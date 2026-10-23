#!/usr/bin/env php
<?php

/**
 * Offline retention for aggregated page views. Dry-run unless --apply is given.
 * This command never creates or changes the table schema.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function visitorStatsPruneUsage(): void
{
    echo <<<'HELP'
Usage: php tools/visitor-stats-prune.php [--apply] [options]

By default, only report aggregate rows older than the configured retention.
--apply                    Delete old rows, oldest date first.
--dry-run                  Explicitly select the default preview mode.
--config=PATH              Visitor config (default: config/visitor-stats.php).
--database-config=PATH     Database config (default: config/database.php).
--max-batches=N            At most N batches of 1000 rows (default: 10; 1-100).
--help                     Show this help without connecting to a database.

The default visitor config is overlaid with visitor-stats.local.php if present.
An explicit --config is used alone. Rows on the cutoff date are retained.

HELP;
}

function visitorStatsPruneConfig(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new InvalidArgumentException('Configuration file is missing or unreadable.');
    }

    $config = require $path;
    if (!is_array($config)) {
        throw new InvalidArgumentException('Configuration must return an array.');
    }

    return $config;
}

function visitorStatsPruneDsnValue(array $config, string $key, string $default = ''): string
{
    $value = $config[$key] ?? $default;
    if (!is_string($value) && !is_int($value)) {
        throw new InvalidArgumentException('Database configuration is invalid.');
    }
    $value = (string) $value;
    if ($value === '' || preg_match('/[;\x00-\x1f\x7f]/', $value)) {
        throw new InvalidArgumentException('Database configuration is invalid.');
    }

    return $value;
}

try {
    $root = dirname(__DIR__);
    $options = [
        'apply' => false,
        'dry-run' => false,
        'config' => $root . '/config/visitor-stats.php',
        'database-config' => $root . '/config/database.php',
        'max-batches' => '10',
    ];
    $customConfig = false;

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--help') {
            visitorStatsPruneUsage();
            exit(0);
        }
        if ($argument === '--apply' || $argument === '--dry-run') {
            $options[substr($argument, 2)] = true;
            continue;
        }
        if (!preg_match('/^--(config|database-config|max-batches)=(.+)$/D', $argument, $matches)) {
            throw new InvalidArgumentException('Unknown or incomplete option. Use --help for usage.');
        }
        $options[$matches[1]] = $matches[2];
        if ($matches[1] === 'config') {
            $customConfig = true;
        }
    }

    if ($options['apply'] && $options['dry-run']) {
        throw new InvalidArgumentException('Choose either --apply or --dry-run.');
    }
    if (!preg_match('/^[1-9][0-9]{0,2}$/D', $options['max-batches'])
        || (int) $options['max-batches'] > 100) {
        throw new InvalidArgumentException('--max-batches must be an integer from 1 to 100.');
    }
    $maxBatches = (int) $options['max-batches'];

    $config = visitorStatsPruneConfig($options['config']);
    $localPath = $root . '/config/visitor-stats.local.php';
    if (!$customConfig && is_file($localPath)) {
        $config = array_replace($config, visitorStatsPruneConfig($localPath));
    }

    $retentionDays = $config['retention_days'] ?? null;
    if (!is_int($retentionDays) || $retentionDays < 1 || $retentionDays > 36500) {
        throw new InvalidArgumentException('retention_days must be an integer from 1 to 36500.');
    }
    if (!isset($config['timezone']) || !is_string($config['timezone']) || $config['timezone'] === '') {
        throw new InvalidArgumentException('A valid retention timezone is required.');
    }
    try {
        $timezone = new DateTimeZone($config['timezone']);
    } catch (Exception $exception) {
        throw new InvalidArgumentException('A valid retention timezone is required.');
    }
    $cutoff = (new DateTimeImmutable('today', $timezone))
        ->sub(new DateInterval('P' . $retentionDays . 'D'))
        ->format('Y-m-d');

    // Validate all retention settings before loading credentials or connecting.
    $database = visitorStatsPruneConfig($options['database-config']);
    $databaseName = visitorStatsPruneDsnValue($database, 'database');
    $charset = visitorStatsPruneDsnValue($database, 'charset', 'utf8mb4');
    if (!preg_match('/^[A-Za-z0-9_]+$/D', $charset)) {
        throw new InvalidArgumentException('Database configuration is invalid.');
    }
    if (isset($database['unix_socket'])) {
        $dsn = 'mysql:unix_socket=' . visitorStatsPruneDsnValue($database, 'unix_socket');
    } else {
        $host = visitorStatsPruneDsnValue($database, 'host', '127.0.0.1');
        $port = visitorStatsPruneDsnValue($database, 'port', '3306');
        if (!preg_match('/^[0-9]{1,5}$/D', $port) || (int) $port < 1 || (int) $port > 65535) {
            throw new InvalidArgumentException('Database configuration is invalid.');
        }
        $dsn = 'mysql:host=' . $host . ';port=' . $port;
    }
    foreach (['username', 'password'] as $credential) {
        if (!isset($database[$credential]) || !is_string($database[$credential])) {
            throw new InvalidArgumentException('Database configuration is invalid.');
        }
    }
    $dsn .= ';dbname=' . $databaseName . ';charset=' . $charset;
    $pdo = new PDO($dsn, $database['username'], $database['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $preview = $pdo->prepare(
        'SELECT COUNT(*) AS row_count, MIN(stat_date) AS oldest_date '
        . 'FROM site_page_view_daily WHERE stat_date < :cutoff'
    );
    $preview->execute(['cutoff' => $cutoff]);
    $before = $preview->fetch();

    echo 'mode=' . ($options['apply'] ? 'apply' : 'dry-run') . PHP_EOL;
    echo 'cutoff=' . $cutoff . PHP_EOL;
    echo 'candidate_rows=' . (string) $before['row_count'] . PHP_EOL;
    echo 'oldest_date=' . ($before['oldest_date'] ?? 'none') . PHP_EOL;

    $deletedRows = 0;
    $batches = 0;
    if ($options['apply']) {
        $delete = $pdo->prepare(
            'DELETE FROM site_page_view_daily WHERE stat_date < :cutoff '
            . 'ORDER BY stat_date ASC LIMIT 1000'
        );
        do {
            $delete->execute(['cutoff' => $cutoff]);
            $affectedRows = $delete->rowCount();
            $deletedRows += $affectedRows;
            ++$batches;
        } while ($affectedRows === 1000 && $batches < $maxBatches);
    }

    $preview->execute(['cutoff' => $cutoff]);
    $after = $preview->fetch();
    echo 'deleted_rows=' . $deletedRows . PHP_EOL;
    echo 'batches=' . $batches . PHP_EOL;
    echo 'remaining_rows=' . (string) $after['row_count'] . PHP_EOL;
    exit(0);
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, 'Visitor statistics retention refused: ' . $exception->getMessage() . PHP_EOL);
    exit(2);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Visitor statistics retention failed. Check the configuration and table availability.' . PHP_EOL);
    exit(1);
}
