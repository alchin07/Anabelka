<?php

class DeliveryOptionInput
{
    private static $schemaReady = false;


    /**
     * Создаём таблицу настроек при первом обращении.
     *
     * В проекте пока нет отдельной системы миграций,
     * поэтому этот небольшой служебный слой делает
     * изменение БД безопасно через IF NOT EXISTS.
     */
    private static function ensureTable()
    {
        if (self::$schemaReady) {
            return;
        }

        $db = Database::connect();

        $db->exec("
            CREATE TABLE IF NOT EXISTS delivery_option_inputs
            (
                option_id INT NOT NULL,
                is_enabled TINYINT(1) NOT NULL DEFAULT 0,
                field_label VARCHAR(120) NULL,
                placeholder VARCHAR(160) NULL,
                updated_at TIMESTAMP NOT NULL
                    DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (option_id)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        self::$schemaReady = true;
    }


    /**
     * Настройки дополнительного поля покупателя.
     */
    public static function getForOption($optionId)
    {
        self::ensureTable();

        $db = Database::connect();

        $stmt = $db->prepare("
            SELECT
                option_id,
                is_enabled,
                field_label,
                placeholder
            FROM delivery_option_inputs
            WHERE option_id = :option_id
            LIMIT 1
        ");

        $stmt->execute([
            'option_id' => (int) $optionId
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return [
                'option_id' => (int) $optionId,
                'is_enabled' => 0,
                'field_label' => '',
                'placeholder' => ''
            ];
        }

        $row['is_enabled'] =
            !empty($row['is_enabled']) ? 1 : 0;

        return $row;
    }


    /**
     * Создать или обновить настройки опции.
     */
    public static function save(
        $optionId,
        $isEnabled,
        $fieldLabel,
        $placeholder
    ) {
        self::ensureTable();

        $db = Database::connect();

        $fieldLabel = trim((string) $fieldLabel);
        $placeholder = trim((string) $placeholder);

        if ($isEnabled && $fieldLabel === '') {
            $fieldLabel = 'Укажите данные доставки';
        }

        $stmt = $db->prepare("
            INSERT INTO delivery_option_inputs
            (
                option_id,
                is_enabled,
                field_label,
                placeholder
            )
            VALUES
            (
                :option_id,
                :is_enabled,
                :field_label,
                :placeholder
            )
            ON DUPLICATE KEY UPDATE
                is_enabled = VALUES(is_enabled),
                field_label = VALUES(field_label),
                placeholder = VALUES(placeholder)
        ");

        return $stmt->execute([
            'option_id' => (int) $optionId,
            'is_enabled' => $isEnabled ? 1 : 0,
            'field_label' =>
                $fieldLabel !== '' ? $fieldLabel : null,
            'placeholder' =>
                $placeholder !== '' ? $placeholder : null
        ]);
    }
}
