<?php

/**
 * Browser-only fixture: renders the real visitor statistics view and admin
 * header with synthetic report output. It never boots the app or opens a DB.
 * Usage: php tests/fixtures/visitor_stats_page.php populated 30
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
error_reporting(E_ALL);
ini_set('display_errors', '0');
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

class AdminAccess
{
    public static function current(): array
    {
        return ['id' => 1, 'name' => 'Тестовий адміністратор',
            'role_slug' => 'owner', 'role_name' => 'Власник'];
    }

    public static function can(string $permission): bool
    {
        return true;
    }

    public static function csrfToken(): string
    {
        return 'browser-fixture-token';
    }
}

$state = $argv[1] ?? 'populated';
if (!in_array($state, ['populated', 'empty', 'unavailable'], true)) {
    throw new InvalidArgumentException('Unknown browser fixture state.');
}
$period = (int) ($argv[2] ?? 30);
$period = in_array($period, [7, 30, 90], true) ? $period : 30;
$reportUnavailable = $state === 'unavailable';
$chart = [];
$start = new DateTimeImmutable('2026-09-24');
for ($index = 0; $index < 30; $index++) {
    $chart[] = ['date' => $start->modify('+' . $index . ' days')->format('Y-m-d'),
        'views' => $state === 'populated' ? ($index === 9 ? 0 : 125 + $index * 17) : 0];
}
$report = $reportUnavailable ? null : [
    'period' => $period, 'timezone' => 'Europe/Kyiv',
    'summary' => $state === 'empty'
        ? ['today' => 0, 'yesterday' => 0, 'week' => 0, 'month' => 0]
        : ['today' => 618, 'yesterday' => 601, 'week' => 3969, 'month' => 10867],
    'chart' => $chart, 'top_categories' => [], 'top_products' => [],
    'has_data' => $state === 'populated'
];
if ($state === 'populated') {
    // Deliberately long, unbroken and HTML-looking current entity names test
    // wrapping and escaping; these strings are not copied UI markup.
    $categoryNames = [
        'Жіночі сукні для щоденних прогулянок, урочистих подій і сімейних свят — велика сезонна колекція',
        str_repeat('ДужеДовгаНазваБезПробілів', 5),
        'Категорія <script>window.fixtureInjected=true</script> & "Літо"'
    ];
    $productNames = [
        'Сукня жіноча з натуральної тканини, довгим рукавом та оригінальним оздобленням для осені й весни',
        str_repeat('СуперДовгаНазваТоваруБезПробілів', 5),
        'Товар <img src=x onerror="window.fixtureInjected=true"> & "Тест"'
    ];
    foreach ($categoryNames as $index => $name) {
        $report['top_categories'][] = ['id' => $index + 1, 'name' => $name,
            'slug' => 'fixture-category-' . ($index + 1), 'department_slug' => 'women',
            'view_count' => 98765 - $index * 172];
    }
    foreach ($productNames as $index => $name) {
        $report['top_products'][] = ['id' => $index + 11, 'name' => $name,
            'slug' => 'fixture-product-' . ($index + 11),
            'main_image' => $index === 0 ? 'uploads/products/browser-fixture.png' : '',
            'view_count' => 76432 - $index * 91];
    }
}

$pageTitle = 'Адмін-панель · Статистика відвідуваності';
require __DIR__ . '/../../views/admin/visitor-stats/index.php';
