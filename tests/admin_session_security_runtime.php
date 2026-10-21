<?php

require_once __DIR__ . '/../app/Core/SessionSecurity.php';
require_once __DIR__ . '/../app/Models/AdminAccess.php';

function verifySession($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAILED: {$message}\n");
        exit(1);
    }
}

ini_set('session.save_path', sys_get_temp_dir());
$_SERVER['HTTPS'] = 'off';
SessionSecurity::start();

verifySession(session_status() === PHP_SESSION_ACTIVE, 'session started');
verifySession(ini_get('session.use_strict_mode') === '1', 'strict mode');
verifySession((int) ini_get('session.gc_maxlifetime') >= 7200, 'GC window');
$cookie = session_get_cookie_params();
verifySession($cookie['httponly'] === true, 'HttpOnly');
verifySession($cookie['samesite'] === 'Lax', 'SameSite');
verifySession($cookie['secure'] === false, 'local HTTP');
verifySession((int) $cookie['lifetime'] === 0, 'browser session cookie');

$now = time();
$valid = function ($started, $last) {
    $_SESSION = [
        'admin_user_id' => 7,
        'admin_user_name' => 'Test',
        'admin_role_slug' => 'administrator',
        'admin_auth_started_at' => $started,
        'admin_auth_last_activity_at' => $last,
        'user_id' => 21,
        'cart' => ['item' => 1]
    ];
};
$preserved = function () {
    verifySession($_SESSION['user_id'] === 21, 'customer intact');
    verifySession($_SESSION['cart']['item'] === 1, 'cart intact');
};

$valid($now - 100, $now - 50);
verifySession(AdminAccess::currentId() === 7, 'valid admin session');
AdminAccess::markActivity();
verifySession($_SESSION['admin_auth_started_at'] === $now - 100, 'absolute age never reset');
verifySession($_SESSION['admin_auth_last_activity_at'] >= $now, 'activity refreshed');

$valid($now - 3600, $now - 3600);
verifySession(AdminAccess::currentId() === 0, 'idle timeout at 60 minutes');
$preserved();

$valid($now - 43200, $now - 10);
verifySession(AdminAccess::currentId() === 0, 'absolute timeout at 12 hours');
$preserved();

$valid($now - 43201, $now - 10);
AdminAccess::markActivity();
verifySession(!isset($_SESSION['admin_auth_last_activity_at']), 'cannot revive expired admin');
$preserved();

$valid($now - 10, $now + 10);
verifySession(AdminAccess::currentId() === 0, 'future timestamp rejected');
$preserved();

$valid($now - 10, $now - 5);
unset($_SESSION['admin_auth_started_at']);
verifySession(AdminAccess::currentId() === 0, 'legacy session forces re-login');
$preserved();

session_destroy();
echo "admin session security runtime passed\n";
