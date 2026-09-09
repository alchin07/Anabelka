<?php

class CustomerNotification
{
    private static $schemaReady = false;


    public static function ensureSchema()
    {
        if (self::$schemaReady) {
            return;
        }

        $db = Database::connect();
        $db->exec("
            CREATE TABLE IF NOT EXISTS customer_notifications
            (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                type VARCHAR(80) NOT NULL,
                entity_type VARCHAR(80) NULL,
                entity_id BIGINT UNSIGNED NULL,
                data_json TEXT NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                read_at DATETIME NULL,
                PRIMARY KEY (id),
                KEY idx_customer_notification_user (user_id, id),
                KEY idx_customer_notification_unread (user_id, is_read, id),
                UNIQUE KEY uq_customer_notification_event
                    (user_id, type, entity_type, entity_id)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        $db->exec("
            DELETE n
            FROM customer_notifications n
            LEFT JOIN users u ON u.id = n.user_id
            WHERE u.id IS NULL
        ");

        self::$schemaReady = true;
    }


    public static function create(
        $userId,
        $type,
        array $data = [],
        $entityType = null,
        $entityId = null
    ) {
        self::ensureSchema();

        $userId = (int) $userId;
        $type = trim((string) $type);
        $entityType = trim((string) $entityType);
        $entityId = $entityId !== null ? (int) $entityId : null;

        if ($userId <= 0 || $type === '') {
            throw new InvalidArgumentException('Некоректні дані сповіщення.');
        }

        $encoded = !empty($data)
            ? json_encode(
                $data,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )
            : null;

        $stmt = Database::connect()->prepare("
            INSERT INTO customer_notifications
            (user_id, type, entity_type, entity_id, data_json)
            VALUES
            (:user_id, :type, :entity_type, :entity_id, :data_json)
            ON DUPLICATE KEY UPDATE
                data_json = VALUES(data_json),
                is_read = 0,
                read_at = NULL,
                created_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            'user_id' => $userId,
            'type' => $type,
            'entity_type' => $entityType !== '' ? $entityType : null,
            'entity_id' => $entityId !== null && $entityId > 0 ? $entityId : null,
            'data_json' => $encoded !== false ? $encoded : null
        ]);
    }


    public static function recentForUser($userId, $limit = 10)
    {
        self::ensureSchema();

        $userId = (int) $userId;
        $limit = max(1, min(30, (int) $limit));

        if ($userId <= 0) {
            return [];
        }

        $stmt = Database::connect()->prepare("
            SELECT
                id,
                type,
                entity_type,
                entity_id,
                data_json,
                is_read,
                created_at,
                read_at
            FROM customer_notifications
            WHERE user_id = :user_id
            ORDER BY id DESC
            LIMIT {$limit}
        ");
        $stmt->execute(['user_id' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $data = json_decode((string) ($row['data_json'] ?? ''), true);
            $row['data'] = is_array($data) ? $data : [];
            unset($row['data_json']);
        }
        unset($row);

        return $rows;
    }


    public static function unreadCount($userId)
    {
        self::ensureSchema();
        $stmt = Database::connect()->prepare("
            SELECT COUNT(*)
            FROM customer_notifications
            WHERE user_id = :user_id
              AND is_read = 0
        ");
        $stmt->execute(['user_id' => (int) $userId]);

        return (int) $stmt->fetchColumn();
    }


    public static function markRead($userId, array $notificationIds)
    {
        self::ensureSchema();
        $userId = (int) $userId;
        $notificationIds = array_values(array_unique(array_filter(
            array_map('intval', $notificationIds),
            function ($id) {
                return $id > 0;
            }
        )));

        if ($userId <= 0 || empty($notificationIds)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($notificationIds), '?'));
        $params = array_merge([$userId], $notificationIds);
        $stmt = Database::connect()->prepare("
            UPDATE customer_notifications
            SET is_read = 1,
                read_at = COALESCE(read_at, NOW())
            WHERE user_id = ?
              AND id IN ({$placeholders})
              AND is_read = 0
        ");
        $stmt->execute($params);
    }


    public static function deleteForUser($userId)
    {
        self::ensureSchema();
        $stmt = Database::connect()->prepare("
            DELETE FROM customer_notifications
            WHERE user_id = :user_id
        ");
        $stmt->execute(['user_id' => (int) $userId]);
    }
}
