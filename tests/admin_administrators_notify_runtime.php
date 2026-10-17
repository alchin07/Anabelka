<?php

require_once __DIR__ . '/../app/Core/NotificationBootstrap.php';

// Intentionally no database or authenticated admin calls. This tests only
// notifications and the existing session-only invitation boundary.
class Controller
{
    public $lastView = null;

    protected function view($path, $data)
    {
        $this->lastView = ['path' => $path, 'data' => $data];
    }
}

class AdminManagement
{
    public static function administrators() { return []; }
    public static function roles() { return []; }
    public static function assignableRoles() { return []; }
    public static function permissions() { return []; }
}

class AdminAccess
{
    public static function csrfToken() { return 'csrf-placeholder'; }
}

require_once __DIR__ . '/../app/Controllers/AdminAdministratorController.php';

function adminsExpect($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

if (($argv[1] ?? '') === '--redirect-probe') {
    ini_set('session.use_cookies', '0');
    ini_set('session.cache_limiter', '');
    session_save_path($argv[2]);
    session_id($argv[3]);
    adminsExpect(session_start(), 'Subprocess session failed to start');

    // This is only a synthetic fixture, never a real invitation token.
    $_SESSION['admin_administrator_invite_flash'] = [
        'invite_token' => 'synthetic-testing-only-not-a-credential',
        'email' => 'example@example.test'
    ];

    register_shutdown_function(function () {
        $notices = AnabelkaFlash::consume();
        echo json_encode([
            'notices' => $notices,
            'invite' => $_SESSION['admin_administrator_invite_flash'] ?? null
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    });

    $controller = new AdminAdministratorController();
    $redirect = new ReflectionMethod(AdminAdministratorController::class, 'redirect');
    $redirect->invoke($controller, $argv[4], $argv[5]);
    exit;
}

function adminsRedirectProbe($type, $message)
{
    $dir = sys_get_temp_dir();
    $id = 'admin-notify-' . bin2hex(random_bytes(8));
    $cmd = [PHP_BINARY, '-n', __FILE__, '--redirect-probe',
        $dir, $id, $type, $message];
    $process = proc_open(
        $cmd,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    adminsExpect(is_resource($process), 'Redirect subprocess must start');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    adminsExpect($status === 0 && $err === '',
        'Redirect subprocess failed: ' . $err);
    $data = json_decode($out, true);
    adminsExpect(is_array($data), 'Redirect probe must produce JSON');
    return $data;
}

// POST -> GET uses the shared queue; its contents never include credentials.
$created = adminsRedirectProbe('message', 'Запрошення адміністратора створено.');
adminsExpect(count($created['notices']) === 1
    && $created['notices'][0]['type'] === 'success'
    && $created['notices'][0]['message'] === 'Запрошення адміністратора створено.',
    'Invitation success must be one shared notification');
adminsExpect(
    $created['invite']['invite_token'] === 'synthetic-testing-only-not-a-credential',
    'Sensitive one-time invitation must stay in its separate session result');
adminsExpect(
    strpos(json_encode($created['notices']), 'synthetic-testing-only-not-a-credential') === false,
    'Invite token must never be in a public notification');

// A rank that was already assigned is an informational no-op.
$noOp = adminsRedirectProbe('message', 'Адміністратор уже має цю роль.');
adminsExpect(count($noOp['notices']) === 1
    && $noOp['notices'][0]['type'] === 'info',
    'Unchanged rank must not emit a success claim');

// Reissue and revocation are still successful operations.
$reissue = adminsRedirectProbe('message',
    'Нове одноразове запрошення створено. Попереднє посилання більше не працює.');
adminsExpect(count($reissue['notices']) === 1
    && $reissue['notices'][0]['type'] === 'success',
    'Reissued invitation must use shared success');

$revoke = adminsRedirectProbe('message', 'Запрошення адміністратора відкликано.');
adminsExpect(count($revoke['notices']) === 1
    && $revoke['notices'][0]['type'] === 'success',
    'Revoked invitation must use shared success');

// Role errors and session/CSRF problems remain persistent page messages.
$error = adminsRedirectProbe('error', 'Сесію форми застаріло. Оновіть сторінку.');
adminsExpect($error['notices'] === [],
    'CSRF errors must not enter the expiring toast queue');

// Rendering of the invitation result is unchanged.
ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');
session_save_path(sys_get_temp_dir());
session_id('admin-index-' . bin2hex(random_bytes(8)));
adminsExpect(session_start(), 'Index test session failed to start');
$_SESSION['admin_administrator_invite_flash'] = [
    'invite_token' => 'index-synthetic-test-token',
    'email' => 'example@example.test',
    'channel' => 'telegram'
];
$_GET = ['error' => 'Недостатньо прав для цієї дії.'];
$controller = new AdminAdministratorController();
$controller->index();

adminsExpect($controller->lastView['path'] === 'admin/administrators/index',
    'Must retain the original administrators screen');
adminsExpect($controller->lastView['data']['inviteFlash']['invite_token']
    === 'index-synthetic-test-token',
    'Invite must be available to the page once');
adminsExpect(!isset($_SESSION['admin_administrator_invite_flash']),
    'Invitation cannot remain after being read');
adminsExpect($controller->lastView['data']['error']
    === 'Недостатньо прав для цієї дії.',
    'Permission error must remain on the page');
adminsExpect(AnabelkaFlash::consume() === [],
    'Invite content and persistent errors must not become timed notifications');

session_write_close();
echo "admin administrator notifications runtime passed\n";
