<?php

class AppSetting
{
    private static $schemaReady = false;
    private static $schemaInitializationSuspended = 0;


    private static function ensureTable()
    {
        if (self::$schemaReady || self::$schemaInitializationSuspended > 0) {
            return;
        }

        $db = Database::connect();

        $db->exec("
            CREATE TABLE IF NOT EXISTS app_settings
            (
                setting_key VARCHAR(100) NOT NULL,
                setting_value VARCHAR(255) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (setting_key)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        self::$schemaReady = true;
    }


    public static function withoutSchemaInitialization(callable $operation)
    {
        self::$schemaInitializationSuspended++;
        try {
            return $operation();
        } finally {
            self::$schemaInitializationSuspended--;
        }
    }


    public static function get($key, $default = null)
    {
        self::ensureTable();

        return self::getExisting($key, $default);
    }


    // Emergency readers must never initialize or repair the database schema.
    public static function getExisting($key, $default = null)
    {
        $key = trim((string) $key);

        if ($key === '') {
            return $default;
        }

        $db = Database::connect();

        $stmt = $db->prepare("
            SELECT setting_value
            FROM app_settings
            WHERE setting_key = :setting_key
            LIMIT 1
        ");

        $stmt->execute([
            'setting_key' => $key
        ]);

        $value = $stmt->fetchColumn();

        return $value === false ? $default : $value;
    }


    public static function set($key, $value)
    {
        self::ensureTable();

        return self::setExisting($key, $value);
    }


    public static function setExisting($key, $value)
    {
        $key = trim((string) $key);
        $value = trim((string) $value);

        if ($key === '' || strlen($key) > 100) {
            throw new InvalidArgumentException(
                'Некоректний ключ налаштування.'
            );
        }

        if (strlen($value) > 255) {
            throw new InvalidArgumentException(
                'Значення налаштування занадто довге.'
            );
        }

        $db = Database::connect();

        $stmt = $db->prepare("
            INSERT INTO app_settings
            (
                setting_key,
                setting_value
            )
            VALUES
            (
                :setting_key,
                :setting_value
            )
            ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value)
        ");

        return $stmt->execute([
            'setting_key' => $key,
            'setting_value' => $value
        ]);
    }
}
