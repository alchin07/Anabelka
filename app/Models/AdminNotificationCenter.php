<?php

class AdminNotificationCenter
{
    private static $schemaReady = false;
    private static $summaryCache = [];

    private const BADGE_CHANNELS = [
        'orders' => 'Нові замовлення',
        'new_users' => 'Нові користувачі',
        'rank_requests' => 'Запити на підвищення рангу',
        'translations' => 'Переклади потребують уваги',
        'audit' => 'Нові дії в журналі'
    ];

    private const CUSTOM_BADGE_ROLES = [
        'owner',
        'store_owner'
    ];


    public static function ensureSchema()
    {
        if (self::$schemaReady) {
            return;
        }

        $db = Database::connect();

        $db->exec("
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

        $db->exec("
            CREATE TABLE IF NOT EXISTS admin_notification_preferences
            (
                admin_user_id BIGINT UNSIGNED NOT NULL,
                channel VARCHAR(80) NOT NULL,
                is_enabled TINYINT(1) NOT NULL DEFAULT 1,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (admin_user_id, channel)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS admin_audit_read_state
            (
                viewer_admin_user_id INT UNSIGNED NOT NULL,
                audit_log_id BIGINT UNSIGNED NOT NULL,
                viewed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (viewer_admin_user_id, audit_log_id),
                KEY idx_admin_audit_read_log (audit_log_id)
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

        if (AdminAccess::can('orders.manage')) {
            $items[] = self::item(
                'orders',
                self::BADGE_CHANNELS['orders'],
                self::countNewOrders(),
                '/Anabelka/admin/orders',
                'action'
            );
        }

        if (AdminAccess::can('users.manage')) {
            $items[] = self::item(
                'new_users',
                self::BADGE_CHANNELS['new_users'],
                self::countNewUsers($adminUserId),
                '/Anabelka/admin/users',
                'new'
            );

            $items[] = self::item(
                'rank_requests',
                self::BADGE_CHANNELS['rank_requests'],
                self::countPendingRankRequests(),
                '/Anabelka/admin/users',
                'action'
            );
        }

        if (AdminAccess::can('translations.manage')) {
            $items[] = self::item(
                'translations',
                self::BADGE_CHANNELS['translations'],
                self::countTranslationAttention(),
                '/Anabelka/admin/translations',
                'action'
            );
        }

        // Журнал дій — виняток із правила *.manage: це read-only канал,
        // тому для нього достатньо окремого права audit.view.
        if (AdminAccess::can('audit.view')) {
            $items[] = self::item(
                'audit',
                self::BADGE_CHANNELS['audit'],
                self::countNewAuditActions($adminUserId),
                '/Anabelka/admin/audit',
                'new'
            );
        }

        $byKey = [];
        $allTotal = 0;
        $badgeTotal = 0;
        $preferences = self::canCustomizeBadge($admin)
            ? self::preferences($adminUserId)
            : null;

        foreach ($items as $item) {
            $key = (string) ($item['key'] ?? '');
            $count = max(0, (int) ($item['count'] ?? 0));

            if ($key !== '') {
                $byKey[$key] = $count;
            }

            $allTotal += $count;

            if (
                $preferences === null
                || ($key !== '' && !empty($preferences[$key]))
            ) {
                $badgeTotal += $count;
            }
        }

        self::$summaryCache[$adminUserId] = [
            // total — число для загального бейджа з дозволених каналів.
            // Робочі канали вимагають *.manage, журнал дій — audit.view.
            'total' => $badgeTotal,
            // all_total — усі події з дозволених поточній ролі каналів.
            'all_total' => $allTotal,
            // items/by_key завжди повні: локальні лічильники не фільтруємо.
            'items' => $items,
            'by_key' => $byKey
        ];

        return self::$summaryCache[$adminUserId];
    }


    public static function badgeSummary($adminUserId = null)
    {
        $summary = self::summary($adminUserId);
        $admin = class_exists('AdminAccess')
            ? AdminAccess::current()
            : null;

        if (!$admin || !self::canCustomizeBadge($admin)) {
            return $summary;
        }

        $adminUserId = $adminUserId !== null
            ? (int) $adminUserId
            : (int) ($admin['id'] ?? AdminAccess::currentId());

        $preferences = self::preferences($adminUserId);
        $items = [];
        $byKey = [];
        $total = 0;

        foreach ($summary['items'] ?? [] as $item) {
            $key = (string) ($item['key'] ?? '');

            if ($key === '' || empty($preferences[$key])) {
                continue;
            }

            $count = max(0, (int) ($item['count'] ?? 0));
            $items[] = $item;
            $byKey[$key] = $count;
            $total += $count;
        }

        return [
            'total' => $total,
            'all_total' => (int) ($summary['all_total'] ?? $total),
            'items' => $items,
            'by_key' => $byKey
        ];
    }


    public static function canCustomizeBadge($admin = null)
    {
        if (!class_exists('AdminAccess')) {
            return false;
        }

        $admin = is_array($admin)
            ? $admin
            : AdminAccess::current();

        if (!$admin) {
            return false;
        }

        return in_array(
            (string) ($admin['role_slug'] ?? ''),
            self::CUSTOM_BADGE_ROLES,
            true
        );
    }


    public static function badgeOptions($adminUserId = null)
    {
        $admin = class_exists('AdminAccess')
            ? AdminAccess::current()
            : null;

        if (!$admin || !self::canCustomizeBadge($admin)) {
            return [];
        }

        $adminUserId = $adminUserId !== null
            ? (int) $adminUserId
            : (int) ($admin['id'] ?? AdminAccess::currentId());
        $preferences = self::preferences($adminUserId);
        $options = [];

        foreach (self::BADGE_CHANNELS as $key => $label) {
            if (!self::channelAllowedByPermissions($key)) {
                continue;
            }

            $options[] = [
                'key' => $key,
                'label' => $label,
                'enabled' => !empty($preferences[$key])
            ];
        }

        return $options;
    }


    public static function saveBadgePreferences(array $enabledChannels)
    {
        if (!class_exists('AdminAccess')) {
            throw new RuntimeException('Адміністратора не знайдено.');
        }

        $admin = AdminAccess::current();

        if (!$admin || !self::canCustomizeBadge($admin)) {
            throw new RuntimeException(
                'Персональний склад бейджа доступний лише Розробнику та Власнику.'
            );
        }

        $adminUserId = (int) ($admin['id'] ?? AdminAccess::currentId());

        if ($adminUserId <= 0) {
            throw new RuntimeException('Адміністратора не знайдено.');
        }

        $enabledLookup = [];
        foreach ($enabledChannels as $channel) {
            $channel = trim((string) $channel);
            if (array_key_exists($channel, self::BADGE_CHANNELS)) {
                $enabledLookup[$channel] = true;
            }
        }

        self::ensureSchema();
        $db = Database::connect();
        $stmt = $db->prepare("
            INSERT INTO admin_notification_preferences
                (admin_user_id, channel, is_enabled)
            VALUES
                (:admin_user_id, :channel, :is_enabled)
            ON DUPLICATE KEY UPDATE
                is_enabled = VALUES(is_enabled),
                updated_at = CURRENT_TIMESTAMP
        ");

        foreach (array_keys(self::BADGE_CHANNELS) as $channel) {
            $stmt->execute([
                'admin_user_id' => $adminUserId,
                'channel' => $channel,
                'is_enabled' => isset($enabledLookup[$channel]) ? 1 : 0
            ]);
        }

        unset(self::$summaryCache[$adminUserId]);

        return self::badgeOptions($adminUserId);
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

        if (!class_exists('AdminAccess') || !AdminAccess::can('users.manage')) {
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


    private static function preferences($adminUserId)
    {
        $defaults = [];
        foreach (array_keys(self::BADGE_CHANNELS) as $channel) {
            $defaults[$channel] = true;
        }

        $adminUserId = (int) $adminUserId;

        if ($adminUserId <= 0) {
            return $defaults;
        }

        self::ensureSchema();
        $stmt = Database::connect()->prepare("
            SELECT channel, is_enabled
            FROM admin_notification_preferences
            WHERE admin_user_id = :admin_user_id
        ");
        $stmt->execute(['admin_user_id' => $adminUserId]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $channel = (string) ($row['channel'] ?? '');
            if (array_key_exists($channel, $defaults)) {
                $defaults[$channel] = !empty($row['is_enabled']);
            }
        }

        return $defaults;
    }


    private static function channelAllowedByPermissions($channel)
    {
        switch ((string) $channel) {
            case 'orders':
                return AdminAccess::can('orders.manage');

            case 'new_users':
            case 'rank_requests':
                return AdminAccess::can('users.manage');

            case 'translations':
                return AdminAccess::can('translations.manage');

            case 'audit':
                return AdminAccess::can('audit.view');
        }

        return false;
    }


    public static function auditUnreadState($adminUserId = null)
    {
        if (
            !class_exists('AdminAccess')
            || !AdminAccess::can('audit.view')
        ) {
            return self::emptyAuditUnreadState();
        }

        $adminUserId = $adminUserId !== null
            ? (int) $adminUserId
            : AdminAccess::currentId();

        if ($adminUserId <= 0) {
            return self::emptyAuditUnreadState();
        }

        self::ensureSchema();
        $maxId = self::currentMaxAuditId();

        // The audit cursor is only a baseline. On the first use it is set
        // to the current maximum so old history does not become "new".
        $baseline = self::cursor(
            $adminUserId,
            'audit',
            $maxId
        );

        if ($maxId <= $baseline) {
            return [
                'total' => 0,
                'cursor' => $baseline,
                'max_id' => $maxId,
                'by_actor' => []
            ];
        }

        $stmt = Database::connect()->prepare("
            SELECT
                l.admin_user_id,
                au.name AS admin_name,
                au.email AS admin_email,
                COUNT(*) AS new_count
            FROM admin_audit_log l
            LEFT JOIN admin_users au
                ON au.id = l.admin_user_id
            LEFT JOIN admin_audit_read_state rs
                ON rs.audit_log_id = l.id
               AND rs.viewer_admin_user_id = :viewer_admin_user_id
            WHERE l.id > :baseline
              AND l.id <= :max_id
              AND rs.audit_log_id IS NULL
            GROUP BY
                l.admin_user_id,
                au.name,
                au.email
            ORDER BY MAX(l.id) DESC
        ");
        $stmt->execute([
            'viewer_admin_user_id' => $adminUserId,
            'baseline' => $baseline,
            'max_id' => $maxId
        ]);

        $total = 0;
        $byActor = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $actorId = (int) ($row['admin_user_id'] ?? 0);
            $key = $actorId > 0
                ? 'admin-' . $actorId
                : 'system';
            $count = max(0, (int) ($row['new_count'] ?? 0));

            $byActor[$key] = [
                'admin_id' => $actorId,
                'name' => trim((string) ($row['admin_name'] ?? '')),
                'email' => trim((string) ($row['admin_email'] ?? '')),
                'count' => $count
            ];
            $total += $count;
        }

        return [
            'total' => $total,
            'cursor' => $baseline,
            'max_id' => $maxId,
            'by_actor' => $byActor
        ];
    }


    public static function auditUnreadEntryIds(
        array $auditLogIds,
        $adminUserId = null
    ) {
        if (
            !class_exists('AdminAccess')
            || !AdminAccess::can('audit.view')
        ) {
            return [];
        }

        $adminUserId = $adminUserId !== null
            ? (int) $adminUserId
            : AdminAccess::currentId();

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $auditLogIds),
            static function ($id) {
                return $id > 0;
            }
        )));

        if ($adminUserId <= 0 || empty($ids)) {
            return [];
        }

        self::ensureSchema();
        $baseline = self::cursor(
            $adminUserId,
            'audit',
            self::currentMaxAuditId()
        );
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge(
            [$adminUserId, $baseline],
            $ids
        );

        $stmt = Database::connect()->prepare("
            SELECT l.id
            FROM admin_audit_log l
            LEFT JOIN admin_audit_read_state rs
                ON rs.audit_log_id = l.id
               AND rs.viewer_admin_user_id = ?
            WHERE l.id > ?
              AND l.id IN ({$placeholders})
              AND rs.audit_log_id IS NULL
        ");
        $stmt->execute($params);

        return array_map(
            'intval',
            $stmt->fetchAll(PDO::FETCH_COLUMN)
        );
    }


    public static function markAuditEntrySeen(
        $auditLogId,
        $adminUserId = null
    ) {
        if (
            !class_exists('AdminAccess')
            || !AdminAccess::can('audit.view')
        ) {
            throw new RuntimeException(
                'Недостатньо прав для перегляду журналу дій.'
            );
        }

        $auditLogId = (int) $auditLogId;
        $adminUserId = $adminUserId !== null
            ? (int) $adminUserId
            : AdminAccess::currentId();

        if ($auditLogId <= 0 || $adminUserId <= 0) {
            throw new InvalidArgumentException(
                'Некоректний запис журналу.'
            );
        }

        self::ensureSchema();
        $db = Database::connect();

        $entry = $db->prepare("
            SELECT id, admin_user_id
            FROM admin_audit_log
            WHERE id = :id
            LIMIT 1
        ");
        $entry->execute(['id' => $auditLogId]);
        $row = $entry->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException(
                'Запис журналу дій не знайдено.'
            );
        }

        $baseline = self::cursor(
            $adminUserId,
            'audit',
            self::currentMaxAuditId()
        );

        if ($auditLogId > $baseline) {
            $insert = $db->prepare("
                INSERT IGNORE INTO admin_audit_read_state
                    (viewer_admin_user_id, audit_log_id)
                VALUES
                    (:viewer_admin_user_id, :audit_log_id)
            ");
            $insert->execute([
                'viewer_admin_user_id' => $adminUserId,
                'audit_log_id' => $auditLogId
            ]);
        }

        unset(self::$summaryCache[$adminUserId]);

        $state = self::auditUnreadState($adminUserId);
        $actorId = (int) ($row['admin_user_id'] ?? 0);
        $actorKey = $actorId > 0
            ? 'admin-' . $actorId
            : 'system';

        return [
            'audit_log_id' => $auditLogId,
            'actor_key' => $actorKey,
            'actor_remaining' => max(
                0,
                (int) ($state['by_actor'][$actorKey]['count'] ?? 0)
            ),
            'remaining_total' => max(
                0,
                (int) ($state['total'] ?? 0)
            )
        ];
    }


    public static function markAuditAllSeen($adminUserId = null)
    {
        if (!self::canClearAuditUnread()) {
            throw new RuntimeException(
                'Обнулити всі нові дії може лише Розробник або Власник.'
            );
        }

        $adminUserId = $adminUserId !== null
            ? (int) $adminUserId
            : AdminAccess::currentId();

        if ($adminUserId <= 0) {
            throw new RuntimeException(
                'Адміністратора не знайдено.'
            );
        }

        self::ensureSchema();
        $maxId = self::currentMaxAuditId();
        self::saveCursor(
            $adminUserId,
            'audit',
            $maxId
        );

        $cleanup = Database::connect()->prepare("
            DELETE FROM admin_audit_read_state
            WHERE viewer_admin_user_id = :viewer_admin_user_id
              AND audit_log_id <= :max_id
        ");
        $cleanup->execute([
            'viewer_admin_user_id' => $adminUserId,
            'max_id' => $maxId
        ]);

        unset(self::$summaryCache[$adminUserId]);

        return $maxId;
    }


    public static function canClearAuditUnread($admin = null)
    {
        if (!class_exists('AdminAccess')) {
            return false;
        }

        $admin = is_array($admin)
            ? $admin
            : AdminAccess::current();

        if (!$admin) {
            return false;
        }

        return in_array(
            (string) ($admin['role_slug'] ?? ''),
            ['owner', 'store_owner'],
            true
        );
    }


    private static function countNewAuditActions($adminUserId)
    {
        $state = self::auditUnreadState((int) $adminUserId);

        return max(0, (int) ($state['total'] ?? 0));
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

        if (AdminAccess::can('products.manage')) {
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

        if (AdminAccess::can('categories.manage')) {
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

        if (AdminAccess::can('delivery.manage')) {
            $total += self::deliveryTranslationAttention();
        }

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


    private static function currentMaxAuditId()
    {
        return self::safeCount(
            'SELECT COALESCE(MAX(id), 0) FROM admin_audit_log'
        );
    }


    private static function emptyAuditUnreadState()
    {
        return [
            'total' => 0,
            'cursor' => 0,
            'max_id' => 0,
            'by_actor' => []
        ];
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
            'all_total' => 0,
            'items' => [],
            'by_key' => []
        ];
    }
}
