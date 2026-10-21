<?php

require_once __DIR__ . '/../app/Core/NotificationBootstrap.php';

class Controller
{
    public $lastView = null;

    protected function view($name, $data)
    {
        $this->lastView = ['name' => $name, 'data' => $data];
    }
}

class Language
{
    public static function all()
    {
        return [['code' => 'uk', 'name' => 'Українська']];
    }

    public static function availableCatalog()
    {
        return ['en' => ['name' => 'English', 'short_name' => 'EN']];
    }
}

require_once __DIR__ . '/../app/Controllers/AdminLanguageController.php';

function languagesExpect($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectFlash($type, $message)
{
    $notices = AnabelkaFlash::consume();
    languagesExpect(count($notices) === 1, 'Exactly one notice expected');
    languagesExpect(
        $notices[0]['type'] === $type
        && $notices[0]['message'] === $message,
        'Wrong notification severity or message'
    );
    languagesExpect(AnabelkaFlash::consume() === [], 'Notice must appear only once');
}

ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');
session_save_path(sys_get_temp_dir());
session_id('language-notices-' . bin2hex(random_bytes(8)));
languagesExpect(session_start(), 'Session failed to start');

$controller = new AdminLanguageController();
$success = new ReflectionMethod(AdminLanguageController::class, 'flashSuccess');

// The same helper is used by all language mutations, and clears stale errors.
foreach ([
    'Мову додано.',
    'Назву мови збережено.',
    'Мову увімкнено.',
    'Мову вимкнено.',
    'Основну мову сайту змінено.',
    'Мову та її переклади видалено.'
] as $message) {
    $_SESSION = [
        'language_message' => 'Старе повідомлення.',
        'language_error' => 'Попередня помилка.'
    ];
    $success->invoke($controller, $message);
    languagesExpect(
        !isset($_SESSION['language_message'], $_SESSION['language_error'])
        && !isset($_SESSION['language_message'])
        && !isset($_SESSION['language_error']),
        'Success cannot leave legacy session messages behind'
    );
    expectFlash('success', $message);
}

// Unread old success is migrated to the shared one-time queue during the GET.
$_SESSION = ['language_message' => 'Попередня мова додана.'];
$controller->index();
languagesExpect($controller->lastView['name'] === 'admin/languages/index',
    'Language page must remain the original view');
languagesExpect($controller->lastView['data']['languages'][0]['code'] === 'uk',
    'The language list must be kept');
languagesExpect($controller->lastView['data']['error'] === '',
    'No error expected after a successful action');
languagesExpect(!isset($_SESSION['language_message']), 'Legacy success must be consumed');
expectFlash('success', 'Попередня мова додана.');

// CSRF/validation/server failures stay visible on the page rather than timed.
$_SESSION = ['language_error' => 'Не вдалося зберегти мову в базі даних.'];
$controller->index();
languagesExpect(
    $controller->lastView['data']['error']
        === 'Не вдалося зберегти мову в базі даних.',
    'Error must remain available in the view'
);
languagesExpect(!isset($_SESSION['language_error']),
    'Persistent error is consumed once after its page is prepared');
languagesExpect(AnabelkaFlash::consume() === [],
    'Persistent errors must not enter an ephemeral notification queue');

// Unsafe HTML in a success is only embedded in nonexecuting encoded JSON.
$_SESSION = [];
$success->invoke($controller, 'Збережено <script>alert(1)</script>');
$page = NotificationBootstrap::inject(
    '<!doctype html><html><head><title>Мови</title></head>'
    . '<body><main class="languages-admin"></main></body></html>'
);
languagesExpect(
    preg_match('/<script type="application\/json" id="anabelka-flash">(.*?)<\/script>/s', $page, $found) === 1,
    'JSON flash must exist'
);
$decoded = json_decode($found[1], true);
languagesExpect(
    count($decoded) === 1 && $decoded[0]['type'] === 'success'
    && $decoded[0]['message'] === 'Збережено <script>alert(1)</script>',
    'JSON flash must preserve literal message content'
);
languagesExpect(
    strpos($page, '<script>alert(1)</script>') === false,
    'Escaped success must not become executable content'
);
languagesExpect(AnabelkaFlash::consume() === [],
    'Queue must be consumed after injecting the page');

session_write_close();
echo "admin languages notification runtime passed\n";
