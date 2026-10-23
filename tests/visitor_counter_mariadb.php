<?php

/**
 * Real SQL/HTTP tests. Run with tests/run-visitor-counter-db.sh, or explicitly set
 * VC_TEST_DSN to an expendable database whose name ends in _vc_test.
 * This file never loads config/database.php or the application's Database class.
 */
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
    fwrite(STDERR, "REFUSED: explicitly set VC_TEST_DSN to an expendable database ending _vc_test.\n");
    exit(2);
}

function vcAssert($expected, $actual, string $message): void
{
    $GLOBALS['vcCheckCount'] = ($GLOBALS['vcCheckCount'] ?? 0) + 1;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

function vcProcess(array $command): array
{
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start a fixture process.');
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out, $err];
}

function vcTotal(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COALESCE(SUM(view_count),0) FROM site_page_view_daily')->fetchColumn();
}

function vcReadCount(PDO $pdo, string $date, string $type, int $id): int
{
    $statement = $pdo->prepare('SELECT view_count FROM site_page_view_daily WHERE stat_date=:date AND page_type=:type AND entity_id=:id');
    $statement->execute(['date' => $date, 'type' => $type, 'id' => $id]);
    return (int) $statement->fetchColumn();
}

$httpProcess = null;
$tempDirectory = null;
$testExit = 0;
try {
    $pdo = new PDO($dsn, getenv('VC_TEST_USER') ?: 'root', getenv('VC_TEST_PASSWORD') === false ? '' : getenv('VC_TEST_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $databaseName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($databaseName !== $expectedDatabase || !str_ends_with($databaseName, '_vc_test')) {
        throw new RuntimeException('Unsafe test database.');
    }
    $existing = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=:database');
    $existing->execute(['database' => $databaseName]);
    if ((int) $existing->fetchColumn() !== 0) {
        throw new RuntimeException('REFUSED: integration database must be empty before any fixtures are created.');
    }
    echo 'Native database: ' . $pdo->query('SELECT VERSION()')->fetchColumn() . "\n";
    echo 'Native PHP: ' . PHP_VERSION . "; MariaDB 10.4 compatibility is not a native 10.4 run.\n";

    $migration = file_get_contents(__DIR__ . '/../database/migrations/2026-10-23_visitor_counter.sql');
    $pdo->exec($migration);
    $pdo->exec($migration);
    $columns = $pdo->query('SHOW COLUMNS FROM site_page_view_daily')->fetchAll(PDO::FETCH_COLUMN);
    vcAssert(['stat_date', 'page_type', 'entity_id', 'view_count'], $columns, 'Aggregate table must contain only the four approved fields');
    $primary = array_values(array_filter($pdo->query('SHOW INDEX FROM site_page_view_daily')->fetchAll(), static fn ($row) => $row['Key_name'] === 'PRIMARY'));
    vcAssert(['stat_date', 'page_type', 'entity_id'], array_column($primary, 'Column_name'), 'Daily unique key');
    echo "PASS idempotent migration and aggregate schema\n";

    require __DIR__ . '/../app/Services/VisitorPageViewCounter.php';
    $config = ['enabled' => true, 'allowed_hosts' => ['shop.anabelka.com.ua'], 'base_path' => '/Anabelka', 'timezone' => 'Europe/Kyiv', 'retention_days' => 400];
    $server = ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'shop.anabelka.com.ua', 'REQUEST_URI' => '/Anabelka/', 'REMOTE_ADDR' => '8.8.8.8', 'HTTP_USER_AGENT' => 'Mozilla/5.0', 'HTTP_ACCEPT' => 'text/html'];
    $makeCounter = static fn (string $instant = '2026-10-23T12:00:00+00:00') => new VisitorPageViewCounter($config, static fn () => $pdo, static fn () => new DateTimeImmutable($instant));
    $settingsSql = 'SELECT @@session.max_statement_time AS statement_time, @@session.innodb_lock_wait_timeout AS lock_timeout';
    $settings = $pdo->query($settingsSql)->fetch();
    $counter = $makeCounter();
    vcAssert(true, $counter->count('home', 0, $server), 'Valid render increments');
    vcAssert(false, $counter->count('catalog', 0, $server), 'One request records only one canonical type');
    vcAssert(1, vcTotal($pdo), 'Once per instance');
    vcAssert(true, $makeCounter()->count('home', 0, $server), 'A reload is another view');
    vcAssert(2, vcReadCount($pdo, '2026-10-23', 'home', 0), 'Reload updates the same aggregate');
    vcAssert($settings, $pdo->query($settingsSql)->fetch(), 'Counter must not modify session settings');

    date_default_timezone_set('Pacific/Honolulu');
    $productServer = array_replace($server, ['REQUEST_URI' => '/Anabelka/product/fixture']);
    vcAssert(true, $makeCounter('2026-10-22T20:59:59+00:00')->count('product', 91, $productServer), 'Before Kyiv midnight');
    vcAssert(true, $makeCounter('2026-10-22T21:00:00+00:00')->count('product', 91, $productServer), 'At Kyiv midnight');
    vcAssert(1, vcReadCount($pdo, '2026-10-22', 'product', 91), 'Configured timezone previous date');
    vcAssert(1, vcReadCount($pdo, '2026-10-23', 'product', 91), 'Configured timezone next date');
    echo "PASS canonical count, reload, configured midnight, session settings\n";

    $pdo->exec('DELETE FROM site_page_view_daily');
    $workers = [];
    for ($i = 0; $i < 8; ++$i) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __DIR__ . '/visitor_counter_concurrency_worker.php', '50', 'product', '92'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start a concurrency worker.');
        }
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    foreach ($workers as [$process, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        vcAssert(0, proc_close($process), 'Parallel increment worker: ' . $out . $err);
    }
    vcAssert(400, vcReadCount($pdo, '2026-10-23', 'product', 92), 'Atomic upsert must preserve every parallel increment');
    vcAssert(1, (int) $pdo->query('SELECT COUNT(*) FROM site_page_view_daily')->fetchColumn(), 'Parallel increments share one row');
    echo "PASS 8 concurrent processes x 50 increments = 400, no lost updates\n";

    $pdo->beginTransaction();
    $pdo->query("SELECT * FROM site_page_view_daily WHERE stat_date='2026-10-23' AND page_type='product' AND entity_id=92 FOR UPDATE")->fetchAll();
    $started = microtime(true);
    [$lockExit] = vcProcess([PHP_BINARY, __DIR__ . '/visitor_counter_concurrency_worker.php', '1', 'product', '92']);
    $lockSeconds = microtime(true) - $started;
    $pdo->rollBack();
    vcAssert(1, $lockExit, 'A locked counter must fail open');
    if ($lockSeconds > 2.5) {
        throw new RuntimeException('Counter waited too long on a locked row: ' . $lockSeconds . 's');
    }
    vcAssert(400, vcReadCount($pdo, '2026-10-23', 'product', 92), 'Failed locked increment must not alter the counter');
    echo 'PASS row lock fails open in ' . number_format($lockSeconds, 3) . "s\n";

    $pdo->exec('RENAME TABLE site_page_view_daily TO vc_test_saved_daily');
    try {
        vcAssert(false, $makeCounter()->count('home', 0, $server), 'Missing migration fails open');
        $table = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=:database AND table_name=:table');
        $table->execute(['database' => $databaseName, 'table' => 'site_page_view_daily']);
        vcAssert(0, (int) $table->fetchColumn(), 'Counter must not perform runtime DDL');
    } finally {
        $pdo->exec('RENAME TABLE vc_test_saved_daily TO site_page_view_daily');
    }
    echo "PASS missing migration fails open without runtime DDL\n";

    // Actual HTTP requests exercise a render fixture with the production gate.
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
    if (!$socket) {
        throw new RuntimeException('Could not reserve fixture HTTP port.');
    }
    $httpPort = parse_url('tcp://' . stream_socket_get_name($socket, false), PHP_URL_PORT);
    fclose($socket);
    $tempDirectory = sys_get_temp_dir() . '/visitor-counter-test-' . bin2hex(random_bytes(8));
    if (!mkdir($tempDirectory, 0700)) {
        throw new RuntimeException('Could not create fixture directory.');
    }
    putenv('VC_TEST_HTTP=1');
    $httpProcess = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $httpPort, __DIR__ . '/visitor_counter_test_server.php'], [0 => ['pipe', 'r'], 1 => ['file', $tempDirectory . '/http.log', 'a'], 2 => ['file', $tempDirectory . '/http.log', 'a']], $httpPipes);
    if (!is_resource($httpProcess)) {
        throw new RuntimeException('Could not start fixture HTTP server.');
    }
    fclose($httpPipes[0]);
    for ($i = 0; $i < 100; ++$i) {
        $probe = @stream_socket_client('tcp://127.0.0.1:' . $httpPort, $errorNumber, $errorMessage, 0.05);
        if ($probe) {
            fclose($probe);
            break;
        }
        usleep(20000);
    }
    $request = static function (string $path, string $method = 'GET', string $host = 'shop.anabelka.com.ua', string $extra = '') use ($httpPort): array {
        $context = stream_context_create(['http' => ['method' => $method, 'header' => "Host: {$host}\r\nUser-Agent: Mozilla/5.0\r\nAccept: text/html\r\n" . $extra, 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 3]]);
        $body = file_get_contents('http://127.0.0.1:' . $httpPort . $path, false, $context);
        return [$body, $http_response_header ?? []];
    };
    $before = vcTotal($pdo);
    [$body, $responseHeaders] = $request('/Anabelka/');
    vcAssert(true, str_contains((string) $body, 'Rendered fixture'), 'HTML response succeeds');
    vcAssert(true, str_contains($responseHeaders[0] ?? '', '200'), 'HTML response status');
    $request('/Anabelka/');
    vcAssert($before + 2, vcTotal($pdo), 'Two HTTP GET reloads increment twice');
    $before = vcTotal($pdo);
    $request('/Anabelka/', 'HEAD');
    $request('/Anabelka/', 'POST');
    $request('/Anabelka/?fixture=json');
    $request('/Anabelka/?fixture=redirect');
    $request('/Anabelka/?fixture=missing');
    $request('/Anabelka/?fixture=admin-session');
    $request('/Anabelka/', 'GET', 'staging.anabelka.com.ua');
    $request('/Anabelka/', 'GET', 'shop.anabelka.com.ua', "Purpose: prefetch\r\n");
    [$body, $responseHeaders] = $request('/Anabelka/?fixture=fail-open');
    vcAssert(true, str_contains((string) $body, 'Rendered fixture'), 'Database failure preserves HTML');
    vcAssert(true, str_contains($responseHeaders[0] ?? '', '200'), 'Database failure preserves status');
    vcAssert($before, vcTotal($pdo), 'HTTP exclusions and failed storage do not increment');
    echo "PASS actual HTTP production facade, reload, exclusions, fail-open response\n";

    // Retention uses only an explicit fixture config and this guarded test DSN.
    preg_match('/(?:^|;)host=([^;]+)/', substr($dsn, 6), $hostMatch);
    preg_match('/(?:^|;)port=([0-9]+)/', substr($dsn, 6), $portMatch);
    preg_match('/(?:^|;)unix_socket=([^;]+)/', substr($dsn, 6), $socketMatch);
    $databaseConfig = ['host' => $hostMatch[1] ?? '127.0.0.1', 'port' => $portMatch[1] ?? '3306', 'database' => $databaseName, 'username' => getenv('VC_TEST_USER') ?: 'root', 'password' => getenv('VC_TEST_PASSWORD') === false ? '' : getenv('VC_TEST_PASSWORD'), 'charset' => 'utf8mb4'];
    if (isset($socketMatch[1])) {
        $databaseConfig['unix_socket'] = $socketMatch[1];
    }
    file_put_contents($tempDirectory . '/database.php', '<?php return ' . var_export($databaseConfig, true) . ';');
    file_put_contents($tempDirectory . '/visitor.php', '<?php return ["retention_days"=>400,"timezone"=>"Europe/Kyiv"];');
    $cutoff = (new DateTimeImmutable('today', new DateTimeZone('Europe/Kyiv')))->sub(new DateInterval('P400D'))->format('Y-m-d');
    $oldDate = (new DateTimeImmutable($cutoff))->sub(new DateInterval('P1D'))->format('Y-m-d');
    $olderDate = (new DateTimeImmutable($cutoff))->sub(new DateInterval('P2D'))->format('Y-m-d');
    $pdo->exec('DELETE FROM site_page_view_daily');
    $insert = $pdo->prepare('INSERT INTO site_page_view_daily(stat_date,page_type,entity_id,view_count) VALUES(:date,:type,:id,:views)');
    $insert->execute(['date' => $olderDate, 'type' => 'home', 'id' => 0, 'views' => 1]);
    for ($i = 1; $i <= 1001; ++$i) {
        $insert->execute(['date' => $oldDate, 'type' => 'product', 'id' => $i, 'views' => 1]);
    }
    $insert->execute(['date' => $cutoff, 'type' => 'home', 'id' => 0, 'views' => 3]);
    $command = [PHP_BINARY, __DIR__ . '/../tools/visitor-stats-prune.php', '--config=' . $tempDirectory . '/visitor.php', '--database-config=' . $tempDirectory . '/database.php'];
    [$exit, $out, $err] = vcProcess($command);
    vcAssert(0, $exit, 'Retention dry-run: ' . $err);
    vcAssert(true, str_contains($out, 'mode=dry-run') && str_contains($out, 'candidate_rows=1002'), 'Dry-run previews candidates');
    vcAssert(1005, vcTotal($pdo), 'Default dry-run preserves all data');
    [$exit, $out, $err] = vcProcess(array_merge($command, ['--apply', '--max-batches=1']));
    vcAssert(0, $exit, 'Retention apply: ' . $err);
    vcAssert(true, str_contains($out, 'deleted_rows=1000') && str_contains($out, 'remaining_rows=2'), 'Retention batch is bounded');
    vcAssert(0, vcReadCount($pdo, $olderDate, 'home', 0), 'Retention deletes oldest dates first');
    vcAssert(3, vcReadCount($pdo, $cutoff, 'home', 0), 'Retention retains cutoff date');
    file_put_contents($tempDirectory . '/invalid.php', '<?php return ["retention_days"=>0,"timezone"=>"Europe/Kyiv"];');
    [$exit] = vcProcess([PHP_BINARY, __DIR__ . '/../tools/visitor-stats-prune.php', '--apply', '--config=' . $tempDirectory . '/invalid.php', '--database-config=' . $tempDirectory . '/database.php']);
    vcAssert(2, $exit, 'Invalid retention must refuse deletion');
    vcAssert(5, vcTotal($pdo), 'Invalid retention preserves rows');
    echo "PASS retention dry-run, bounded apply, oldest date, cutoff, invalid config\n";

    // Current catalog fixtures exist only in the newly created empty test schema.
    $pdo->exec('CREATE TABLE departments (id INT PRIMARY KEY, slug VARCHAR(100) NOT NULL)');
    $pdo->exec('CREATE TABLE categories (id INT PRIMARY KEY, parent_id INT NULL, department_id INT NOT NULL, name VARCHAR(150) NOT NULL, slug VARCHAR(150) NOT NULL, is_adult TINYINT NOT NULL DEFAULT 0)');
    $pdo->exec('CREATE TABLE products (id INT PRIMARY KEY, category_id INT NOT NULL, name VARCHAR(150) NOT NULL, slug VARCHAR(150) NOT NULL, main_image VARCHAR(255) NULL)');
    $pdo->exec("INSERT INTO departments VALUES (1,'shop')");
    $pdo->exec("INSERT INTO categories VALUES (1,NULL,1,'Current public category','current-public',0),(2,NULL,1,'Adult root','adult',1),(3,2,1,'Child','child',0),(4,3,1,'Grandchild','grandchild',0)");
    $pdo->exec("INSERT INTO products VALUES (10,1,'Current public product','current-product','current.jpg'),(20,4,'Adult descendant product','adult-product','adult.jpg')");
    $pdo->exec('DELETE FROM site_page_view_daily');
    $reportRows = [
        ['2026-09-23', 'home', 0, 1], ['2026-09-24', 'home', 0, 2],
        ['2026-09-25', 'category', 1, 100], ['2026-09-30', 'home', 0, 3],
        ['2026-10-01', 'home', 0, 4], ['2026-10-18', 'home', 0, 5],
        ['2026-10-19', 'home', 0, 6], ['2026-10-22', 'home', 0, 7],
        ['2026-10-23', 'home', 0, 8], ['2026-10-23', 'category', 1, 10],
        ['2026-10-23', 'category', 2, 100], ['2026-10-23', 'category', 3, 200],
        ['2026-10-23', 'category', 4, 300], ['2026-10-23', 'category', 999, 400],
        ['2026-10-23', 'product', 10, 20], ['2026-10-23', 'product', 20, 500],
        ['2026-10-23', 'product', 999, 600], ['2026-10-24', 'home', 0, 900],
    ];
    foreach ($reportRows as [$date, $type, $id, $views]) {
        $insert->execute(['date' => $date, 'type' => $type, 'id' => $id, 'views' => $views]);
    }
    require __DIR__ . '/../app/Models/VisitorStats.php';
    $stats = new VisitorStats($pdo, new DateTimeZone('Europe/Kyiv'), static fn () => new DateTimeImmutable('2026-10-22T22:30:00+00:00'));
    $beforeReport = $pdo->query('SELECT * FROM site_page_view_daily ORDER BY stat_date,page_type,entity_id')->fetchAll();
    // Real read-only transaction catches unexpected reporting writes.
    $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    $report = $stats->report(30);
    $weekReport = $stats->report(7);
    $pdo->rollBack();
    vcAssert(['today' => 2138, 'yesterday' => 7, 'week' => 2156, 'month' => 2265], $report['summary'], 'Rolling calendar-day summary uses local dates and sums canonical types once');
    vcAssert(30, count($report['chart']), 'Chart always has thirty days');
    vcAssert(['date' => '2026-09-24', 'views' => 2], $report['chart'][0], 'Chart starts at inclusive thirty-day boundary');
    vcAssert(['date' => '2026-10-23', 'views' => 2138], $report['chart'][29], 'Chart ends at local today and excludes future rows');
    vcAssert(['date' => '2026-09-26', 'views' => 0], $report['chart'][2], 'Absent dates are zero-filled');
    vcAssert([1], array_column($report['top_categories'], 'id'), 'Deleted and adult graph categories are absent from popularity');
    vcAssert(110, $report['top_categories'][0]['view_count'], 'Thirty-day popularity boundary');
    vcAssert(10, $weekReport['top_categories'][0]['view_count'], 'Seven-day popularity boundary');
    vcAssert('Current public category', $report['top_categories'][0]['name'], 'Category metadata is current');
    vcAssert([10], array_column($report['top_products'], 'id'), 'Deleted and inherited adult products are absent from popularity');
    vcAssert('current-product', $report['top_products'][0]['slug'], 'Product link uses current metadata');
    vcAssert($beforeReport, $pdo->query('SELECT * FROM site_page_view_daily ORDER BY stat_date,page_type,entity_id')->fetchAll(), 'Reports do not write aggregate data');
    echo "PASS actual read-only report SQL, local calendar totals, zero-fill, periods, current metadata, adult ancestors\n";

    // Inject the guarded PDO rather than loading the normal Database connection.
    class Database
    {
        public static PDO $connection;

        public static function connect(): PDO
        {
            return self::$connection;
        }
    }
    Database::$connection = $pdo;
    require __DIR__ . '/../app/Models/AdminAccess.php';
    AdminAccess::ensureSchema();
    $insertAdmin = $pdo->prepare('INSERT INTO admin_users(role_id,name,email,password_hash) VALUES(:role_id,:name,:email,:password)');
    $roleIds = $pdo->query('SELECT slug,id FROM admin_roles')->fetchAll(PDO::FETCH_KEY_PAIR);
    $adminIds = [];
    foreach (['owner' => true, 'store_owner' => true, 'administrator' => false, 'order_manager' => false, 'content_manager' => false] as $slug => $allowed) {
        $insertAdmin->execute(['role_id' => $roleIds[$slug], 'name' => 'VC test ' . $slug, 'email' => 'vc-' . $slug . '-' . bin2hex(random_bytes(3)) . '@example.test', 'password' => 'fixture-not-a-login-password']);
        $adminIds[$slug] = (int) $pdo->lastInsertId();
        $_SESSION = ['admin_user_id' => $adminIds[$slug]];
        vcAssert($allowed, AdminAccess::can('visitor_stats.view'), 'Default visitor stats access for ' . $slug);
    }
    $grant = $pdo->prepare('INSERT INTO admin_role_permissions(role_id,permission_key) VALUES(:role_id,:permission)');
    $grant->execute(['role_id' => $roleIds['content_manager'], 'permission' => 'visitor_stats.view']);
    $_SESSION = ['admin_user_id' => $adminIds['content_manager']];
    vcAssert(true, AdminAccess::can('visitor_stats.view'), 'Explicit permission grants report access');
    $beforeAdmin = vcTotal($pdo);
    foreach (['owner', 'store_owner', 'content_manager'] as $role) {
        [$body, $responseHeaders] = $request('/Anabelka/admin/visitor-stats?fixture_role=' . $role);
        vcAssert(true, str_contains($responseHeaders[0] ?? '', '200'), 'Production admin controller authorized HTTP response for ' . $role);
        vcAssert(['authorized' => true, 'report_available' => true, 'unavailable' => false], json_decode((string) $body, true), 'Authorized real report renders for ' . $role);
    }
    foreach (['administrator', 'order_manager', ''] as $role) {
        [$body, $responseHeaders] = $request('/Anabelka/admin/visitor-stats?fixture_role=' . $role);
        vcAssert(true, str_contains($responseHeaders[0] ?? '', '403'), 'Production admin controller denied HTTP response for ' . ($role ?: 'anonymous'));
        vcAssert(false, str_contains((string) $body, '"authorized":true'), 'Denied controller response does not render report');
    }
    $revoke = $pdo->prepare('DELETE FROM admin_role_permissions WHERE role_id=:role_id AND permission_key=:permission');
    $revoke->execute(['role_id' => $roleIds['content_manager'], 'permission' => 'visitor_stats.view']);
    vcAssert(false, AdminAccess::can('visitor_stats.view'), 'Revoked permission removes report access');
    [$body, $responseHeaders] = $request('/Anabelka/admin/visitor-stats?fixture_role=content_manager');
    vcAssert(true, str_contains($responseHeaders[0] ?? '', '403'), 'Revoked role receives real HTTP 403');
    $grant->execute(['role_id' => $roleIds['administrator'], 'permission' => 'visitor_stats.view']);
    $_SESSION = ['admin_user_id' => $adminIds['administrator']];
    vcAssert(true, AdminAccess::can('visitor_stats.view'), 'Explicit visitor permission grants ordinary administrator access');
    [$body, $responseHeaders] = $request('/Anabelka/admin/visitor-stats?fixture_role=administrator');
    vcAssert(true, str_contains($responseHeaders[0] ?? '', '200'), 'Explicit administrator grant receives real HTTP 200');
    vcAssert(['authorized' => true, 'report_available' => true, 'unavailable' => false], json_decode((string) $body, true), 'Explicitly authorized administrator renders the real report');
    $revoke->execute(['role_id' => $roleIds['administrator'], 'permission' => 'visitor_stats.view']);
    vcAssert(false, AdminAccess::can('visitor_stats.view'), 'Revoking the ordinary administrator grant removes access');
    [$body, $responseHeaders] = $request('/Anabelka/admin/visitor-stats?fixture_role=administrator');
    vcAssert(true, str_contains($responseHeaders[0] ?? '', '403'), 'Revoked administrator receives real HTTP 403');
    vcAssert(false, str_contains((string) $body, '"authorized":true'), 'Revoked administrator cannot render report data');
    vcAssert($beforeAdmin, vcTotal($pdo), 'Admin report HTTP requests never count page views');
    $_SESSION = [];
    vcAssert(false, AdminAccess::can('visitor_stats.view'), 'Anonymous requests cannot read report');
    vcAssert('visitor_stats.view', AdminAccess::permissionForRequest('GET', '/admin/visitor-stats'), 'Report route has explicit permission');
    echo "PASS actual AdminAccess role seeds and controller HTTP 200/403, explicit grant/revoke, anonymous denial\n";

    [$guardExit, $guardOut, $guardError] = vcProcess([PHP_BINARY, __FILE__]);
    vcAssert(1, $guardExit, 'A nonempty pre-existing fixture database must be refused');
    vcAssert(true, str_contains($guardError, 'database must be empty'), 'Nonempty schema refusal explains the safety guard');
    vcAssert($beforeAdmin, vcTotal($pdo), 'Nonempty schema refusal preserves data');
    // The unsafe database name is rejected before a connection is attempted.
    putenv('VC_TEST_DSN=mysql:host=127.0.0.1;port=1;dbname=anabelka;charset=utf8mb4');
    try {
        [$guardExit, $guardOut, $guardError] = vcProcess([PHP_BINARY, __FILE__]);
    } finally {
        putenv('VC_TEST_DSN=' . $dsn);
    }
    vcAssert(2, $guardExit, 'A normal application database name must be refused before connection');
    vcAssert(true, str_contains($guardError, 'ending _vc_test'), 'Unsafe DSN refusal explains the database suffix guard');
    putenv('VC_TEST_DSN=mysql:host=127.0.0.1;port=1;dbname=anabelka_vc_test;dbname=anabelka');
    try {
        [$guardExit] = vcProcess([PHP_BINARY, __FILE__]);
    } finally {
        putenv('VC_TEST_DSN=' . $dsn);
    }
    vcAssert(2, $guardExit, 'Duplicate database declarations cannot bypass the DSN guard');
    echo "PASS unsafe DSN and pre-existing nonempty schema refused without mutation\n";

    echo 'PASS visitor counter MariaDB integration; assertions=' . ($GLOBALS['vcCheckCount'] ?? 0) . "\n";
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . "\n");
    $testExit = 1;
} finally {
    if (is_resource($httpProcess)) {
        proc_terminate($httpProcess);
        proc_close($httpProcess);
    }
    if (is_string($tempDirectory) && is_dir($tempDirectory)) {
        foreach (glob($tempDirectory . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($tempDirectory);
    }
}
exit($testExit);
