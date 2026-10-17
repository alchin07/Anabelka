<?php

require_once __DIR__ . '/../app/Core/NotificationBootstrap.php';

// Avoid loading the database, real routes, or the admin session while testing
// the notification boundary. Only the news controller's private flash and
// its read-only index() method are invoked.
class Controller
{
    public $lastView = null;

    protected function view($path, $data)
    {
        $this->lastView = ['path' => $path, 'data' => $data];
    }
}

class SiteNews
{
    public static function adminAll()
    {
        return [];
    }
}

class Language
{
    public static function active()
    {
        return [];
    }
}

class AdminAccess
{
    public static function csrfToken()
    {
        return 'test-csrf';
    }
}

require_once __DIR__ . '/../app/Controllers/AdminNewsController.php';

function newsExpect($truth, $message)
{
    if (!$truth) {
        throw new RuntimeException($message);
    }
}

function newsQueued($expectedType, $expectedMessage)
{
    $entries = AnabelkaFlash::consume();

    newsExpect(count($entries) === 1, 'Exactly one queued notification expected.');
    newsExpect(
        $entries[0]['type'] === $expectedType
            && $entries[0]['message'] === $expectedMessage,
        'Unexpected flash entry.'
    );
    newsExpect(AnabelkaFlash::consume() === [], 'Flash must be consumed only once.');
}

ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');
session_save_path(sys_get_temp_dir());
session_id('news-notify-' . bin2hex(random_bytes(8)));
newsExpect(session_start(), 'Test session must start.');

$controller = new AdminNewsController();
$flash = new ReflectionMethod(AdminNewsController::class, 'flash');

// Each successful mutation is shown only through the shared queue.
$_SESSION = [];
$flash->invoke($controller, 'success', 'Чернетку новини створено.');
newsExpect(!isset($_SESSION['admin_news_flash']), 'A successful news save must not use the legacy flash.');
newsQueued('success', 'Чернетку новини створено.');

// Duplicate success posts must not unexpectedly recreate a green legacy flash.
$_SESSION = [];
$flash->invoke($controller, 'success', 'Новину опубліковано.');
$flash->invoke($controller, 'success', 'Новину опубліковано.');
newsExpect(!isset($_SESSION['admin_news_flash']), 'Duplicate success may not use the legacy flash.');
newsQueued('success', 'Новину опубліковано.');

// Validation/CSRF errors remain persistent on the destination page.
$_SESSION = [];
$flash->invoke($controller, 'error', 'Сесію форми завершено. Оновіть сторінку.');
newsExpect(
    ($_SESSION['admin_news_flash']['type'] ?? '') === 'error',
    'CSRF error must stay in the old persistent session flash.'
);
newsExpect(AnabelkaFlash::consume() === [], 'Persistent errors must not enter the timed toast queue.');
$controller->index();
newsExpect($controller->lastView['path'] === 'admin/news/index', 'Unexpected view.');
newsExpect(
    ($controller->lastView['data']['flash']['message'] ?? '')
        === 'Сесію форми завершено. Оновіть сторінку.',
    'The error must remain available to the page.'
);
newsExpect(!isset($_SESSION['admin_news_flash']), 'Persistent error is consumed once by the news page.');

// A pre-update queued success must be migrated instead of showing old green UI.
$_SESSION = ['admin_news_flash' => [
    'type' => 'success', 'message' => 'Новину та переклади збережено.'
]];
$controller->index();
newsExpect(
    $controller->lastView['data']['flash'] === null,
    'Legacy success must not display in the old banner.'
);
newsExpect(!isset($_SESSION['admin_news_flash']), 'Legacy success must be consumed.');
newsQueued('success', 'Новину та переклади збережено.');

// A new successful action clears any stale old persistent error.
$_SESSION = ['admin_news_flash' => [
    'type' => 'error', 'message' => 'Попередня помилка.'
]];
$flash->invoke($controller, 'success', 'Новину видалено.');
newsExpect(!isset($_SESSION['admin_news_flash']), 'Stale error must not accompany new success.');
newsQueued('success', 'Новину видалено.');

// The regular Bootstrap creates one safe JSON flash in the HTML response.
$_SESSION = [];
$flash->invoke($controller, 'success', 'Збережено <script>alert(1)</script>');
$html = NotificationBootstrap::inject(
    '<!doctype html><html><head><title>Новини</title></head>'
    . '<body><main class="admin-news-page"></main></body></html>'
);
newsExpect(
    preg_match('/<script\s+type="application\/json"\s+id="anabelka-flash">(.*?)<\/script>/s', $html, $match) === 1,
    'The success must appear in the central HTML JSON flash.'
);
$payload = json_decode($match[1], true);
newsExpect(is_array($payload) && count($payload) === 1, 'Exactly one HTML flash expected.');
newsExpect(
    $payload[0]['type'] === 'success'
        && $payload[0]['message'] === 'Збережено <script>alert(1)</script>',
    'HTML bridge must preserve text without interpreting markup.'
);
newsExpect(
    strpos($html, '<script>alert(1)</script>') === false,
    'User text must not be embedded as executable HTML.'
);
newsExpect(AnabelkaFlash::consume() === [], 'HTML flash must be consumed once.');

session_write_close();
echo "admin news notification runtime passed\n";
