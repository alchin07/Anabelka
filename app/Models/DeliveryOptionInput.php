<?php

class DeliveryOptionInput
{
    private static $schemaReady = false;


    private static function defaults($optionId = 0)
    {
        return [
            'option_id' => (int) $optionId,
            'is_enabled' => 0,
            'field_label' => '',
            'placeholder' => ''
        ];
    }


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
     * Настройки дополнительного поля покупателя
     * по ID опции. Используется в админ-панели.
     */
    public static function getForOption($optionId)
    {
        try {
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
                return self::defaults($optionId);
            }

            $row['is_enabled'] =
                !empty($row['is_enabled']) ? 1 : 0;

            return $row;

        } catch (PDOException $e) {
            return self::defaults($optionId);
        }
    }


    /**
     * Публичные настройки поля для checkout.
     *
     * Настройка возвращается только если способ,
     * служба и опция существуют, активны и действительно
     * принадлежат друг другу.
     */
    public static function getPublicBySelection(
        $methodSlug,
        $serviceSlug,
        $optionSlug
    ) {
        try {
            self::ensureTable();

            $db = Database::connect();

            $stmt = $db->prepare("
                SELECT
                    o.id AS option_id,
                    COALESCE(i.is_enabled, 0) AS is_enabled,
                    COALESCE(i.field_label, '') AS field_label,
                    COALESCE(i.placeholder, '') AS placeholder
                FROM delivery_service_options o
                INNER JOIN delivery_services s
                    ON s.id = o.delivery_service_id
                INNER JOIN delivery_methods m
                    ON m.id = s.delivery_method_id
                LEFT JOIN delivery_option_inputs i
                    ON i.option_id = o.id
                WHERE m.slug = :method_slug
                  AND s.slug = :service_slug
                  AND o.slug = :option_slug
                  AND m.is_active = 1
                  AND s.is_active = 1
                  AND o.is_active = 1
                LIMIT 1
            ");

            $stmt->execute([
                'method_slug' => trim((string) $methodSlug),
                'service_slug' => trim((string) $serviceSlug),
                'option_slug' => trim((string) $optionSlug)
            ]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                return self::defaults();
            }

            $row['option_id'] = (int) $row['option_id'];
            $row['is_enabled'] =
                !empty($row['is_enabled']) ? 1 : 0;

            return $row;

        } catch (PDOException $e) {
            return self::defaults();
        }
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
