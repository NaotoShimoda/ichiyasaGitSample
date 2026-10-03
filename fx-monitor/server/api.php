<?php
// EA からの受信口: POST JSON, ヘッダー X-Api-Key
declare(strict_types=1);
require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fxm_json(405, ['ok' => false, 'error' => 'POST only']);
}
$cfg = fxm_config();
if (!hash_equals((string)$cfg['api_key'], (string)($_SERVER['HTTP_X_API_KEY'] ?? ''))) {
    fxm_json(401, ['ok' => false, 'error' => 'unauthorized']);
}

$in = json_decode(rtrim((string)file_get_contents('php://input'), "\0"), true);
if (!is_array($in)) {
    fxm_json(400, ['ok' => false, 'error' => 'invalid json']);
}

function fxm_str(array $in, string $key, int $max, bool $required = false): string
{
    $v = trim((string)($in[$key] ?? ''));
    if ($required && $v === '') {
        fxm_json(400, ['ok' => false, 'error' => "$key required"]);
    }
    return mb_substr($v, 0, $max);
}

// 死活監視の更新（正常に処理できた受信のみ）
function fxm_touch(PDO $db, string $account, string $info): void
{
    $now  = fxm_now();
    $prev = $db->prepare('SELECT alerted, info FROM fxm_heartbeats WHERE account = ?');
    $prev->execute([$account]);
    $row        = $prev->fetch() ?: ['alerted' => 0, 'info' => ''];
    $wasAlerted = (int)$row['alerted'] === 1;

    $up = $db->prepare("UPDATE fxm_heartbeats SET last_seen = ?, alerted = 0, info = CASE WHEN ? = '' THEN info ELSE ? END WHERE account = ?");
    $up->execute([$now, $info, $info, $account]);
    if ($up->rowCount() === 0) {
        try {
            $db->prepare('INSERT INTO fxm_heartbeats (account, info, last_seen, alerted) VALUES (?, ?, ?, 0)')
               ->execute([$account, $info, $now]);
        } catch (PDOException $e) {
            if (!fxm_is_duplicate($e)) throw $e; // 同一秒の同時受信
        }
    }
    if ($wasAlerted) {
        fxm_notify("✅ EA復帰: {$account} " . ($info !== '' ? $info : $row['info']));
    }
}

$db      = fxm_db();
$now     = fxm_now();
$kind    = fxm_str($in, 'kind', 16, true);
$account = fxm_str($in, 'account', 32, true);
$info    = fxm_str($in, 'info', 255);

switch ($kind) {
    case 'heartbeat':
        fxm_touch($db, $account, $info);
        fxm_json(200, ['ok' => true]);

    case 'snapshot':
        $symbol = fxm_str($in, 'symbol', 32, true);
        $tf     = fxm_str($in, 'timeframe', 8, true);
        $trend  = max(-1, min(1, (int)($in['trend'] ?? 0)));
        $data   = isset($in['data']) ? json_encode($in['data'], JSON_UNESCAPED_UNICODE) : null;

        $db->beginTransaction();
        $db->prepare('DELETE FROM fxm_snapshots WHERE account = ? AND symbol = ? AND timeframe = ?')
           ->execute([$account, $symbol, $tf]);
        $db->prepare('INSERT INTO fxm_snapshots (account, symbol, timeframe, trend, price, bar_time, data, updated_at)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
           ->execute([$account, $symbol, $tf, $trend, (float)($in['price'] ?? 0), fxm_str($in, 'bar_time', 20), $data, $now]);
        $db->commit();
        fxm_touch($db, $account, $info);
        fxm_json(200, ['ok' => true]);

    case 'signal':
        $symbol  = fxm_str($in, 'symbol', 32, true);
        $tf      = fxm_str($in, 'timeframe', 8, true);
        $side    = fxm_str($in, 'side', 16, true);
        $price   = (float)($in['price'] ?? 0);
        $barTime = fxm_str($in, 'bar_time', 20);
        $message = fxm_str($in, 'message', 255);
        try {
            $db->prepare('INSERT INTO fxm_signals (account, symbol, timeframe, side, price, bar_time, message, notified, created_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)')
               ->execute([$account, $symbol, $tf, $side, $price, $barTime, $message, $now]);
        } catch (PDOException $e) {
            if (fxm_is_duplicate($e)) {
                fxm_touch($db, $account, $info);
                fxm_json(200, ['ok' => true, 'duplicate' => true]); // 再送・EA再起動時の二重通知防止
            }
            throw $e;
        }
        $id = (int)$db->lastInsertId();
        fxm_touch($db, $account, $info);
        if (($in['notify'] ?? true) !== false) {
            $text = "📈 {$side} {$symbol} {$tf} @ {$price}" . ($message !== '' ? "\n{$message}" : '') . "\n({$barTime})";
            if (fxm_notify($text)) {
                $db->prepare('UPDATE fxm_signals SET notified = 1 WHERE id = ?')->execute([$id]);
            }
        }
        fxm_json(200, ['ok' => true, 'id' => $id]);

    default:
        fxm_json(400, ['ok' => false, 'error' => 'unknown kind']);
}
