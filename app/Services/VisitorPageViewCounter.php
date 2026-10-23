<?php

/** One best-effort aggregate increment after an allowlisted HTML view has rendered. */
final class VisitorPageViewCounter
{
    private array $config;
    private $connection;
    private $clock;
    private bool $attempted = false;
    private static ?self $requestCounter = null;

    private const PATHS = [
        'home' => '#^/$#D',
        'catalog' => '#^/catalog/?$#D',
        'category' => '#^/catalog/[^/]+/[^/]+/?$#D',
        'product' => '#^/product/[^/]+/?$#D',
        'news_index' => '#^/news/?$#D',
        'news' => '#^/news/[^/]+/?$#D',
        'discounts' => '#^/discounts/?$#D',
        'new' => '#^/new/?$#D'
    ];

    public function __construct(array $config, callable $connection, ?callable $clock = null)
    {
        $this->config = $config;
        $this->connection = $connection;
        $this->clock = $clock ?? static fn() => new DateTimeImmutable('now');
    }

    public static function configuration(): array
    {
        return require __DIR__ . '/../../config/visitor-stats.php';
    }

    public static function recordRendered(string $type, int $entityId = 0, bool $adult = false): void
    {
        try {
            if (self::$requestCounter === null) {
                self::$requestCounter = new self(
                    self::configuration(),
                    static fn() => Database::connect()
                );
            }
            // currentId() reads only the existing authentication flag; current()
            // would invoke legacy admin schema seeding on a public request.
            $admin = class_exists('AdminAccess') && AdminAccess::currentId() > 0;
            self::$requestCounter->count(
                $type, $entityId, $_SERVER, $admin,
                (int) (http_response_code() ?: 200), headers_list(), $adult
            );
        } catch (Throwable $e) {
            // No exception text/URL/session data is copied into analytics logs.
        }
    }

    public function count(
        string $type, int $entityId, array $server, bool $isAdmin = false,
        int $status = 200, array $headers = [], bool $adult = false
    ): bool {
        if ($this->attempted) {
            return false;
        }
        $this->attempted = true;

        try {
            if (!$this->eligible($type, $entityId, $server, $isAdmin, $status, $headers, $adult)) {
                return false;
            }
            $timezone = new DateTimeZone((string) ($this->config['timezone'] ?? ''));
            $now = ($this->clock)();
            if (!$now instanceof DateTimeImmutable) {
                return false;
            }
            $date = $now->setTimezone($timezone)->format('Y-m-d');
            $db = ($this->connection)();
            // Never participate in or alter a shop transaction. No retries or DDL.
            if ($db->inTransaction()) {
                return false;
            }
            $stmt = $db->prepare("
                SET STATEMENT max_statement_time=0.1,
                    innodb_lock_wait_timeout=1, lock_wait_timeout=1 FOR
                INSERT INTO site_page_view_daily
                    (stat_date, page_type, entity_id, view_count)
                VALUES (:stat_date, :page_type, :entity_id, 1)
                ON DUPLICATE KEY UPDATE view_count = view_count + 1
            ");
            return $stmt->execute(['stat_date' => $date, 'page_type' => $type, 'entity_id' => $entityId]);
        } catch (Throwable $e) {
            return false;
        }
    }

    private function eligible(
        string $type, int $entityId, array $server, bool $admin,
        int $status, array $headers, bool $adult
    ): bool {
        if (($this->config['enabled'] ?? false) !== true || $admin || $adult || $status !== 200
            || ($server['REQUEST_METHOD'] ?? '') !== 'GET' || !isset(self::PATHS[$type])) {
            return false;
        }
        $entityType = in_array($type, ['category', 'product', 'news'], true);
        if (($entityType && $entityId <= 0) || (!$entityType && $entityId !== 0)) {
            return false;
        }
        $host = self::hostname((string) ($server['HTTP_HOST'] ?? ''));
        $allowed = $this->config['allowed_hosts'] ?? [];
        if ($host === null || !is_array($allowed) || !in_array($host, $allowed, true)) {
            return false;
        }
        $remote = strtolower((string) ($server['REMOTE_ADDR'] ?? ''));
        if ($remote === '::1' || preg_match('/^(?:::ffff:)?127\./', $remote)) {
            return false;
        }
        $path = parse_url((string) ($server['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $base = $this->config['base_path'] ?? null;
        if (!is_string($path) || !is_string($base)
            || ($base !== '' && (!preg_match('#^/[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$#D', $base)
                || ($path !== $base && strpos($path, $base . '/') !== 0)))) {
            return false;
        }
        $path = substr($path, strlen($base));
        if ($path === '') { $path = '/'; }
        if (!preg_match(self::PATHS[$type], $path)) {
            return false;
        }
        // Inspect only for exclusion. The URI, query and headers are never stored.
        $query = [];
        parse_str((string) (parse_url((string) ($server['REQUEST_URI'] ?? ''), PHP_URL_QUERY) ?? ''), $query);
        foreach (['builder_preview', 'preview', 'token', 'access_token', 'invite', 'invitation'] as $key) {
            if (array_key_exists($key, $query)) { return false; }
        }
        if (!empty($server['HTTP_X_REQUESTED_WITH'])) {
            return false;
        }
        $accept = strtolower((string) ($server['HTTP_ACCEPT'] ?? ''));
        if ($accept !== '' && strpos($accept, 'text/html') === false
            && strpos($accept, 'application/xhtml+xml') === false && trim($accept) !== '*/*') {
            return false;
        }
        if (isset($server['HTTP_SEC_FETCH_DEST']) && $server['HTTP_SEC_FETCH_DEST'] !== 'document') {
            return false;
        }
        if (isset($server['HTTP_SEC_FETCH_MODE']) && $server['HTTP_SEC_FETCH_MODE'] !== 'navigate') {
            return false;
        }
        foreach (['HTTP_PURPOSE','HTTP_SEC_PURPOSE','HTTP_X_PURPOSE','HTTP_X_MOZ'] as $key) {
            if (preg_match('/prefetch|prerender|preview/i', (string) ($server[$key] ?? ''))) {
                return false;
            }
        }
        // Heuristic only: spoofed UAs and unknown automation cannot be identified reliably.
        if (preg_match('/bot\b|crawler|spider|slurp|bingpreview|facebookexternalhit|headless|lighthouse|curl\b|wget\b|python-requests|httpclient|uptimerobot|monitoring/i', (string) ($server['HTTP_USER_AGENT'] ?? ''))) {
            return false;
        }
        foreach ($headers as $header) {
            if (preg_match('/^Location\s*:/i', $header)) { return false; }
            if (preg_match('/^Content-Type\s*:\s*([^;]+)/i', $header, $match)
                && !in_array(strtolower(trim($match[1])), ['text/html','application/xhtml+xml'], true)) {
                return false;
            }
        }
        return true;
    }

    private static function hostname(string $value): ?string
    {
        // Exact HTTP Host allowlist. Forwarded host headers are never trusted.
        if (!preg_match('/^([a-z0-9](?:[a-z0-9.-]*[a-z0-9])?)(?::[0-9]{1,5})?$/iD', $value, $match)) {
            return null;
        }
        $host = strtolower($match[1]);
        if (filter_var($host, FILTER_VALIDATE_IP) || !str_contains($host, '.')
            || preg_match('/(?:^|\.)(?:localhost|local|test|invalid|example)$/D', $host)
            || preg_match('/(?:^|[.-])(?:test|testing|staging|dev|development)(?:[.-]|$)/D', $host)) {
            return null;
        }
        return $host;
    }
}
