<?php

class SearchQueryLog
{
    private static $schemaReady = false;


    public static function ensureSchema()
    {
        if (self::$schemaReady) {
            return;
        }

        $db = Database::connect();

        $db->exec("
            CREATE TABLE IF NOT EXISTS search_queries
            (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                query_text VARCHAR(255) NOT NULL,
                normalized_query VARCHAR(255) NOT NULL,
                user_id INT UNSIGNED NULL,
                language_code VARCHAR(10) NOT NULL,
                product_results INT UNSIGNED NOT NULL DEFAULT 0,
                category_results INT UNSIGNED NOT NULL DEFAULT 0,
                total_results INT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_search_queries_created_at (created_at),
                KEY idx_search_queries_user_id (user_id),
                KEY idx_search_queries_total_results (total_results),
                KEY idx_search_queries_normalized (normalized_query)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        self::$schemaReady = true;
    }


    public static function record(
        $query,
        $userId,
        $languageCode,
        $productResults,
        $categoryResults
    ) {
        self::ensureSchema();

        $query = CatalogSearch::normalizeQuery($query);

        if ($query === '') {
            return;
        }

        $normalized = function_exists('mb_strtolower')
            ? mb_strtolower($query, 'UTF-8')
            : strtolower($query);

        $productResults = max(0, (int) $productResults);
        $categoryResults = max(0, (int) $categoryResults);
        $totalResults = $productResults + $categoryResults;

        $db = Database::connect();
        $stmt = $db->prepare("
            INSERT INTO search_queries
            (
                query_text,
                normalized_query,
                user_id,
                language_code,
                product_results,
                category_results,
                total_results
            )
            VALUES
            (
                :query_text,
                :normalized_query,
                :user_id,
                :language_code,
                :product_results,
                :category_results,
                :total_results
            )
        ");

        $stmt->execute([
            'query_text' => $query,
            'normalized_query' => $normalized,
            'user_id' => (int) $userId > 0 ? (int) $userId : null,
            'language_code' => strtolower(trim((string) $languageCode)),
            'product_results' => $productResults,
            'category_results' => $categoryResults,
            'total_results' => $totalResults
        ]);
    }


    public static function normalizeFilters(array $input)
    {
        $visitor = strtolower(trim((string) ($input['visitor'] ?? 'all')));
        $results = strtolower(trim((string) ($input['results'] ?? 'all')));

        if (!in_array($visitor, ['all', 'user', 'guest'], true)) {
            $visitor = 'all';
        }

        if (!in_array($results, ['all', 'with', 'zero'], true)) {
            $results = 'all';
        }

        return [
            'q' => CatalogSearch::normalizeQuery($input['q'] ?? ''),
            'visitor' => $visitor,
            'results' => $results,
            'date_from' => self::validDate($input['date_from'] ?? ''),
            'date_to' => self::validDate($input['date_to'] ?? '')
        ];
    }


    public static function page(array $filters, $page = 1, $perPage = 100)
    {
        self::ensureSchema();

        $page = max(1, (int) $page);
        $perPage = max(20, min(200, (int) $perPage));
        $offset = ($page - 1) * $perPage;

        [$where, $params] = self::buildWhere($filters);
        $db = Database::connect();

        $sql = "
            SELECT
                s.id,
                s.query_text,
                s.user_id,
                s.language_code,
                s.product_results,
                s.category_results,
                s.total_results,
                s.created_at,
                u.name AS user_name,
                u.email AS user_email
            FROM search_queries s
            LEFT JOIN users u ON u.id = s.user_id
            {$where}
            ORDER BY s.id DESC
            LIMIT {$perPage} OFFSET {$offset}
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    public static function count(array $filters)
    {
        self::ensureSchema();
        [$where, $params] = self::buildWhere($filters);
        $db = Database::connect();
        $stmt = $db->prepare("SELECT COUNT(*) FROM search_queries s {$where}");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }


    public static function summary()
    {
        self::ensureSchema();
        $db = Database::connect();

        $row = $db->query("
            SELECT
                COUNT(*) AS all_count,
                SUM(total_results = 0) AS zero_count,
                SUM(user_id IS NULL) AS guest_count,
                SUM(user_id IS NOT NULL) AS user_count,
                SUM(DATE(created_at) = CURRENT_DATE()) AS today_count
            FROM search_queries
        ")->fetch(PDO::FETCH_ASSOC);

        return [
            'all' => (int) ($row['all_count'] ?? 0),
            'zero' => (int) ($row['zero_count'] ?? 0),
            'guests' => (int) ($row['guest_count'] ?? 0),
            'users' => (int) ($row['user_count'] ?? 0),
            'today' => (int) ($row['today_count'] ?? 0)
        ];
    }


    public static function periodAnalytics()
    {
        self::ensureSchema();
        $db = Database::connect();

        $row = $db->query("
            SELECT
                SUM(created_at >= CURRENT_DATE()) AS today_count,
                COUNT(DISTINCT CASE
                    WHEN created_at >= CURRENT_DATE()
                    THEN normalized_query
                END) AS today_unique,
                SUM(
                    created_at >= CURRENT_DATE()
                    AND total_results = 0
                ) AS today_zero,

                SUM(
                    created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                ) AS week_count,
                COUNT(DISTINCT CASE
                    WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                    THEN normalized_query
                END) AS week_unique,
                SUM(
                    created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                    AND total_results = 0
                ) AS week_zero,

                SUM(
                    created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                ) AS month_count,
                COUNT(DISTINCT CASE
                    WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                    THEN normalized_query
                END) AS month_unique,
                SUM(
                    created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                    AND total_results = 0
                ) AS month_zero
            FROM search_queries
        ")->fetch(PDO::FETCH_ASSOC);

        return [
            'today' => [
                'count' => (int) ($row['today_count'] ?? 0),
                'unique' => (int) ($row['today_unique'] ?? 0),
                'zero' => (int) ($row['today_zero'] ?? 0)
            ],
            'week' => [
                'count' => (int) ($row['week_count'] ?? 0),
                'unique' => (int) ($row['week_unique'] ?? 0),
                'zero' => (int) ($row['week_zero'] ?? 0)
            ],
            'month' => [
                'count' => (int) ($row['month_count'] ?? 0),
                'unique' => (int) ($row['month_unique'] ?? 0),
                'zero' => (int) ($row['month_zero'] ?? 0)
            ]
        ];
    }


    public static function popularQueries($days = 30, $limit = 10)
    {
        return self::topQueries($days, $limit, false);
    }


    public static function popularZeroQueries($days = 30, $limit = 10)
    {
        return self::topQueries($days, $limit, true);
    }


    private static function topQueries($days, $limit, $zeroOnly)
    {
        self::ensureSchema();

        $days = max(1, min(3650, (int) $days));
        $limit = max(1, min(50, (int) $limit));
        $zeroCondition = $zeroOnly
            ? ' AND total_results = 0'
            : '';
        $db = Database::connect();

        $sql = "
            SELECT
                latest.query_text,
                stats.normalized_query,
                stats.search_count,
                stats.last_searched_at,
                stats.average_results
            FROM
            (
                SELECT
                    normalized_query,
                    COUNT(*) AS search_count,
                    MAX(id) AS latest_id,
                    MAX(created_at) AS last_searched_at,
                    ROUND(AVG(total_results), 1) AS average_results
                FROM search_queries
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
                {$zeroCondition}
                GROUP BY normalized_query
            ) stats
            INNER JOIN search_queries latest
                ON latest.id = stats.latest_id
            ORDER BY
                stats.search_count DESC,
                stats.last_searched_at DESC
            LIMIT {$limit}
        ";

        return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }


    private static function buildWhere(array $filters)
    {
        $conditions = [];
        $params = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $conditions[] = 'LOCATE(LOWER(:filter_q), LOWER(s.query_text)) > 0';
            $params['filter_q'] = $q;
        }

        if (($filters['visitor'] ?? 'all') === 'user') {
            $conditions[] = 's.user_id IS NOT NULL';
        } elseif (($filters['visitor'] ?? 'all') === 'guest') {
            $conditions[] = 's.user_id IS NULL';
        }

        if (($filters['results'] ?? 'all') === 'with') {
            $conditions[] = 's.total_results > 0';
        } elseif (($filters['results'] ?? 'all') === 'zero') {
            $conditions[] = 's.total_results = 0';
        }

        $dateFrom = (string) ($filters['date_from'] ?? '');
        if ($dateFrom !== '') {
            $conditions[] = 's.created_at >= :date_from';
            $params['date_from'] = $dateFrom . ' 00:00:00';
        }

        $dateTo = (string) ($filters['date_to'] ?? '');
        if ($dateTo !== '') {
            $conditions[] = 's.created_at < DATE_ADD(:date_to, INTERVAL 1 DAY)';
            $params['date_to'] = $dateTo;
        }

        return [
            empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions),
            $params
        ];
    }


    private static function validDate($value)
    {
        $value = trim((string) $value);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return '';
        }

        return $value;
    }
}
