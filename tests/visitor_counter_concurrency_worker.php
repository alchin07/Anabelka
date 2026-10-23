<?php

// Never load the application's default connection configuration in this fixture.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$dsn = getenv('VC_TEST_DSN');
$databaseParts = is_string($dsn) ? preg_match_all('/(?:^|;)dbname=([^;]*)(?=;|$)/i', substr($dsn, 6), $databaseMatches) : 0;
$expectedDatabase = $databaseParts === 1 ? $databaseMatches[1][0] : '';
if (!is_string($dsn) || !preg_match('/^mysql:/', $dsn)
    || !preg_match('/^[A-Za-z0-9_]+$/D', $expectedDatabase)
    || !str_ends_with($expectedDatabase, '_vc_test')) {
    fwrite(STDERR, "REFUSED: explicitly set VC_TEST_DSN to a database ending _vc_test.\n");
    exit(2);
}

$iterations = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
$type = $argv[2] ?? 'home';
$entity = filter_var($argv[3] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
$instant = $argv[4] ?? '2026-10-23T12:00:00+00:00';
if ($iterations === false || $entity === false || !in_array($type, ['home', 'catalog', 'category', 'product', 'news_index', 'news', 'discounts', 'new'], true)) {
    fwrite(STDERR, "REFUSED: invalid worker arguments.\n");
    exit(2);
}

try {
    $pdo = new PDO($dsn, getenv('VC_TEST_USER') ?: 'root', getenv('VC_TEST_PASSWORD') === false ? '' : getenv('VC_TEST_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
        throw new RuntimeException('Unsafe test database.');
    }
    require __DIR__ . '/../app/Services/VisitorPageViewCounter.php';
    $config = ['enabled' => true, 'allowed_hosts' => ['shop.anabelka.com.ua'], 'base_path' => '/Anabelka', 'timezone' => 'Europe/Kyiv', 'retention_days' => 400];
    $server = ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'shop.anabelka.com.ua', 'REQUEST_URI' => '/Anabelka/', 'REMOTE_ADDR' => '8.8.8.8', 'HTTP_USER_AGENT' => 'Mozilla/5.0', 'HTTP_ACCEPT' => 'text/html'];
    $paths = ['home' => '/', 'catalog' => '/catalog', 'category' => '/catalog/shop/fixture', 'product' => '/product/fixture', 'news_index' => '/news', 'news' => '/news/fixture', 'discounts' => '/discounts', 'new' => '/new'];
    $server['REQUEST_URI'] = '/Anabelka' . $paths[$type];
    for ($i = 0; $i < $iterations; ++$i) {
        $counter = new VisitorPageViewCounter($config, static fn () => $pdo, static fn () => new DateTimeImmutable($instant));
        if (!$counter->count($type, $entity, $server)) {
            throw new RuntimeException('Counter refused a valid fixture.');
        }
    }
    echo "OK increments={$iterations}\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "FAIL: isolated concurrency worker failed.\n");
    exit(1);
}
