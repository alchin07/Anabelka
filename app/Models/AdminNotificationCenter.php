<?php

class AdminNotificationCenter
{
    private static $schemaReady = false;
    private static $summaryCache = [];


    public static function ensureSchema()
    {
        if (self::$schemaReady) {
            return;
        }

        Database::connect()->exec("
            CREATE TABLE IF NOT EXISTS admin_notification_state
            (
                admin_user_id BIGINT UNSIGNED NOT NULL,
                channel VARCHAR(80) NOT NULL,
                last_seen_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (admin_user_id, channel)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        self::$schemaReady = true;
    }


    public static function summary($adminUserId = null)
    {
        if (!class_exists('AdminAccess')) {
            return self::emptySummary();
        }

        $admin = AdminAccess::current();

        if (!$admin) {
            return self::emptySummary();
        }

        $adminUserId = $adminUserId !== null
            ? (int) $adminUserId
            : (int) ($admin['id'] ?? AdminAccess::currentId());

        if ($adminUserId <= 0) {
            return self::emptySummary();
        }

        if (isset(self::$summaryCache[$adminUserId])) {
            return self::$summaryCache[$adminUserId];
        }

        self::ensureSchema();
        $items = [];

        if (AdminAccess::can('orders.view')) {
            $count = self::countNewOrders();
            $items[] = self::item(
                'orders',
                'Нові замовлення',
                $count,
                '/Anabelka/admin/orders',
                'action'
            );
        }

        if (AdminAccess::can('users.view')) {
            $items[] = self::item(
                'new_users',
                'Нові користувачі',
                self::countNewUsers($adminUserId),
                '/Anabelka/admin/users',
                'new'
            );

            $items[] = self::item(
                'rank_requests',
                'Запити на підвищення рангу',
                self::countPendingRankRequests(),
                '/Anabelka/admin/users',
                'action'
            );
        }

        if (AdminAccess::can('translations.view')) {
            $items[] = self::item(
                'translations',
                'Переклади потребують уваги',
                self::countTranslationAttention(),
                '/Anabelka/admin/translations',
                'action'
            );
        }

        $byKey = [];
        $total = 0;

        foreach ($items as $item) {
            $key = (string) ($item['key'] ?? '');
            $count = max(0, (int) ($item['count'] ?? 0));

            if ($key !== '') {
                $byKey[$key] = $count;
            }

            $total += $count;
        }

        self::$summaryCache[$adminUserId] = [
            'total' => $total,
            'items' => $items,
            'by_key' => $byKey
        ];

        return self::$summaryCache[$adminUserId];
    }


    public static function markPathViewed($path, $method = 'GET')
    {
        $method = strtoupper((string) $method);

        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return;
        }

        $path = rtrim((string) $path, '/');

        if ($path !== '/admin/users') {
            return;
        }

        if (!class_exists('AdminAccess') || !AdminAccess::can('users.view')) {
            return;
        }

        $adminUserId = AdminAccess::currentId();

        if ($adminUserId <= 0) {
            return;
        }

        self::ensureSchema();
        self::saveCursor(
            $adminUserId,
            'users',
            self::currentMaxUserId()
        );
        unset(self::$summaryCache[$adminUserId]);
    }


    private static function countNewOrders()
    {
        return self::safeCount(
            "SELECT COUNT(*) FROM orders WHERE status = 'new'"
        ) + self::safeCount(
            "SELECT COUNT(*) FROM quick_orders WHERE status = 'new'"
        );
    }


    private static function countNewUsers($adminUserId)
    {
        $cursor = self::cursor(
            (int) $adminUserId,
            'users',
            self::currentMaxUserId()
        );

        return self::safeCount(
            "
                SELECT COUNT(*)
                FROM users
                WHERE id > :cursor
                  AND is_active = 1
            ",
            ['cursor' => $cursor]
        );
    }


    private static function countPendingRankRequests()
    {
        try {
            if (class_exists('CustomerRankRequest')) {
                CustomerRankRequest::ensureSchema();
            }
        } catch (Throwable $e) {
            return 0;
        }

        return self::safeCount(
            "
                SELECT COUNT(*)
                FROM customer_rank_requests rr
                INNER JOIN users u ON u.id = rr.user_id
                WHERE rr.status = 'pending'
                  AND u.is_active = 1
            "
        );
    }


    private static function countTranslationAttention()
    {
        $total = 0;

        if (AdminAccess::can('products.view')) {
            $total += self::safeCount("
                SELECT COUNT(*)
                FROM products p
                INNER JOIN languages l
                    ON l.is_active = 1
                   AND l.code <> 'uk'
                LEFT JOIN product_translations t
                    ON t.product_id = p.id
                   AND t.language_code = l.code
                WHERE t.product_id IS NULL
                   OR TRIM(COALESCE(t.name, '')) = ''
                   OR (
                        TRIM(COALESCE(p.description, '')) <> ''
                        AND TRIM(COALESCE(t.description, '')) = ''
                   )
                   OR COALESCE(t.status, '') <> 'approved'
            ");
        }

        if (AdminAccess::can('categories.view')) {
            $total += self::safeCount("
                SELECT COUNT(*)
                FROM categories c
                INNER JOIN languages l
                    ON l.is_active = 1
                   AND l.code <> 'uk'
                LEFT JOIN category_translations t
                    ON t.category_id = c.id
                   AND t.language_code = l.code
                WHERE t.category_id IS NULL
                   OR TRIM(COALESCE(t.name, '')) = ''
                   OR (
                        TRIM(COALESCE(c.description, '')) <> ''
                        AND TRIM(COALESCE(t.description, '')) = ''
                   )
                   OR COALESCE(t.status, '') <> 'approved'
            ");
        }

        if (AdminAccess::can('delivery.view')) {
            $total += self::deliveryTranslationAttention();
        }

        // Інтерфейсні переклади належать безпосередньо до розділу
        // «Переклади», тому достатньо translations.view.
        $total += self::safeCount("
            SELECT COUNT(*)
            FROM interface_translations source
            INNER JOIN languages l
                ON l.is_active = 1
               AND l.code <> 'uk'
            LEFT JOIN interface_translations t
                ON t.translation_key = source.translation_key
               AND t.language_code = l.code
            WHERE source.language_code = 'uk'
              AND TRIM(source.value) <> ''
              AND (
                    t.translation_key IS NULL
                    OR TRIM(COALESCE(t.value, '')) = ''
                    OR COALESCE(t.status, '') <> 'approved'
              )
        ");

        return $total;
    }


    private static function deliveryTranslationAttention()
    {
        $total = 0;

        $entities = [
            ['delivery_methods', 'method'],
            ['delivery_services', 'service'],
            ['delivery_service_options', 'option']
        ];

        foreach ($entities as $entity) {
            [$table, $type] = $entity;

            $total += self::safeCount("
                SELECT COUNT(*)
                FROM {$table} e
                INNER JOIN languages l
                    ON l.is_active = 1
                   AND l.code <> 'uk'
                LEFT JOIN delivery_translations t
                    ON t.entity_type = '{$type}'
                   AND t.entity_id = e.id
                   AND t.language_code = l.code
                WHERE t.entity_id IS NULL
                   OR TRIM(COALESCE(t.name, '')) = ''
                   OR (
                        TRIM(COALESCE(e.description, '')) <> ''
                        AND TRIM(COALESCE(t.description, '')) = ''
                   )
                   OR COALESCE(t.status, '') <> 'approved'
            ");
        }

        $total += self::safeCount("
            SELECT COUNT(*)
            FROM delivery_option_inputs i
            INNER JOIN languages l
                ON l.is_active = 1
               AND l.code <> 'uk'
            LEFT JOIN delivery_option_input_translations t
                ON t.option_id = i.option_id
               AND t.language_code = l.code
            WHERE i.is_enabled = 1
              AND (
                    TRIM(COALESCE(i.field_label, '')) <> ''
                    OR TRIM(COALESCE(i.placeholder, '')) <> ''
              )
              AND (
                    t.option_id IS NULL
                    OR (
                        TRIM(COALESCE(i.field_label, '')) <> ''
                        AND TRIM(COALESCE(t.field_label, '')) = ''
                    )
                    OR (
                        TRIM(COALESCE(i.placeholder, '')) <> ''
                        AND TRIM(COALESCE(t.placeholder, '')) = ''
                    )
                    OR COALESCE(t.status, '') <> 'approved'
              )
        ");

        return $total;
    }


    private static function cursor($adminUserId, $channel, $initialValue)
    {
        self::ensureSchema();
        $db = Database::connect();
        $stmt = $db->prepare("
            SELECT last_seen_value
            FROM admin_notification_state
            WHERE admin_user_id = :admin_user_id
              AND channel = :channel
            LIMIT 1
        ");
        $stmt->execute([
            'admin_user_id' => (int) $adminUserId,
            'channel' => (string) $channel
        ]);
        $value = $stmt->fetchColumn();

        if ($value !== false) {
            return max(0, (int) $value);
        }

        $initialValue = max(0, (int) $initialValue);
        self::saveCursor($adminUserId, $channel, $initialValue);

        return $initialValue;
    }


    private static function saveCursor($adminUserId, $channel, $value)
    {
        $stmt = Database::connect()->prepare("
            INSERT INTO admin_notification_state
                (admin_user_id, channel, last_seen_value)
            VALUES
                (:admin_user_id, :channel, :last_seen_value)
            ON DUPLICATE KEY UPDATE
                last_seen_value = VALUES(last_seen_value),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            'admin_user_id' => (int) $adminUserId,
            'channel' => (string) $channel,
            'last_seen_value' => max(0, (int) $value)
        ]);
    }


    private static function currentMaxUserId()
    {
        return self::safeCount('SELECT COALESCE(MAX(id), 0) FROM users');
    }


    private static function safeCount($sql, array $params = [])
    {
        try {
            $stmt = Database::connect()->prepare((string) $sql);
            $stmt->execute($params);

            return max(0, (int) $stmt->fetchColumn());
        } catch (Throwable $e) {
            return 0;
        }
    }


    private static function item($key, $label, $count, $url, $kind)
    {
        return [
            'key' => (string) $key,
            'label' => (string) $label,
            'count' => max(0, (int) $count),
            'url' => (string) $url,
            'kind' => (string) $kind
        ];
    }


    private static function emptySummary()
    {
        return [
            'total' => 0,
            'items' => [],
            'by_key' => []
        ];
    }
}
