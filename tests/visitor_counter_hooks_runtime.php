<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Real controller actions and inherited counter hook; only rendering/domain I/O
// are isolated. Removing/moving a hook, changing its ID, or omitting the adult
// flag must fail these tests. No application bootstrap or database is opened.
final class VisitorHookTrace
{
    public static array $events = [];
    public static bool $failRender = false;
}

final class VisitorHookStatement extends PDOStatement
{
    public array $rows = [];
    public function execute(?array $params = null): bool
    {
        $this->rows[] = $params;
        VisitorHookTrace::$events[] = ['write', $params];
        return true;
    }
}

final class VisitorHookDatabase extends PDO
{
    public array $queries = [];
    public VisitorHookStatement $statement;
    public function __construct() { $this->statement = new VisitorHookStatement(); }
    public function inTransaction(): bool { return false; }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queries[] = $query;
        return $this->statement;
    }
}

// Domain doubles prevent production seeders, session helpers and data access.
class AdminAccess
{
    public static bool $previewAllowed = false;
    public static function currentId() { return 0; }
    public static function can($permission) { return self::$previewAllowed; }
}
class PublicInterfaceTranslator { public static function seed() {} }
class HomeInterfaceTranslator { public static function seed() {} }
class ContentInterfaceTranslator { public static function seed() {} }
class Translator
{
    public static function currentLanguage() { return ['code' => 'uk']; }
    public static function t($key, $fallback) { return $fallback; }
}
class Language { public const SOURCE_CODE = 'uk'; }
class CategoryTranslator
{
    public static function localize($item, $language) { return $item; }
    public static function localizeList($items, $language) { return $items; }
    public static function localizeTree($items, $language) { return $items; }
}
class ProductTranslator
{
    public static function localize($item, $language) { return $item; }
    public static function localizeList($items, $language) { return $items; }
}
class HomePage
{
    public static function directions() { return []; }
    public static function localizedNavigationTree($language) { return []; }
}
class HomePageBlock { public static function activeByZone() { return []; } }
class Category
{
    public static bool $adult = false;
    public static function navigationTree() { return []; }
    public static function children($id) { return []; }
    public static function findByDepartmentAndSlug($department, $slug)
    {
        return ['id' => 42, 'department_slug' => $department, 'slug' => $slug,
            'name' => 'Fixture category', 'effective_adult' => self::$adult];
    }
    public static function findById($id)
    {
        return self::findByDepartmentAndSlug('lingerie', 'lace');
    }
}
class AdultAccess { public static function isConfirmed() { return true; } }
class Product
{
    public static function pageByCategory($id, $page, $limit) { return ['items' => []]; }
    public static function findBySlug($slug)
    {
        return ['id' => 71, 'category_id' => 42, 'slug' => $slug,
            'name' => 'Fixture product', 'stock' => 3, 'stock_mode' => 'total'];
    }
    public static function attributes($id) { return []; }
    public static function images($id) { return []; }
    public static function getPricesByRanks($id) { return []; }
    public static function getCurrentRankSlug() { return 'retail'; }
    public static function getBadges($id) { return []; }
}
class VipPriceProtection { public static function forVisiblePrices($id, $prices, $context) { return []; } }
class ProductReview { public static function approvedForProduct($id) { return []; } }
class CustomerAccount { public static function currentId() { return 0; } }
class SiteNews
{
    public static bool $missing = false;
    public static function publishedPage($language) { return []; }
    public static function findPublishedBySlug($slug, $language)
    {
        return self::$missing ? null : ['id' => 9, 'slug' => $slug, 'title' => 'Fixture news'];
    }
}
class StorefrontProductCollection
{
    public static bool $unavailable = false;
    public static function page($source, $page, $language)
    {
        if (self::$unavailable) { throw new RuntimeException('expected collection failure'); }
        return ['items' => []];
    }
}

require_once __DIR__ . '/../app/Services/VisitorPageViewCounter.php';
require_once __DIR__ . '/../app/Core/Controller.php';
foreach (['Home', 'Catalog', 'Product', 'News', 'StorefrontPage'] as $controller) {
    require_once __DIR__ . '/../app/Controllers/' . $controller . 'Controller.php';
}

trait VisitorHookRender
{
    protected function view($view, $data = [])
    {
        VisitorHookTrace::$events[] = ['view', $view];
        if (VisitorHookTrace::$failRender) {
            throw new RuntimeException('expected render failure');
        }
        VisitorHookTrace::$events[] = ['rendered', $view];
    }
}
class VisitorHookHomeController extends HomeController { use VisitorHookRender; }
class VisitorHookCatalogController extends CatalogController { use VisitorHookRender; }
class VisitorHookProductController extends ProductController { use VisitorHookRender; }
class VisitorHookNewsController extends NewsController { use VisitorHookRender; }
class VisitorHookStorefrontController extends StorefrontPageController { use VisitorHookRender; }

$passed = 0;
$check = static function (bool $ok, string $message) use (&$passed): void {
    if (!$ok) { throw new RuntimeException($message); }
    $passed++;
};
$counterProperty = new ReflectionProperty(VisitorPageViewCounter::class, 'requestCounter');
$reset = static function (string $path) use ($counterProperty): array {
    $_GET = [];
    $_SESSION = [];
    $_SERVER = ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'shop.anabelka.com.ua',
        'REQUEST_URI' => '/Anabelka' . $path, 'REMOTE_ADDR' => '8.8.8.8',
        'HTTP_USER_AGENT' => 'Mozilla/5.0', 'HTTP_ACCEPT' => 'text/html'];
    http_response_code(200);
    header_remove();
    VisitorHookTrace::$events = [];
    VisitorHookTrace::$failRender = false;
    Category::$adult = false;
    SiteNews::$missing = false;
    StorefrontProductCollection::$unavailable = false;
    AdminAccess::$previewAllowed = false;
    $db = new VisitorHookDatabase();
    $connections = (object) ['count' => 0];
    $counter = new VisitorPageViewCounter(
        ['enabled' => true, 'allowed_hosts' => ['shop.anabelka.com.ua'],
            'base_path' => '/Anabelka', 'timezone' => 'Europe/Kyiv'],
        static function () use ($db, $connections) { $connections->count++; return $db; },
        static fn() => new DateTimeImmutable('2026-10-23T12:00:00Z')
    );
    $counterProperty->setValue(null, $counter);
    return [$db, $connections];
};
$cases = [
    ['home', 0, '/', 'home', static fn() => (new VisitorHookHomeController())->index()],
    ['catalog', 0, '/catalog', 'catalog/index', static fn() => (new VisitorHookCatalogController())->index()],
    ['category', 42, '/catalog/lingerie/lace', 'catalog/category',
        static fn() => (new VisitorHookCatalogController())->category('lingerie', 'lace')],
    ['product', 71, '/product/lace', 'product/show', static fn() => (new VisitorHookProductController())->show('lace')],
    ['news_index', 0, '/news', 'news/index', static fn() => (new VisitorHookNewsController())->index()],
    ['news', 9, '/news/open', 'news/show', static fn() => (new VisitorHookNewsController())->show('open')],
    ['discounts', 0, '/discounts', 'storefront/discounts', static fn() => (new VisitorHookStorefrontController())->discounts()],
    ['new', 0, '/new', 'storefront/new', static fn() => (new VisitorHookStorefrontController())->newArrivals()]
];

foreach ($cases as [$type, $id, $path, $view, $action]) {
    [$db, $connections] = $reset($path);
    $action();
    $expected = ['stat_date' => '2026-10-23', 'page_type' => $type, 'entity_id' => $id];
    $check($db->statement->rows === [$expected], "$type records its actual aggregate key once");
    $check(VisitorHookTrace::$events === [['view', $view], ['rendered', $view], ['write', $expected]],
        "$type increments only after rendering completes");
    $check($connections->count === 1 && count($db->queries) === 1,
        "$type uses a single database statement");

    [$db, $connections] = $reset($path);
    VisitorHookTrace::$failRender = true;
    $thrown = false;
    try { $action(); } catch (RuntimeException $e) { $thrown = $e->getMessage() === 'expected render failure'; }
    $check($thrown, "$type render failure propagates");
    $check(VisitorHookTrace::$events === [['view', $view]], "$type never records a failed render");
    $check($connections->count === 0 && $db->queries === [] && $db->statement->rows === [],
        "$type render failure performs no database work");
}

foreach ([$cases[2], $cases[3]] as [$type, $id, $path, $view, $action]) {
    [$db, $connections] = $reset($path);
    Category::$adult = true; // Confirmed age permits the real action to render.
    $action();
    $check(VisitorHookTrace::$events === [['view', $view], ['rendered', $view]], "$type adult content renders");
    $check($connections->count === 0 && $db->queries === [] && $db->statement->rows === [],
        "$type adult flag excludes statistics before database access");
}

[$db, $connections] = $reset('/news/missing');
SiteNews::$missing = true;
(new VisitorHookNewsController())->show('missing');
$check(http_response_code() === 404, 'missing news returns 404');
$check(VisitorHookTrace::$events === [['view', 'errors/public'], ['rendered', 'errors/public']], 'missing news renders error view');
$check($connections->count === 0 && $db->queries === [] && $db->statement->rows === [], 'missing news is not counted');

$previousErrorLog = ini_get('error_log');
ini_set('error_log', '/dev/null'); // Expected fixture exceptions are not test failures.
try {
    foreach ([$cases[6], $cases[7]] as [$type, $id, $path, $view, $action]) {
        [$db, $connections] = $reset($path);
        StorefrontProductCollection::$unavailable = true;
        $action();
        $check(http_response_code() === 503, "$type collection failure returns 503");
        $check(VisitorHookTrace::$events === [['view', 'errors/public'], ['rendered', 'errors/public']], "$type collection failure renders error view");
        $check($connections->count === 0 && $db->queries === [] && $db->statement->rows === [], "$type unavailable collection is not counted");
    }
} finally {
    ini_set('error_log', (string) $previousErrorLog);
}

[$db, $connections] = $reset('/?builder_preview=home');
$_GET = ['builder_preview' => 'home'];
ob_start();
try { (new VisitorHookHomeController())->index(); } finally { $body = ob_get_clean(); }
$check(http_response_code() === 403 && str_contains($body, '403'), 'forbidden home preview returns 403');
$check(VisitorHookTrace::$events === [], 'forbidden home preview never renders public home');
$check($connections->count === 0 && $db->queries === [] && $db->statement->rows === [], 'forbidden home preview is not counted');

$counterProperty->setValue(null, null);
echo "$passed visitor counter controller hook checks passed\n";
