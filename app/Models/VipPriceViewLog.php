<?php

class VipPriceViewLog
{
    private static $schemaReady = false;


    public static function normalizeFilters(array $input)
    {
        $dateFrom = self::normalizeDate($input['date_from'] ?? '');
        $dateTo = self::normalizeDate($input['date_to'] ?? '');

        if (
            $dateFrom !== ''
            && $dateTo !== ''
            && $dateFrom > $dateTo
        ) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'user' => self::cleanText($input['user'] ?? '', 160),
            'product' => self::cleanText($input['product'] ?? '', 160),
            'rank_id' => max(0, (int) ($input['rank_id'] ?? 0)),
            'surface' => self::cleanKey($input['surface'] ?? '', 40),
            'view_code' => self::normalizeViewCode(
                $input['view_code'] ?? ''
            )
        ];
    }


    public static function page(array $filters, $page, $perPage = 50)
    {
        self::ensureSchema();

        $page = max(1, (int) $page);
        $perPage = max(10, min(200, (int) $perPage));
        $offset = ($page - 1) * $perPage;
        [$where, $params] = self::where($filters);

        $sql = "
            SELECT
                v.id,
                v.user_id,
                v.product_id,
                v.rank_id,
                v.price_amount,
                v.surface,
                v.view_code,
                v.viewed_at,
                u.name AS user_name,
                u.email AS user_email,
                p.name AS product_name,
                p.slug AS product_slug,
                p.sku AS product_sku,
                r.name AS rank_name,
                r.slug AS rank_slug,
                r.level AS rank_level
            FROM vip_price_view_log AS v
            LEFT JOIN users AS u
                ON u.id = v.user_id
            LEFT JOIN products AS p
                ON p.id = v.product_id
            LEFT JOIN user_ranks AS r
                ON r.id = v.rank_id
            {$where}
            ORDER BY v.viewed_at DESC, v.id DESC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = Database::connect()->prepare($sql);
        self::bindFilters($stmt, $params);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    public static function count(array $filters)
    {
        self::ensureSchema();
        [$where, $params] = self::where($filters);

        $stmt = Database::connect()->prepare("
            SELECT COUNT(*)
            FROM vip_price_view_log AS v
            LEFT JOIN users AS u
                ON u.id = v.user_id
            LEFT JOIN products AS p
                ON p.id = v.product_id
            LEFT JOIN user_ranks AS r
                ON r.id = v.rank_id
            {$where}
        ");
        self::bindFilters($stmt, $params);
        $stmt->execute();

        return max(0, (int) $stmt->fetchColumn());
    }


    public static function summary(array $filters)
    {
        self::ensureSchema();
        [$where, $params] = self::where($filters);

        $stmt = Database::connect()->prepare("
            SELECT
                COUNT(*) AS views,
                COUNT(DISTINCT v.user_id) AS users,
                COUNT(DISTINCT v.product_id) AS products,
                COUNT(DISTINCT v.rank_id) AS ranks
            FROM vip_price_view_log AS v
            LEFT JOIN users AS u
                ON u.id = v.user_id
            LEFT JOIN products AS p
                ON p.id = v.product_id
            LEFT JOIN user_ranks AS r
                ON r.id = v.rank_id
            {$where}
        ");
        self::bindFilters($stmt, $params);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row)
            ? $row
            : [
                'views' => 0,
                'users' => 0,
                'products' => 0,
                'ranks' => 0
            ];
    }


    public static function rankOptions()
    {
        self::ensureSchema();

        return Database::connect()->query("
            SELECT DISTINCT
                r.id,
                r.name,
                r.slug,
                r.level
            FROM user_ranks AS r
            INNER JOIN vip_price_view_log AS v
                ON v.rank_id = r.id
            ORDER BY r.level ASC, r.id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }


    public static function surfaceOptions()
    {
        self::ensureSchema();

        return Database::connect()->query("
            SELECT DISTINCT surface
            FROM vip_price_view_log
            WHERE surface <> ''
            ORDER BY surface ASC
        ")->fetchAll(PDO::FETCH_COLUMN);
    }


    public static function ensureSchema()
    {
        if (self::$schemaReady) {
            return;
        }

        Database::connect()->exec("
            CREATE TABLE IF NOT EXISTS vip_price_view_log
            (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                product_id INT UNSIGNED NOT NULL,
                rank_id INT UNSIGNED NOT NULL,
                price_amount DECIMAL(12,2) NOT NULL,
                surface VARCHAR(40) NOT NULL,
                view_code CHAR(6) NOT NULL,
                session_hash CHAR(64) NULL,
                viewed_at DATETIME NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_vip_price_user_date (user_id, viewed_at),
                KEY idx_vip_price_product_date (product_id, viewed_at),
                KEY idx_vip_price_code (view_code)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        self::$schemaReady = true;
    }


    private static function where(array $filters)
    {
        $conditions = [];
        $params = [];

        $dateFrom = (string) ($filters['date_from'] ?? '');
        $dateTo = (string) ($filters['date_to'] ?? '');
        $user = trim((string) ($filters['user'] ?? ''));
        $product = trim((string) ($filters['product'] ?? ''));
        $rankId = max(0, (int) ($filters['rank_id'] ?? 0));
        $surface = trim((string) ($filters['surface'] ?? ''));
        $viewCode = trim((string) ($filters['view_code'] ?? ''));

        if ($dateFrom !== '') {
            $conditions[] = 'v.viewed_at >= :date_from';
            $params['date_from'] = $dateFrom . ' 00:00:00';
        }

        if ($dateTo !== '') {
            $conditions[] = 'v.viewed_at < DATE_ADD(:date_to, INTERVAL 1 DAY)';
            $params['date_to'] = $dateTo . ' 00:00:00';
        }

        if ($user !== '') {
            if (ctype_digit($user)) {
                $conditions[] = 'v.user_id = :user_id';
                $params['user_id'] = (int) $user;
            } else {
                $conditions[] = '(
                    u.name LIKE :user_query_name
                    OR u.email LIKE :user_query_email
                )';
                $params['user_query_name'] = '%' . $user . '%';
                $params['user_query_email'] = '%' . $user . '%';
            }
        }

        if ($product !== '') {
            if (ctype_digit($product)) {
                $conditions[] = 'v.product_id = :product_id';
                $params['product_id'] = (int) $product;
            } else {
                $conditions[] = '(
                    p.name LIKE :product_query_name
                    OR p.sku LIKE :product_query_sku
                    OR p.slug LIKE :product_query_slug
                )';
                $params['product_query_name'] = '%' . $product . '%';
                $params['product_query_sku'] = '%' . $product . '%';
                $params['product_query_slug'] = '%' . $product . '%';
            }
        }

        if ($rankId > 0) {
            $conditions[] = 'v.rank_id = :rank_id';
            $params['rank_id'] = $rankId;
        }

        if ($surface !== '') {
            $conditions[] = 'v.surface = :surface';
            $params['surface'] = $surface;
        }

        if ($viewCode !== '') {
            $conditions[] = 'v.view_code = :view_code';
            $params['view_code'] = $viewCode;
        }

        return [
            !empty($conditions)
                ? 'WHERE ' . implode(' AND ', $conditions)
                : '',
            $params
        ];
    }


    private static function bindFilters(PDOStatement $stmt, array $params)
    {
        foreach ($params as $key => $value) {
            $type = is_int($value)
                ? PDO::PARAM_INT
                : PDO::PARAM_STR;

            $stmt->bindValue(':' . $key, $value, $type);
        }
    }


    private static function normalizeDate($value)
    {
        $value = trim((string) $value);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return '';
        }

        $date = DateTime::createFromFormat('!Y-m-d', $value);
        $errors = DateTime::getLastErrors();

        if (
            !$date
            || (
                is_array($errors)
                && (
                    !empty($errors['warning_count'])
                    || !empty($errors['error_count'])
                )
            )
            || $date->format('Y-m-d') !== $value
        ) {
            return '';
        }

        return $value;
    }


    private static function normalizeViewCode($value)
    {
        $value = strtoupper(trim((string) $value));
        $value = preg_replace('/[^A-F0-9]/', '', $value);

        return substr((string) $value, 0, 6);
    }


    private static function cleanKey($value, $maxLength)
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9_-]+/', '-', $value);
        $value = trim((string) $value, '-');

        return substr($value, 0, (int) $maxLength);
    }


    private static function cleanText($value, $maxLength)
    {
        $value = trim((string) $value);

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, (int) $maxLength, 'UTF-8');
        }

        return substr($value, 0, (int) $maxLength);
    }
}
