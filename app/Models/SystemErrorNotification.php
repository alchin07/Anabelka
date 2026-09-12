<?php

class SystemErrorNotification
{
    private static $schemaReady = false;


    public static function ensureSchema()
    {
        if (self::$schemaReady) {
            return;
        }

        Database::connect()->exec("
            CREATE TABLE IF NOT EXISTS admin_system_error_notification_state
            (
                admin_user_id BIGINT UNSIGNED NOT NULL,
                last_seen_reference VARCHAR(80) NOT NULL DEFAULT '',
                last_seen_time BIGINT UNSIGNED NOT NULL DEFAULT 0,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (admin_user_id)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        self::$schemaReady = true;
    }


    public static function unreadCount($adminUserId)
    {
        $adminUserId = (int) $adminUserId;

        if ($adminUserId <= 0) {
            return 0;
        }

        self::ensureSchema();
        $state = self::state($adminUserId);

        // Перший запуск каналу не повинен перетворювати старий журнал
        // на сотні "нових" сповіщень. Поточну позицію приймаємо за прочитану.
        if ($state === null) {
            self::markSeen($adminUserId);
            return 0;
        }

        $seenReference = (string) ($state['last_seen_reference'] ?? '');
        $seenTime = max(0, (int) ($state['last_seen_time'] ?? 0));
        $items = self::seriousItems();
        $count = 0;
        $foundReference = false;

        foreach ($items as $item) {
            $reference = (string) ($item['reference'] ?? '');

            if ($seenReference !== '' && $reference === $seenReference) {
                $foundReference = true;
                break;
            }

            $itemTime = strtotime((string) ($item['time'] ?? '')) ?: 0;

            if ($seenReference !== '') {
                // Поки не зустріли останній переглянутий запис, усі записи
                // перед ним є новішими. Це коректно працює навіть коли
                // кілька помилок з'явилися в одну секунду.
                $count++;
                continue;
            }

            if ($itemTime > $seenTime) {
                $count++;
            }
        }

        if ($seenReference !== '' && !$foundReference) {
            // Якщо старий файл журналу вже видалено, використовуємо час як
            // запасний курсор, щоб не показувати весь архів як новий.
            $count = 0;
            foreach ($items as $item) {
                $itemTime = strtotime((string) ($item['time'] ?? '')) ?: 0;
                if ($itemTime > $seenTime) {
                    $count++;
                }
            }
        }

        return max(0, $count);
    }


    public static function markSeen($adminUserId)
    {
        $adminUserId = (int) $adminUserId;

        if ($adminUserId <= 0) {
            return;
        }

        self::ensureSchema();
        $items = self::seriousItems();
        $latest = $items[0] ?? null;
        $reference = is_array($latest)
            ? (string) ($latest['reference'] ?? '')
            : '';
        $seenTime = is_array($latest)
            ? (strtotime((string) ($latest['time'] ?? '')) ?: time())
            : time();

        $stmt = Database::connect()->prepare("
            INSERT INTO admin_system_error_notification_state
                (admin_user_id, last_seen_reference, last_seen_time)
            VALUES
                (:admin_user_id, :last_seen_reference, :last_seen_time)
            ON DUPLICATE KEY UPDATE
                last_seen_reference = VALUES(last_seen_reference),
                last_seen_time = VALUES(last_seen_time),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            'admin_user_id' => $adminUserId,
            'last_seen_reference' => $reference,
            'last_seen_time' => max(0, (int) $seenTime)
        ]);
    }


    private static function state($adminUserId)
    {
        $stmt = Database::connect()->prepare("
            SELECT last_seen_reference, last_seen_time
            FROM admin_system_error_notification_state
            WHERE admin_user_id = :admin_user_id
            LIMIT 1
        ");
        $stmt->execute(['admin_user_id' => (int) $adminUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }


    private static function seriousItems()
    {
        if (!class_exists('SystemErrorLog')) {
            return [];
        }

        $items = SystemErrorLog::recent([], 300);

        return array_values(array_filter(
            $items,
            static function ($item) {
                $level = strtolower((string) ($item['level'] ?? ''));
                return in_array($level, ['error', 'critical'], true);
            }
        ));
    }
}
