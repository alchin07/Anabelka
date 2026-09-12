<?php

class SystemErrorStatus
{
    private static $schemaReady = false;

    private const STORED_STATUSES = [
        'viewed',
        'resolved',
        'ignored'
    ];


    public static function ensureSchema()
    {
        if (self::$schemaReady) {
            return;
        }

        Database::connect()->exec("
            CREATE TABLE IF NOT EXISTS admin_system_error_status
            (
                reference VARCHAR(80) NOT NULL,
                status VARCHAR(20) NOT NULL,
                updated_by_admin_id BIGINT UNSIGNED NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (reference),
                KEY idx_system_error_status (status),
                KEY idx_system_error_status_updated (updated_at)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        self::$schemaReady = true;
    }


    public static function decorateItems(array $items)
    {
        if (empty($items)) {
            return [];
        }

        $references = [];

        foreach ($items as $item) {
            $reference = trim((string) ($item['reference'] ?? ''));

            if (self::validReference($reference)) {
                $references[$reference] = true;
            }
        }

        $states = self::statesForReferences(array_keys($references));

        foreach ($items as &$item) {
            $reference = trim((string) ($item['reference'] ?? ''));
            $state = $states[$reference] ?? null;

            $item['workflow_status'] = is_array($state)
                ? (string) ($state['status'] ?? 'viewed')
                : 'new';
            $item['workflow_updated_at'] = is_array($state)
                ? (string) ($state['updated_at'] ?? '')
                : '';
            $item['workflow_updated_by'] = is_array($state)
                ? (int) ($state['updated_by_admin_id'] ?? 0)
                : 0;
        }
        unset($item);

        return $items;
    }


    public static function markViewed($reference, $adminUserId)
    {
        $reference = trim((string) $reference);
        $adminUserId = (int) $adminUserId;

        if (!self::validReference($reference)) {
            throw new InvalidArgumentException('Некоректний код системної помилки.');
        }

        self::ensureSchema();

        // Відсутній запис означає статус "new". INSERT IGNORE переводить
        // тільки нову помилку в "viewed" і ніколи не понижує resolved/ignored.
        $stmt = Database::connect()->prepare("
            INSERT IGNORE INTO admin_system_error_status
                (reference, status, updated_by_admin_id)
            VALUES
                (:reference, 'viewed', :updated_by_admin_id)
        ");
        $stmt->execute([
            'reference' => $reference,
            'updated_by_admin_id' => $adminUserId > 0 ? $adminUserId : null
        ]);
    }


    public static function setStatus($reference, $status, $adminUserId)
    {
        $reference = trim((string) $reference);
        $status = strtolower(trim((string) $status));
        $adminUserId = (int) $adminUserId;

        if (!self::validReference($reference)) {
            throw new InvalidArgumentException('Некоректний код системної помилки.');
        }

        if (!in_array($status, self::STORED_STATUSES, true)) {
            throw new InvalidArgumentException('Некоректний статус системної помилки.');
        }

        self::ensureSchema();

        $stmt = Database::connect()->prepare("
            INSERT INTO admin_system_error_status
                (reference, status, updated_by_admin_id)
            VALUES
                (:reference, :status, :updated_by_admin_id)
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                updated_by_admin_id = VALUES(updated_by_admin_id),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            'reference' => $reference,
            'status' => $status,
            'updated_by_admin_id' => $adminUserId > 0 ? $adminUserId : null
        ]);
    }


    public static function summary(array $items)
    {
        $summary = [
            'new' => 0,
            'viewed' => 0,
            'resolved' => 0,
            'ignored' => 0
        ];

        foreach ($items as $item) {
            $status = strtolower((string) ($item['workflow_status'] ?? 'new'));

            if (array_key_exists($status, $summary)) {
                $summary[$status]++;
            }
        }

        return $summary;
    }


    public static function validFilterStatus($status)
    {
        $status = strtolower(trim((string) $status));

        return in_array(
            $status,
            ['all', 'new', 'viewed', 'resolved', 'ignored'],
            true
        );
    }


    private static function statesForReferences(array $references)
    {
        $references = array_values(array_unique(array_filter(
            array_map('strval', $references),
            [self::class, 'validReference']
        )));

        if (empty($references)) {
            return [];
        }

        self::ensureSchema();
        $placeholders = implode(',', array_fill(0, count($references), '?'));
        $stmt = Database::connect()->prepare("
            SELECT reference, status, updated_by_admin_id, updated_at
            FROM admin_system_error_status
            WHERE reference IN ({$placeholders})
        ");
        $stmt->execute($references);

        $states = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $reference = (string) ($row['reference'] ?? '');

            if ($reference !== '') {
                $states[$reference] = $row;
            }
        }

        return $states;
    }


    private static function validReference($reference)
    {
        return is_string($reference)
            && preg_match('/^ERR-[A-Z0-9-]{8,80}$/i', $reference) === 1;
    }
}
