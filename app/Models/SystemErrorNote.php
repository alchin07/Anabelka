<?php

class SystemErrorNote
{
    private static $schemaReady = false;


    public static function ensureSchema()
    {
        if (self::$schemaReady) {
            return;
        }

        Database::connect()->exec("
            CREATE TABLE IF NOT EXISTS admin_system_error_notes
            (
                group_key VARCHAR(40) NOT NULL,
                note TEXT NOT NULL,
                updated_by_admin_id BIGINT UNSIGNED NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (group_key),
                KEY idx_system_error_note_updated (updated_at)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        self::$schemaReady = true;
    }


    public static function find($groupKey)
    {
        $groupKey = trim((string) $groupKey);

        if (!self::validGroupKey($groupKey)) {
            return null;
        }

        self::ensureSchema();
        $stmt = Database::connect()->prepare("
            SELECT group_key, note, updated_by_admin_id, created_at, updated_at
            FROM admin_system_error_notes
            WHERE group_key = :group_key
            LIMIT 1
        ");
        $stmt->execute(['group_key' => $groupKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }


    public static function save($groupKey, $note, $adminUserId)
    {
        $groupKey = trim((string) $groupKey);
        $note = trim((string) $note);
        $adminUserId = (int) $adminUserId;

        if (!self::validGroupKey($groupKey)) {
            throw new InvalidArgumentException('Некоректна група системної помилки.');
        }

        if (function_exists('mb_strlen')) {
            if (mb_strlen($note, 'UTF-8') > 4000) {
                throw new InvalidArgumentException('Нотатка не може бути довшою за 4000 символів.');
            }
        } elseif (strlen($note) > 12000) {
            throw new InvalidArgumentException('Нотатка занадто довга.');
        }

        self::ensureSchema();

        if ($note === '') {
            $stmt = Database::connect()->prepare("
                DELETE FROM admin_system_error_notes
                WHERE group_key = :group_key
            ");
            $stmt->execute(['group_key' => $groupKey]);
            return 'deleted';
        }

        $stmt = Database::connect()->prepare("
            INSERT INTO admin_system_error_notes
                (group_key, note, updated_by_admin_id)
            VALUES
                (:group_key, :note, :updated_by_admin_id)
            ON DUPLICATE KEY UPDATE
                note = VALUES(note),
                updated_by_admin_id = VALUES(updated_by_admin_id),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            'group_key' => $groupKey,
            'note' => $note,
            'updated_by_admin_id' => $adminUserId > 0 ? $adminUserId : null
        ]);

        return 'saved';
    }


    public static function decorateGroups(array $groups)
    {
        if (empty($groups)) {
            return [];
        }

        $keys = [];

        foreach ($groups as $group) {
            $key = trim((string) ($group['group_key'] ?? ''));

            if (self::validGroupKey($key)) {
                $keys[$key] = true;
            }
        }

        $notes = self::notesForKeys(array_keys($keys));

        foreach ($groups as &$group) {
            $key = trim((string) ($group['group_key'] ?? ''));
            $row = $notes[$key] ?? null;
            $group['developer_note'] = is_array($row)
                ? (string) ($row['note'] ?? '')
                : '';
            $group['developer_note_updated_at'] = is_array($row)
                ? (string) ($row['updated_at'] ?? '')
                : '';
            $group['developer_note_updated_by'] = is_array($row)
                ? (int) ($row['updated_by_admin_id'] ?? 0)
                : 0;
        }
        unset($group);

        return $groups;
    }


    public static function validGroupKey($groupKey)
    {
        return is_string($groupKey)
            && preg_match('/^SEG-[A-F0-9]{24}$/i', $groupKey) === 1;
    }


    private static function notesForKeys(array $keys)
    {
        $keys = array_values(array_unique(array_filter(
            array_map('strval', $keys),
            [self::class, 'validGroupKey']
        )));

        if (empty($keys)) {
            return [];
        }

        self::ensureSchema();
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = Database::connect()->prepare("
            SELECT group_key, note, updated_by_admin_id, updated_at
            FROM admin_system_error_notes
            WHERE group_key IN ({$placeholders})
        ");
        $stmt->execute($keys);

        $notes = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string) ($row['group_key'] ?? '');

            if ($key !== '') {
                $notes[$key] = $row;
            }
        }

        return $notes;
    }
}
