<?php

require_once __DIR__ . '/../app/Core/NotificationBootstrap.php';

class Controller
{
    public $lastView = null;

    protected function view($path, $data)
    {
        $this->lastView = ['path' => $path, 'data' => $data];
    }
}

class AdminUser
{
    public static function normalizeFilters($filters)
    {
        return ['q' => (string) ($filters['q'] ?? ''), 'rank_id' => 0, 'status' => ''];
    }

    public static function count($filters) { return 0; }
    public static function page($filters, $page, $perPage) { return []; }
    public static function ranks($active) { return []; }
    public static function assignableRanks() { return []; }
    public static function recentRankHistory($limit) { return []; }
    public static function summary() { return ['total' => 0]; }
}

class CustomerRankRequest
{
    public static function pendingForAdmin() { return []; }
}

class AdminAccess
{
    public static function csrfToken() { return 'test-csrf'; }
}

require_once __DIR__ . '/../app/Controllers/AdminUserController.php';

function userExpect($condition, $message)
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
    session_start();

    register_shutdown_function(function () {
        $flash = AnabelkaFlash::consume();
        echo json_encode([
            'notices' => $flash,
            'invite' => $_SESSION['admin_user_invite_flash'] ?? null
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    });

    $controller = new AdminUserController();
    $method = new ReflectionMethod(AdminUserController::class, 'redirectBack');
    $method->invoke($controller, $argv[4], $argv[5], $argv[6]);
    exit;
}

function userProbe($dir, $key, $message, $returnQuery = '')
{
    $id = 'users-probe-' . bin2hex(random_bytes(8));
    $process = proc_open(
        [PHP_BINARY, '-n', __FILE__, '--redirect-probe',
            $dir, $id, $returnQuery, $key, $message],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    userExpect(is_resource($process), 'Redirect subprocess must start');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    userExpect($status === 0 && $err === '',
        'Redirect subprocess failed: ' . $err);
    $payload = json_decode($out, true);
    userExpect(is_array($payload), 'Redirect must return valid session probe JSON');
    return $payload;
}

$dir = sys_get_temp_dir();
$success = userProbe($dir, 'message', 'Ранг користувача змінено.',
    'q=abc&rank_id=2&page=3');
userExpect(count($success['notices']) === 1
    && $success['notices'][0]['type'] === 'success'
    && $success['notices'][0]['message'] === 'Ранг користувача змінено.',
    'Successful rank update must queue one shared success');

foreach ([
    'Користувач уже має цей ранг.',
    'Акаунт уже деактивований.'
] as $text) {
    $noOp = userProbe($dir, 'message', $text);
    userExpect(count($noOp['notices']) === 1
        && $noOp['notices'][0]['type'] === 'info'
        && $noOp['notices'][0]['message'] === $text,
        'No-op must be informational instead of success');
}

$error = userProbe($dir, 'error', 'Сесію форми застаріло.',
    'q=user&rank_id=4&page=2');
userExpect($error['notices'] === [],
    'Persistent CSRF errors must not become timed notifications');

ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');
session_save_path($dir);
session_id('users-index-' . bin2hex(random_bytes(8)));
userExpect(session_start(), 'Session must start');

$_GET = ['q' => 'search'];
$_SESSION['admin_user_invite_flash'] = [
    'invite_token' => 'private-one-time-link',
    'email' => 'example@example.test',
    'channel' => 'telegram'
];
$controller = new AdminUserController();
$controller->index();

userExpect($controller->lastView['path'] === 'admin/users/index',
    'Must keep original users view');
userExpect(
    $controller->lastView['data']['inviteFlash']['invite_token']
        === 'private-one-time-link',
    'One-time invitation link must stay in the private invite result');
userExpect(!isset($_SESSION['admin_user_invite_flash']),
    'One-time invitation must be consumed by the page only once');
userExpect(AnabelkaFlash::consume() === [],
    'Invitation credentials must never enter the notification queue');

session_write_close();
echo "admin users notification runtime passed\n";
