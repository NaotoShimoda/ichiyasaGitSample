<?php
// 環境認識ダッシュボード
declare(strict_types=1);
require __DIR__ . '/lib.php';

$cfg = fxm_config();
session_start();

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: ./');
    exit;
}
if (isset($_POST['password'])) {
    if (hash_equals((string)$cfg['dashboard_password'], (string)$_POST['password'])) {
        session_regenerate_id(true);
        $_SESSION['fxm_auth'] = true;
        header('Location: ./');
        exit;
    }
    $loginError = true;
}

if (empty($_SESSION['fxm_auth'])) { ?>
<!doctype html><html lang="ja"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>FX Monitor</title>
<style>body{font-family:sans-serif;background:#111;color:#eee;display:flex;justify-content:center;padding-top:20vh}
input,button{font-size:16px;padding:8px}</style>
<form method="post"><p>FX Monitor</p>
<?php if (!empty($loginError)) echo '<p style="color:#f66">パスワードが違います</p>'; ?>
<input type="password" name="password" autofocus> <button>ログイン</button></form></html>
<?php exit; }

$db = fxm_db();
$tfOrder = ['M1', 'M5', 'M15', 'M30', 'H1', 'H4', 'D1', 'W1', 'MN1'];

$snaps = $db->query('SELECT * FROM fxm_snapshots ORDER BY account, symbol')->fetchAll();
$grid = []; $tfs = [];
foreach ($snaps as $s) {
    $grid[$s['account']][$s['symbol']][$s['timeframe']] = $s;
    $tfs[$s['timeframe']] = true;
}
$tfs = array_keys($tfs);
usort($tfs, function ($a, $b) use ($tfOrder) {
    $ia = array_search($a, $tfOrder, true); $ib = array_search($b, $tfOrder, true);
    return ($ia === false ? 99 : $ia) <=> ($ib === false ? 99 : $ib);
});

$signals = $db->query('SELECT * FROM fxm_signals ORDER BY id DESC LIMIT 50')->fetchAll();
$beats   = $db->query('SELECT * FROM fxm_heartbeats ORDER BY account')->fetchAll();
$timeout = 60 * (int)$cfg['heartbeat_timeout_min'];
?>
<!doctype html><html lang="ja"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="refresh" content="60">
<title>FX Monitor</title>
<style>
body{font-family:sans-serif;background:#111;color:#ddd;margin:16px}
table{border-collapse:collapse;margin-bottom:24px}
th,td{border:1px solid #333;padding:6px 10px;text-align:center;white-space:nowrap}
th{background:#222}
.up{background:#0b4d2a;color:#7f7}.dn{background:#5a1414;color:#f88}.fl{background:#333;color:#aaa}
.ok{color:#7f7}.ng{color:#f66}small{color:#888}a{color:#8af}
.wrap{overflow-x:auto}
</style>
<p><a href="?logout=1">ログアウト</a> <small>60秒毎に自動更新 / <?= fxm_h(fxm_now()) ?></small></p>

<h3>EA稼働状況</h3>
<div class="wrap"><table><tr><th>口座</th><th>情報</th><th>最終受信</th><th>状態</th></tr>
<?php foreach ($beats as $b): $alive = time() - strtotime($b['last_seen']) < $timeout; ?>
<tr><td><?= fxm_h($b['account']) ?></td><td><?= fxm_h($b['info']) ?></td><td><?= fxm_h($b['last_seen']) ?></td>
<td class="<?= $alive ? 'ok' : 'ng' ?>"><?= $alive ? '稼働中' : '停止?' ?></td></tr>
<?php endforeach; ?></table></div>

<h3>環境認識</h3>
<?php foreach ($grid as $account => $symbols): ?>
<small>口座 <?= fxm_h($account) ?></small>
<div class="wrap"><table><tr><th>通貨ペア</th><?php foreach ($tfs as $tf) echo '<th>' . fxm_h($tf) . '</th>'; ?></tr>
<?php foreach ($symbols as $symbol => $row): ?>
<tr><th><?= fxm_h($symbol) ?></th>
<?php foreach ($tfs as $tf): $c = $row[$tf] ?? null;
    if (!$c) { echo '<td>-</td>'; continue; }
    $cls = $c['trend'] > 0 ? 'up' : ($c['trend'] < 0 ? 'dn' : 'fl');
    $arw = $c['trend'] > 0 ? '▲' : ($c['trend'] < 0 ? '▼' : '◆'); ?>
<td class="<?= $cls ?>" title="<?= fxm_h($c['data'] ?? '') ?>"><?= $arw ?> <?= fxm_h($c['price']) ?><br><small><?= fxm_h($c['bar_time']) ?></small></td>
<?php endforeach; ?></tr>
<?php endforeach; ?></table></div>
<?php endforeach; ?>

<h3>シグナル履歴（最新50件）</h3>
<div class="wrap"><table><tr><th>受信</th><th>口座</th><th>通貨ペア</th><th>足</th><th>売買</th><th>価格</th><th>足時刻</th><th>メッセージ</th><th>通知</th></tr>
<?php foreach ($signals as $s): ?>
<tr><td><?= fxm_h($s['created_at']) ?></td><td><?= fxm_h($s['account']) ?></td><td><?= fxm_h($s['symbol']) ?></td>
<td><?= fxm_h($s['timeframe']) ?></td><td class="<?= stripos($s['side'], 'BUY') !== false ? 'up' : 'dn' ?>"><?= fxm_h($s['side']) ?></td>
<td><?= fxm_h($s['price']) ?></td><td><?= fxm_h($s['bar_time']) ?></td><td><?= fxm_h($s['message']) ?></td>
<td><?= $s['notified'] ? '✓' : '' ?></td></tr>
<?php endforeach; ?></table></div>
</html>
