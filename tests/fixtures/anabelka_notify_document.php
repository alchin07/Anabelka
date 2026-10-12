<?php
require_once dirname(__DIR__, 2) . '/app/Core/NotificationBootstrap.php';
$sessionDirectory = sys_get_temp_dir() . '/anabelka-notify-browser-' . bin2hex(random_bytes(8));
mkdir($sessionDirectory, 0700);
ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');
session_save_path($sessionDirectory);
session_id('notify-browser-fixture');
session_start();
$_SESSION = [];
foreach ($fixtureMessages ?? [] as $message) {
    AnabelkaFlash::store($message['type'], $message['message'], $message['options'] ?? []);
}
$fixtureHtml = '<!doctype html><html lang="uk"><head><meta charset="utf-8">'
    . '<meta name="viewport" content="width=device-width, initial-scale=1">'
    . ($fixtureHead ?? '') . '</head><body>' . ($fixtureBody ?? '')
    . '<button id="after">Наступне</button></body></html>';
$documents = [
    NotificationBootstrap::inject($fixtureHtml),
    NotificationBootstrap::inject($fixtureHtml),
];
session_write_close();
foreach (glob($sessionDirectory . '/*') as $sessionFile) {
    unlink($sessionFile);
}
rmdir($sessionDirectory);
echo json_encode($documents, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
