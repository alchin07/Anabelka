<?php
// Development-server-only harness, never included by the shop's entry points.
$browserPreview = getenv('ERR01_BROWSER_PREVIEW') === '1';
if (PHP_SAPI !== 'cli-server' || (!$browserPreview && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true))) {
    http_response_code(404);
    exit;
}
$root = getenv('ERR01_SITE_ROOT');
$config = require $root . '/config/database.php';
if (!preg_match('/^anabelka_err01_test(?:_[a-z0-9]+)?$/D', $config['database'] ?? '')) {
    http_response_code(503);
    exit;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($browserPreview && ($path === '/' || $path === '/Anabelka/err01-preview')) {
    echo '<!doctype html><meta charset="UTF-8"><h1>ERR-01 isolated browser tests</h1>';
    foreach ([320, 360, 390, 430, 1280] as $width) {
        echo '<p><a href="/Anabelka/err01-layout/' . $width . '">Settings ' . $width . ' px</a> · '
            . '<a href="/Anabelka/err01-layout/' . $width . '?sample=diagnostic">Diagnostic ' . $width . ' px</a></p>';
    }
    echo '<p><a href="/Anabelka/admin/system/error-display">Full settings page</a></p>';
    return;
}
if ($browserPreview && preg_match('#^/Anabelka/err01-layout/(320|360|390|430|1280)$#D', $path, $m)) {
    $sample = ($_GET['sample'] ?? '') === 'diagnostic' ? '/explode' : '/admin/system/error-display';
    echo '<!doctype html><meta charset="UTF-8"><title>ERR-01 viewport ' . $m[1] . '</title>'
        . '<style>body{margin:0;background:#ddd}iframe{display:block;border:0;margin:0 auto;background:white}</style>'
        . '<iframe title="ERR-01 test viewport" src="/Anabelka' . $sample . '" width="' . $m[1] . '" height="2200"></iframe>';
    return;
}
if (preg_match('#^/Anabelka/(css|js)/[a-zA-Z0-9_.-]+$#D', $path)) {
    return false;
}
require_once $root . '/app/Core/ErrorHandler.php';
ErrorHandler::register($root);
if ($path === '/Anabelka/early-error') {
    throw new RuntimeException('ERR01_SECRET before session startup');
}
require_once $root . '/app/Core/NotificationBootstrap.php';
NotificationBootstrap::register();
require_once $root . '/app/Core/PublicErrorPage.php';
PublicErrorPage::register($root);
session_name('ANABELKA_ERR01_TEST');
session_start();
require_once $root . '/app/Core/Database.php';
require_once $root . '/app/Core/Controller.php';
require_once $root . '/app/Core/Router.php';
require_once $root . '/app/Core/Csrf.php';
require_once $root . '/app/Models/AppSetting.php';
require_once $root . '/app/Models/AdminAccess.php';
require_once $root . '/app/Models/AdminDashboardServiceRegistry.php';
// Observe real queries for one successful public route, without changing any
// MariaDB GLOBAL logging settings or inspecting queries from other databases.
class Err01ObservedPDO extends PDO
{
    public $settingReads = 0;
    public function prepare(string $query, array $options = []): PDOStatement|false {
        if (stripos($query, 'app_settings') !== false) { $this->settingReads++; }
        return parent::prepare($query, $options);
    }
}
if ($path === '/Anabelka/ok') {
    $observed = new Err01ObservedPDO('mysql:host=' . $config['host'] . ';port=' . $config['port'] . ';dbname=' . $config['database'] . ';charset=utf8mb4', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    (new ReflectionProperty(Database::class, 'connection'))->setValue(null, $observed);
}
// Only the explicitly enabled, disposable browser fixture signs in its fake
// owner automatically. This file cannot run through Apache/FPM or shop routes.
if ($browserPreview && empty($_SESSION['admin_user_id'])) {
    AdminAccess::authenticate('owner@err01.invalid', 'err01-test-password');
}
class PublicInterfaceTranslator { public static function seed() {} }
class Translator { public static function t($key, $fallback) { return $fallback; } }
class Err01FixtureController extends Controller
{
    public function loginForm() {
        echo '<form method="post"><input name="_csrf" value="' . htmlspecialchars(AdminAccess::csrfToken(), ENT_QUOTES) . '"></form>';
    }
    public function login() {
        if (!AdminAccess::authenticate($_POST['email'] ?? '', $_POST['password'] ?? '')) {
            http_response_code(403);
            return;
        }
        header('Location: /Anabelka/admin/system/error-display', true, 303);
    }
    public function ok() {
        global $observed;
        if ($observed instanceof Err01ObservedPDO) {
            header('X-ERR01-Setting-Reads: ' . $observed->settingReads);
        }
        echo 'OK';
    }
    public function explode() {
        echo 'PARTIAL_SECRET_OUTPUT';
        throw new PDOException('SELECT private_orders; password=ERR01_SECRET token=ERR01_TOKEN customer=BUYER_PRIVATE');
    }
    public function explicitJson() {
        header('Content-Type: application/json; charset=UTF-8');
        $this->explode();
    }
    public function genericJson($status) {
        header('Content-Type: application/json; charset=UTF-8');
        PublicErrorPage::renderGeneric((int) $status);
    }
    public function caughtHtml() {
        http_response_code(500);
        echo '<html><body><p>SQL SELECT private_orders; ERR01_SECRET BUYER_PRIVATE</p></body></html>';
    }
    public function caughtJson() {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'message' => 'SQL SELECT private_orders; ERR01_SECRET', 'retry_after' => 5, 'labels' => ['retry' => 'Спробувати знову']]);
    }
    public function noSession() { session_write_close(); $this->explode(); }
    public function dbDown() {
        global $root;
        $config = require $root . '/config/database.php';
        $config['port'] = '1';
        file_put_contents($root . '/config/database.php', '<?php return ' . var_export($config, true) . ';');
        $this->explode();
    }
    public function missingConfig() {
        global $root;
        rename($root . '/config/database.php', $root . '/config/database.err01-disabled');
        $this->explode();
    }
    public function badView() {
        global $root;
        file_put_contents($root . '/views/errors/public.php', '<?php echo "PARTIAL_SECRET_OUTPUT"; throw new RuntimeException("ERR01_SECRET");');
        $this->explode();
    }
    public function badLog() {
        global $root;
        rename($root . '/storage/logs', $root . '/storage/logs.err01-disabled');
        file_put_contents($root . '/storage/logs', 'test-only obstruction');
        $this->explode();
    }
    public function fatal() { echo 'PARTIAL_SECRET_OUTPUT'; trigger_error('ERR01_SECRET', E_USER_ERROR); }
    public function journal() {
        $reference = is_string($_GET['ref'] ?? null) ? $_GET['ref'] : '';
        header('Content-Type: application/json');
        echo json_encode(['found' => is_array(SystemErrorLog::findByReference($reference))]);
    }
}
$router = new Router();
require $root . '/routes/AdminSecurity.php';
$router->get('/admin/login', 'Err01FixtureController@loginForm');
$router->post('/admin/login', 'Err01FixtureController@login');
$router->get('/ok', 'Err01FixtureController@ok');
$router->get('/private', 'Err01FixtureController@ok');
$router->get('/explode', 'Err01FixtureController@explode');
$router->get('/api/explode', 'Err01FixtureController@explode');
$router->get('/explicit-json', 'Err01FixtureController@explicitJson');
$router->get('/json-generic/{status}', 'Err01FixtureController@genericJson');
$router->get('/caught-html', 'Err01FixtureController@caughtHtml');
$router->get('/caught-json', 'Err01FixtureController@caughtJson');
$router->get('/no-session', 'Err01FixtureController@noSession');
$router->get('/db-down', 'Err01FixtureController@dbDown');
$router->get('/missing-config', 'Err01FixtureController@missingConfig');
$router->get('/bad-view', 'Err01FixtureController@badView');
$router->get('/bad-log', 'Err01FixtureController@badLog');
$router->get('/fatal', 'Err01FixtureController@fatal');
$router->get('/admin/system/errors', 'Err01FixtureController@journal');
$router->dispatch($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
