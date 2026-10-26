<?php
// Real PDO/session policy tests; no shop DB or external services are used.
require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Models/AppSetting.php';
require_once __DIR__ . '/../app/Models/AdminAccess.php';

$service = __DIR__ . '/../app/Services/ErrorDisplayMode.php';
if (!is_file($service)) {
    fwrite(STDERR, "FAIL: ErrorDisplayMode service is missing\n");
    exit(1);
}
require_once $service;
$checks = 0;
function check($condition, $label) {
    global $checks;
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $label . "\n");
        exit(1);
    }
    $checks++;
}
function useConnection(PDO $db) {
    $property = new ReflectionProperty(Database::class, 'connection');
    $property->setValue(null, $db);
}
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
useConnection($db);
check(ErrorDisplayMode::current() === 'production', 'missing table defaults to production');
check(!$db->query("SELECT name FROM sqlite_master WHERE name='app_settings'")->fetchColumn(), 'read does not create tables');
$db->exec('CREATE TABLE app_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
$db->exec('CREATE TABLE admin_roles (id INTEGER PRIMARY KEY, slug TEXT, name TEXT)');
$db->exec('CREATE TABLE admin_users (id INTEGER PRIMARY KEY, role_id INTEGER, name TEXT, email TEXT, is_active INTEGER)');
$db->exec("INSERT INTO admin_roles VALUES (1,'owner','Розробник'), (2,'store_owner','Власник'), (3,'administrator','Адміністратор')");
$db->exec("INSERT INTO admin_users VALUES (1,1,'Developer','dev@example.invalid',1), (2,2,'Owner','owner@example.invalid',1), (3,3,'Admin','admin@example.invalid',1), (4,1,'Disabled','disabled@example.invalid',0)");
check(ErrorDisplayMode::current() === 'production', 'missing setting defaults to production');
$db->exec("INSERT INTO app_settings VALUES ('error_display_mode','garbage')");
check(ErrorDisplayMode::current() === 'production', 'invalid setting defaults to production');
$db->exec("UPDATE app_settings SET setting_value='development'");
check(ErrorDisplayMode::current() === 'development', 'valid development setting');
$_SESSION = ['admin_user_id' => 1, 'admin_role_slug' => 'owner'];
check(ErrorDisplayMode::diagnostics(500) === [], 'session array without active session is denied');
$sessions = sys_get_temp_dir() . '/anabelka-err01-policy-' . bin2hex(random_bytes(5));
mkdir($sessions, 0700);
session_save_path($sessions);
session_start();
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/Anabelka/catalog/secret-token-1234567890?password=TOPSECRET&email=buyer@example.invalid';
$_SESSION = [];
check(ErrorDisplayMode::diagnostics(500) === [], 'visitor is denied in development');
foreach ([2, 3, 4, 999] as $id) {
    $_SESSION = ['admin_user_id' => $id, 'admin_role_slug' => 'owner'];
    check(ErrorDisplayMode::diagnostics(403) === [], 'database role/activity verified for ' . $id);
}
$_SESSION = ['admin_user_id' => 1, 'admin_role_slug' => 'administrator'];
$exception = new PDOException('SELECT * FROM orders; password=TOPSECRET token=TOKEN123 customer=Buyer');
$ref = 'ERR-20261026-120000-ABC123';
$diagnostic = ErrorDisplayMode::diagnostics(500, ['exception' => $exception, 'reference' => $ref, 'raw' => ['password' => 'TOPSECRET']]);
check(($diagnostic['status'] ?? 0) === 500, 'developer sees HTTP status');
check(($diagnostic['exception_class'] ?? '') === 'PDOException', 'developer sees real exception class');
check(($diagnostic['reference'] ?? '') === $ref, 'developer sees valid correlation reference');
check(strpos($diagnostic['log_url'] ?? '', 'ref=' . $ref) !== false, 'reference links to protected journal');
check(($diagnostic['file'] ?? '') === 'tests/error_display_mode_runtime.php', 'only project-relative file');
check(($diagnostic['line'] ?? 0) > 0, 'known source line included');
check(($diagnostic['route'] ?? '') === '/Anabelka/catalog/[приховано]', 'untrusted route values and query removed');
$encoded = json_encode($diagnostic, JSON_UNESCAPED_UNICODE);
foreach (['SELECT', 'orders', 'TOPSECRET', 'TOKEN123', 'Buyer', 'buyer@example', 'secret-token', dirname(__DIR__), 'trace'] as $secret) {
    check(strpos($encoded, $secret) === false, 'diagnostics exclude ' . $secret);
}
$forbidden = ErrorDisplayMode::diagnostics(403, ['permission' => 'products.manage']);
check(($forbidden['permission'] ?? '') === 'products.manage', 'permission included for access denial');
check(($forbidden['role'] ?? '') === 'owner', 'role comes from verified database row');
$invalid = ErrorDisplayMode::diagnostics(500, ['file' => '/outside/private.php', 'line' => 9, 'reference' => '"><script>bad</script>']);
check(empty($invalid['file']) && empty($invalid['reference']), 'external paths and forged references rejected');
$anonymous = ErrorDisplayMode::diagnostics(500, ['exception' => new class('ERR01_SECRET') extends RuntimeException {}]);
check(($anonymous['exception_class'] ?? '') === 'RuntimeException', 'anonymous exception uses named ancestor');
check(strpos(json_encode($anonymous), '\\u0000') === false, 'anonymous class cannot expose its embedded source path');
$db->exec("UPDATE admin_users SET role_id=2 WHERE id=1");
check(ErrorDisplayMode::diagnostics(500) === [], 'role revocation takes effect without cached authorization');
$db->exec("UPDATE admin_users SET role_id=1 WHERE id=1");
$db->exec("UPDATE app_settings SET setting_value='production'");
check(ErrorDisplayMode::diagnostics(500) === [], 'production hides diagnostics from developer too');
$db->exec('ALTER TABLE admin_users RENAME TO admin_users_err01_saved');
check(ErrorDisplayMode::diagnostics(500) === [], 'missing auth table fails closed');
check(!$db->query("SELECT name FROM sqlite_master WHERE name='admin_users'")->fetchColumn(), 'authorization does not create tables');
session_write_close();
foreach (glob($sessions . '/*') as $file) { unlink($file); }
rmdir($sessions);
echo "ERR-01 policy: {$checks} checks passed\n";
