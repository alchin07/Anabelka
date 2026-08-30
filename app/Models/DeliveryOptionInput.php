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

        $db->exec("
            CREATE TABLE IF NOT EXISTS delivery_option_input_translations
            (
                option_id INT NOT NULL,
                language_code VARCHAR(10) NOT NULL,
                field_label VARCHAR(120) NULL,
                placeholder VARCHAR(160) NULL,
                source VARCHAR(20) NOT NULL DEFAULT 'manual',
                updated_at TIMESTAMP NOT NULL
                    DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (option_id, language_code),
                KEY idx_delivery_option_input_language (language_code)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        self::$schemaReady = true;
    }


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


    public static function getTranslationsForOption($optionId)
    {
        self::ensureTable();

        $optionId = (int) $optionId;

        if ($optionId <= 0) {
            return [];
        }

        $db = Database::connect();

        $stmt = $db->prepare("
            SELECT
                language_code,
                field_label,
                placeholder,
                source
            FROM delivery_option_input_translations
            WHERE option_id = :option_id
        ");

        $stmt->execute([
            'option_id' => $optionId
        ]);

        $result = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[$row['language_code']] = $row;
        }

        return $result;
    }


    public static function saveTranslation(
        $optionId,
        $languageCode,
        $fieldLabel,
        $placeholder,
        $source = 'manual'
    ) {
        self::ensureTable();

        $optionId = (int) $optionId;
        $languageCode = strtolower(trim((string) $languageCode));
        $fieldLabel = trim((string) $fieldLabel);
        $placeholder = trim((string) $placeholder);

        if ($optionId <= 0 || $languageCode === '') {
            throw new InvalidArgumentException(
                'Некорректные данные перевода поля.'
            );
        }

        if ($languageCode === Language::SOURCE_CODE) {
            return true;
        }

        $language = Language::findByCode($languageCode);

        if (!$language || empty($language['is_active'])) {
            throw new InvalidArgumentException('Язык недоступен.');
        }

        $db = Database::connect();

        if ($fieldLabel === '' && $placeholder === '') {
            $stmt = $db->prepare("
                DELETE FROM delivery_option_input_translations
                WHERE option_id = :option_id
                  AND language_code = :language_code
            ");

            return $stmt->execute([
                'option_id' => $optionId,
                'language_code' => $languageCode
            ]);
        }

        $stmt = $db->prepare("
            INSERT INTO delivery_option_input_translations
            (
                option_id,
                language_code,
                field_label,
                placeholder,
                source
            )
            VALUES
            (
                :option_id,
                :language_code,
                :field_label,
                :placeholder,
                :source
            )
            ON DUPLICATE KEY UPDATE
                field_label = VALUES(field_label),
                placeholder = VALUES(placeholder),
                source = VALUES(source)
        ");

        return $stmt->execute([
            'option_id' => $optionId,
            'language_code' => $languageCode,
            'field_label' =>
                $fieldLabel !== '' ? $fieldLabel : null,
            'placeholder' =>
                $placeholder !== '' ? $placeholder : null,
            'source' => trim((string) $source) ?: 'manual'
        ]);
    }


    private static function applyStoredTranslation(array $row)
    {
        if (!class_exists('Translator')) {
            return $row;
        }

        $language = Translator::currentLanguage();
        $code = (string) ($language['code'] ?? Language::SOURCE_CODE);

        if ($code === Language::SOURCE_CODE) {
            return $row;
        }

        $db = Database::connect();

        $stmt = $db->prepare("
            SELECT
                field_label,
                placeholder
            FROM delivery_option_input_translations
            WHERE option_id = :option_id
              AND language_code = :language_code
            LIMIT 1
        ");

        $stmt->execute([
            'option_id' => (int) ($row['option_id'] ?? 0),
            'language_code' => $code
        ]);

        $translation = $stmt->fetch(PDO::FETCH_ASSOC);

        // Нет перевода — оставляем украинский оригинал.
        if (!$translation) {
            return $row;
        }

        if (trim((string) ($translation['field_label'] ?? '')) !== '') {
            $row['field_label'] = $translation['field_label'];
        }

        if (trim((string) ($translation['placeholder'] ?? '')) !== '') {
            $row['placeholder'] = $translation['placeholder'];
        }

        return $row;
    }


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

            return self::applyStoredTranslation($row);

        } catch (PDOException $e) {
            return self::defaults();
        }
    }


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
            $fieldLabel = 'Вкажіть дані доставки';
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
