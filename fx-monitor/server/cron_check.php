<?php
// EA 停止検知: ラッコサーバーの cron で5分毎に実行
//   */5 * * * * /usr/bin/php /home/ユーザー名/.../fxmon/cron_check.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/lib.php';

$cfg   = fxm_config();
$db    = fxm_db();
$limit = date('Y-m-d H:i:s', time() - 60 * (int)$cfg['heartbeat_timeout_min']);

$st = $db->prepare('SELECT account, info, last_seen FROM fxm_heartbeats WHERE alerted = 0 AND last_seen < ?');
$st->execute([$limit]);
foreach ($st->fetchAll() as $r) {
    if (fxm_notify("⚠️ EA停止の疑い: {$r['account']} {$r['info']}\n最終受信 {$r['last_seen']}")) {
        $db->prepare('UPDATE fxm_heartbeats SET alerted = 1 WHERE account = ?')->execute([$r['account']]);
    }
}
