<?php

// This router is exclusively for the explicit, isolated integration harness.
$dsn = getenv('VC_TEST_DSN');
$databaseParts = is_string($dsn) ? preg_match_all('/(?:^|;)dbname=([^;]*)(?=;|$)/i', substr($dsn, 6), $databaseMatches) : 0;
$expectedDatabase = $databaseParts === 1 ? $databaseMatches[1][0] : '';
if (getenv('VC_TEST_HTTP') !== '1' || !is_string($dsn)
    || !preg_match('/^mysql:/', $dsn)
    || !preg_match('/^[A-Za-z0-9_]+$/D', $expectedDatabase)
    || !str_ends_with($expectedDatabase, '_vc_test')) {
    http_response_code(503);
    exit('Isolated fixture disabled.');
}

require __DIR__ . '/../app/Services/VisitorPageViewCounter.php';
// All connection paths, including the real admin permission gate, use only the
// already guarded fixture DSN. No default application database config is loaded.
class Database
{
    public static function connect(): PDO
    {
        global $expectedDatabase;
        static $pdo = null;
        if ($pdo === null) {
            $pdo = new PDO(getenv('VC_TEST_DSN'), getenv('VC_TEST_USER') ?: 'root', getenv('VC_TEST_PASSWORD') === false ? '' : getenv('VC_TEST_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
                throw new RuntimeException('Unsafe test database.');
            }
        }
        return $pdo;
    }
}
require __DIR__ . '/../app/Models/AdminAccess.php';
if (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/Anabelka/admin/visitor-stats') {
    // Exercise the production controller and real DB-backed AdminAccess gate;
    // replace only the layout so unrelated application views are unnecessary.
    class Controller
    {
        protected function view($view, $data = [])
        {
            header('Content-Type: application/json');
            echo json_encode(['authorized' => true, 'report_available' => is_array($data['report'] ?? null), 'unavailable' => $data['reportUnavailable'] ?? true]);
        }
    }
    require __DIR__ . '/../app/Models/VisitorStats.php';
    require __DIR__ . '/../app/Controllers/AdminVisitorStatsController.php';
    $role = $_GET['fixture_role'] ?? '';
    $_SESSION = [];
    if (is_string($role) && in_array($role, ['owner', 'store_owner', 'administrator', 'order_manager', 'content_manager'], true)) {
        $user = Database::connect()->prepare('SELECT u.id FROM admin_users u INNER JOIN admin_roles r ON r.id=u.role_id WHERE r.slug=:role AND u.name=:name LIMIT 1');
        $user->execute(['role' => $role, 'name' => 'VC test ' . $role]);
        $_SESSION['admin_user_id'] = (int) $user->fetchColumn();
    }
    (new AdminVisitorStatsController())->index();
    return;
}
$config = ['enabled' => true, 'allowed_hosts' => ['shop.anabelka.com.ua'], 'base_path' => '/Anabelka', 'timezone' => 'Europe/Kyiv', 'retention_days' => 400];
$scenario = is_string($_GET['fixture'] ?? null) ? $_GET['fixture'] : '';
$status = $scenario === 'missing' ? 404 : ($scenario === 'redirect' ? 302 : 200);
$headers = $scenario === 'json' ? ['Content-Type: application/json'] : ['Content-Type: text/html; charset=utf-8'];
http_response_code($status);
foreach ($headers as $header) {
    header($header);
}
if ($status === 302) {
    header('Location: /Anabelka/');
}
// The built-in server's loopback address is replaced only in this test fixture.
$server = $_SERVER;
$server['REMOTE_ADDR'] = '8.8.8.8';
$connection = static function () use ($dsn, $scenario) {
    if ($scenario === 'fail-open') {
        throw new RuntimeException('Intentional fixture database failure.');
    }
    return Database::connect();
};
$counter = new VisitorPageViewCounter($config, $connection, static fn () => new DateTimeImmutable('2026-10-23T12:00:00+00:00'));
// Inject the request object only; run the production public facade unchanged.
$facade = new ReflectionProperty(VisitorPageViewCounter::class, 'requestCounter');
$facade->setValue(null, $counter);
$_SERVER = $server;
$_SESSION = $scenario === 'admin-session' ? ['admin_user_id' => 1] : [];
VisitorPageViewCounter::recordRendered('home');
echo $scenario === 'json' ? '{"fixture":true}' : '<!doctype html><title>VC fixture</title><p>Rendered fixture</p>';
