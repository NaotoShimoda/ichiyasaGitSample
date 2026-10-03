<?php
// config.sample.php を config.php にコピーして値を設定する
return [
    // ラッコサーバーのコントロールパネルで作成したDB情報
    'db_dsn'  => 'mysql:host=localhost;dbname=YOUR_DB;charset=utf8mb4',
    'db_user' => 'YOUR_DB_USER',
    'db_pass' => 'YOUR_DB_PASS',

    // EA から送る X-Api-Key（ランダムな長い文字列にする）
    'api_key' => 'CHANGE_ME_LONG_RANDOM_STRING',

    // ダッシュボードのログインパスワード
    'dashboard_password' => 'CHANGE_ME',

    // Discord Webhook URL（空なら通知しない）
    'discord_webhook' => '',

    // EA 停止とみなす無通信時間（分）
    'heartbeat_timeout_min' => 15,

    'timezone' => 'Asia/Tokyo',
];
