<?php
declare(strict_types=1);

function fxm_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $path = __DIR__ . '/config.php';
        if (!is_file($path)) {
            http_response_code(500);
            exit('config.php not found');
        }
        $cfg = require $path;
        date_default_timezone_set($cfg['timezone'] ?? 'Asia/Tokyo');
    }
    return $cfg;
}

function fxm_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = fxm_config();
        $pdo = new PDO($c['db_dsn'], $c['db_user'], $c['db_pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function fxm_now(): string
{
    return date('Y-m-d H:i:s');
}

function fxm_json(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

function fxm_is_duplicate(PDOException $e): bool
{
    return $e->getCode() === '23000'; // MySQL / SQLite 共通の一意制約違反
}

function fxm_notify(string $text): bool
{
    $url = fxm_config()['discord_webhook'] ?? '';
    if ($url === '') {
        return false;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode(['content' => mb_substr($text, 0, 1900)], JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

function fxm_h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
