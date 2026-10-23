<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function visitorStatsExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

visitorStatsExpect(
    is_file(__DIR__ . '/../app/Models/VisitorStats.php'),
    'VisitorStats report model must exist'
);
require_once __DIR__ . '/../app/Models/VisitorStats.php';

// The PDO double isolates aggregation from a working site database. It only
// accepts SELECTs; the MariaDB integration suite exercises the actual SQL.
class VisitorStatsFixtureDatabase extends PDO
{
    public array $daily = [];
    public array $categories = [];
    public array $products = [];
    public bool $unavailable = false;

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        visitorStatsExpect((bool) preg_match('/^\s*SELECT\b/i', $query),
            'Reports must never mutate or ensure database tables');
        if ($this->unavailable) {
            throw new PDOException('Private database failure');
        }
        return new VisitorStatsFixtureStatement($this, $query);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $statement = $this->prepare($query);
        $statement->execute();
        return $statement;
    }
}

class VisitorStatsFixtureStatement extends PDOStatement
{
    private array $rows = [];

    public function __construct(private VisitorStatsFixtureDatabase $db, private string $sql)
    {
    }

    public function execute(?array $params = null): bool
    {
        $params = $params ?? [];
        if (str_contains($this->sql, 'FROM categories c')) {
            $this->rows = $this->db->categories;
            return true;
        }

        visitorStatsExpect(str_contains($this->sql, 'FROM site_page_view_daily'),
            'Unexpected report query');
        visitorStatsExpect(isset($params['date_from'], $params['date_to']),
            'Every aggregate must have an inclusive date range');
        $daily = array_filter($this->db->daily, static function (array $row) use ($params): bool {
            return $row['stat_date'] >= $params['date_from']
                && $row['stat_date'] <= $params['date_to']
                && (!isset($params['page_type']) || $row['page_type'] === $params['page_type']);
        });
        $grouped = [];
        if (str_contains($this->sql, 'GROUP BY stat_date')) {
            foreach ($daily as $row) {
                $date = $row['stat_date'];
                $grouped[$date] = ($grouped[$date] ?? 0) + $row['view_count'];
            }
            ksort($grouped);
            $this->rows = [];
            foreach ($grouped as $date => $views) {
                $this->rows[] = ['stat_date' => $date, 'views' => $views];
            }
        } else {
            foreach ($daily as $row) {
                $id = $row['entity_id'];
                $grouped[$id] = ($grouped[$id] ?? 0) + $row['view_count'];
            }
            $this->rows = [];
            foreach ($grouped as $id => $views) {
                if (str_contains($this->sql, 'JOIN products p')) {
                    if (isset($this->db->products[$id])) {
                        $this->rows[] = $this->db->products[$id] + ['view_count' => $views];
                    }
                } else {
                    $this->rows[] = ['entity_id' => $id, 'view_count' => $views];
                }
            }
        }
        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->rows;
    }
}

$db = new VisitorStatsFixtureDatabase();
foreach ([
    [1, null, 0], [2, null, 1], [3, 2, 0], [4, 3, 0],
    [5, 1, 0], [6, 999, 0], [7, 8, 0], [8, 7, 0]
] as [$id, $parentId, $adult]) {
    $db->categories[] = [
        'id' => $id, 'parent_id' => $parentId, 'department_id' => 1,
        'name' => 'Current category ' . $id, 'slug' => 'category-' . $id,
        'is_adult' => $adult, 'department_slug' => 'women'
    ];
}
foreach ([[11, 1], [12, 3], [13, 5], [14, 999]] as [$id, $categoryId]) {
    $db->products[$id] = [
        'id' => $id, 'category_id' => $categoryId,
        'name' => 'Current product ' . $id, 'slug' => 'product-' . $id,
        'main_image' => '/Anabelka/uploads/products/current-' . $id . '.jpg'
    ];
}
foreach ([
    ['2026-09-23', 'home', 0, 100], ['2026-09-24', 'home', 0, 5],
    ['2026-09-30', 'home', 0, 7], ['2026-10-01', 'home', 0, 11],
    ['2026-10-01', 'category', 5, 99], ['2026-10-01', 'product', 11, 15],
    ['2026-10-18', 'home', 0, 13], ['2026-10-19', 'catalog', 0, 17],
    ['2026-10-22', 'news', 1, 19], ['2026-10-22', 'product', 11, 4],
    ['2026-10-22', 'product', 12, 53], ['2026-10-22', 'product', 13, 6],
    ['2026-10-22', 'product', 14, 59], ['2026-10-23', 'home', 0, 23],
    ['2026-10-23', 'category', 1, 3], ['2026-10-23', 'category', 2, 31],
    ['2026-10-23', 'category', 3, 37], ['2026-10-23', 'category', 4, 41],
    ['2026-10-23', 'category', 5, 2], ['2026-10-23', 'category', 6, 43],
    ['2026-10-23', 'category', 7, 47], ['2026-10-24', 'home', 0, 999]
] as [$date, $type, $entityId, $views]) {
    $db->daily[] = ['stat_date' => $date, 'page_type' => $type,
        'entity_id' => $entityId, 'view_count' => $views];
}

$clock = static fn () => new DateTimeImmutable('2026-10-22 22:30:00+00:00');
$stats = new VisitorStats($db, new DateTimeZone('Europe/Kyiv'), $clock);
$report = $stats->report(7);
visitorStatsExpect($report['summary'] === [
    'today' => 227, 'yesterday' => 141, 'week' => 398, 'month' => 535
], 'Summary must use Kyiv today/yesterday and inclusive rolling 7/30 calendar days');
visitorStatsExpect(count($report['chart']) === 30
    && $report['chart'][0] === ['date' => '2026-09-24', 'views' => 5]
    && $report['chart'][1] === ['date' => '2026-09-25', 'views' => 0]
    && $report['chart'][29] === ['date' => '2026-10-23', 'views' => 227],
    'Chart must fill exactly the last 30 calendar dates, including today');
visitorStatsExpect(array_column($report['top_categories'], 'id') === [1, 5],
    'Popularity must omit adult categories, all descendants, broken parents and cycles');
visitorStatsExpect(array_column($report['top_products'], 'id') === [13, 11],
    'Product popularity must exclude current inherited-adult or missing categories');
visitorStatsExpect($report['top_products'][0]['main_image'] === '/Anabelka/uploads/products/current-13.jpg'
    && $report['top_products'][0]['name'] === 'Current product 13',
    'Product popularity must use current metadata');
visitorStatsExpect($report['top_categories'][0]['name'] === 'Current category 1'
    && $report['has_data'] === true, 'Current categories and report availability must be retained');
$month = $stats->report(30);
visitorStatsExpect($month['top_categories'][0]['id'] === 5
    && $month['top_categories'][0]['view_count'] === 101
    && $month['top_products'][0]['view_count'] === 19,
    'The popularity period must include older counts within its inclusive range');
visitorStatsExpect($stats->report(14)['period'] === 30, 'Unsupported periods must normalize to 30 days');
visitorStatsExpect($stats->report(90)['period'] === 90, 'The 90-day popularity choice must be supported');

// Local midnight crosses a DST boundary on the day before this report.
$dstDb = new VisitorStatsFixtureDatabase();
$dstDb->daily = [
    ['stat_date' => '2026-10-25', 'page_type' => 'home', 'entity_id' => 0, 'view_count' => 2],
    ['stat_date' => '2026-10-26', 'page_type' => 'home', 'entity_id' => 0, 'view_count' => 3]
];
$dst = (new VisitorStats($dstDb, new DateTimeZone('Europe/Kyiv'),
    static fn () => new DateTimeImmutable('2026-10-25 22:30:00+00:00')))->report();
visitorStatsExpect($dst['summary'] === ['today' => 3, 'yesterday' => 2, 'week' => 5, 'month' => 5],
    'Rolling calendar periods must include yesterday across a daylight saving change');

$emptyDb = new VisitorStatsFixtureDatabase();
$empty = (new VisitorStats($emptyDb, new DateTimeZone('Europe/Kyiv'), $clock))->report();
visitorStatsExpect($empty['has_data'] === false && count($empty['chart']) === 30
    && array_sum(array_column($empty['chart'], 'views')) === 0,
    'An available but empty report must retain its zero-filled chart');
$emptyDb->unavailable = true;
$failed = false;
try {
    (new VisitorStats($emptyDb, new DateTimeZone('Europe/Kyiv'), $clock))->report();
} catch (PDOException $error) {
    $failed = true;
}
visitorStatsExpect($failed, 'A database failure must remain distinct from an empty report');

$limitDb = new VisitorStatsFixtureDatabase();
for ($id = 20; $id <= 32; $id++) {
    $limitDb->categories[] = ['id' => $id, 'parent_id' => null, 'department_id' => 1,
        'name' => 'Category ' . $id, 'slug' => 'category-' . $id,
        'is_adult' => $id === 20 ? 1 : 0, 'department_slug' => 'women'];
    $limitDb->products[$id] = ['id' => $id, 'category_id' => $id,
        'name' => 'Product ' . $id, 'slug' => 'product-' . $id, 'main_image' => ''];
    foreach (['category', 'product'] as $type) {
        $limitDb->daily[] = ['stat_date' => '2026-10-23', 'page_type' => $type,
            'entity_id' => $id, 'view_count' => $id === 20 ? 100 : 5];
    }
}
$limited = (new VisitorStats($limitDb, new DateTimeZone('Europe/Kyiv'), $clock))->report();
visitorStatsExpect(array_column($limited['top_categories'], 'id') === range(21, 30)
    && array_column($limited['top_products'], 'id') === range(21, 30),
    'Both top tens must apply their limit after filtering and resolve ties consistently');

class Controller
{
    public ?array $lastView = null;

    protected function view($name, $data = [])
    {
        $this->lastView = ['name' => $name, 'data' => $data];
    }
}

class AdminAccess
{
    public static bool $allowed = true;

    public static function can($permission): bool
    {
        return self::$allowed && $permission === 'visitor_stats.view';
    }

    public static function current()
    {
        return null;
    }

    public static function csrfToken(): string
    {
        return '';
    }
}

class Database
{
    public static ?PDO $db = null;
    public static int $connections = 0;

    public static function connect(): PDO
    {
        self::$connections++;
        return self::$db;
    }
}

visitorStatsExpect(is_file(__DIR__ . '/../app/Controllers/AdminVisitorStatsController.php'),
    'The protected visitor-statistics controller must exist');
require_once __DIR__ . '/../app/Controllers/AdminVisitorStatsController.php';
AdminAccess::$allowed = false;
$controller = new AdminVisitorStatsController();
ob_start();
$controller->index();
$denied = ob_get_clean();
visitorStatsExpect(http_response_code() === 403 && $controller->lastView === null
    && Database::$connections === 0 && $denied !== '',
    'Unauthorized requests must return 403 before opening the analytics database');
AdminAccess::$allowed = true;
http_response_code(200);
Database::$db = new VisitorStatsFixtureDatabase();
$_GET = ['period' => '90'];
$controller->index();
visitorStatsExpect($controller->lastView['name'] === 'admin/visitor-stats/index'
    && $controller->lastView['data']['period'] === 90
    && $controller->lastView['data']['reportUnavailable'] === false,
    'Authorized requests must render a report and retain the requested popularity period');
Database::$db->unavailable = true;
$controller->index();
visitorStatsExpect($controller->lastView['data']['reportUnavailable'] === true
    && $controller->lastView['data']['report'] === null,
    'The controller must render an unavailable state instead of fabricated zero counts');

visitorStatsExpect(is_file(__DIR__ . '/../views/admin/visitor-stats/index.php'),
    'The visitor-statistics report view must exist');
function visitorStatsRender(array $reportData): string
{
    extract($reportData);
    ob_start();
    require __DIR__ . '/../views/admin/visitor-stats/index.php';
    return ob_get_clean();
}
$html = visitorStatsRender(['report' => $empty, 'reportUnavailable' => false, 'period' => 30]);
visitorStatsExpect(str_contains($html, 'Даних поки немає') && str_contains($html, '<svg')
    && str_contains($html, '<details') && substr_count($html, 'data-visitor-date=') === 30,
    'An empty report must show its empty state, accessible graphic and actual 30-day table');
$unavailableHtml = visitorStatsRender($controller->lastView['data']);
visitorStatsExpect(str_contains($unavailableHtml, 'Статистика тимчасово недоступна')
    && !str_contains($unavailableHtml, '<svg')
    && !str_contains($unavailableHtml, 'Private database failure')
    && !str_contains($unavailableHtml, 'Даних поки немає'),
    'Unavailable rendering must not imply an empty report or disclose database errors');
$unsafe = $report;
$unsafe['top_products'] = [[
    'id' => 1, 'name' => '<script>alert(1)</script>', 'slug' => 'x" onmouseover="alert(1)',
    'main_image' => 'https://remote.invalid/tracker.jpg', 'view_count' => 1
]];
$unsafe['top_categories'] = [[
    'id' => 1, 'name' => '<img src=x onerror=alert(1)>', 'slug' => 'unsafe/?',
    'department_slug' => 'women"', 'view_count' => 1
]];
$safeHtml = visitorStatsRender(['report' => $unsafe, 'reportUnavailable' => false, 'period' => 7]);
visitorStatsExpect(!str_contains($safeHtml, '<script>alert(1)</script>')
    && !str_contains($safeHtml, '<img src=x')
    && !str_contains($safeHtml, 'remote.invalid')
    && str_contains($safeHtml, '&lt;script&gt;alert(1)&lt;/script&gt;')
    && str_contains($safeHtml, 'x%22%20onmouseover%3D%22alert%281%29'),
    'Entity text and links must be escaped and remote image requests must be omitted');
$unsafe['top_products'][0]['main_image'] = '/Anabelka/uploads/products/safe image.jpg';
$localHtml = visitorStatsRender(['report' => $unsafe, 'reportUnavailable' => false, 'period' => 7]);
visitorStatsExpect(str_contains($localHtml, '/Anabelka/uploads/products/safe%20image.jpg'),
    'Local current product images must remain visible with encoded paths');
foreach (['//remote.invalid/a.jpg', 'data:image/svg+xml,unsafe',
    '/Anabelka/uploads/../../admin/delete.jpg', '/Anabelka/uploads/%2e%2e/admin/a.jpg'] as $unsafePath) {
    $unsafe['top_products'][0]['main_image'] = $unsafePath;
    $pathHtml = visitorStatsRender(['report' => $unsafe, 'reportUnavailable' => false, 'period' => 7]);
    visitorStatsExpect(!str_contains($pathHtml, 'class="visitor-product-image"'),
        'Unsafe product image paths must fall back to the placeholder');
}

echo "Visitor statistics runtime tests passed\n";
