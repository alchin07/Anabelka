<?php

class DeliveryTranslator
{
    private static $schemaReady = false;


    private static function ensureTable()
    {
        if (self::$schemaReady) {
            return;
        }

        Language::all();

        $db = Database::connect();

        $db->exec("
            CREATE TABLE IF NOT EXISTS delivery_translations
            (
                entity_type VARCHAR(20) NOT NULL,
                entity_id INT UNSIGNED NOT NULL,
                language_code VARCHAR(10) NOT NULL,
                name VARCHAR(255) NOT NULL,
                description TEXT NULL,
                source VARCHAR(20) NOT NULL DEFAULT 'manual',
                status VARCHAR(20) NOT NULL DEFAULT 'approved',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (
                    entity_type,
                    entity_id,
                    language_code
                ),
                KEY idx_delivery_translations_language (
                    language_code
                ),
                KEY idx_delivery_translations_entity (
                    entity_type,
                    entity_id
                )
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        self::$schemaReady = true;
    }


    private static function tableForType($entityType)
    {
        switch ($entityType) {
            case 'method':
                return 'delivery_methods';

            case 'service':
                return 'delivery_services';

            case 'option':
                return 'delivery_service_options';
        }

        return null;
    }


    public static function getForEntity($entityType, $entityId)
    {
        self::ensureTable();

        $entityType = trim((string) $entityType);
        $entityId = (int) $entityId;

        if (!self::tableForType($entityType) || $entityId <= 0) {
            return [];
        }

        $db = Database::connect();

        $stmt = $db->prepare("
            SELECT
                language_code,
                name,
                description,
                source,
                status
            FROM delivery_translations
            WHERE entity_type = :entity_type
              AND entity_id = :entity_id
        ");

        $stmt->execute([
            'entity_type' => $entityType,
            'entity_id' => $entityId
        ]);

        $result = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[$row['language_code']] = $row;
        }

        return $result;
    }


    public static function saveForEntity(
        $entityType,
        $entityId,
        $languageCode,
        $name,
        $description,
        $source = 'manual'
    ) {
        self::ensureTable();

        $entityType = trim((string) $entityType);
        $entityId = (int) $entityId;
        $languageCode = strtolower(trim((string) $languageCode));
        $name = trim((string) $name);
        $description = trim((string) $description);

        if (
            !self::tableForType($entityType)
            || $entityId <= 0
            || $languageCode === ''
        ) {
            throw new InvalidArgumentException(
                'Некорректные данные перевода.'
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

        if ($name === '' && $description === '') {
            $delete = $db->prepare("
                DELETE FROM delivery_translations
                WHERE entity_type = :entity_type
                  AND entity_id = :entity_id
                  AND language_code = :language_code
            ");

            return $delete->execute([
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'language_code' => $languageCode
            ]);
        }

        if ($name === '') {
            throw new InvalidArgumentException(
                'Если перевод заполнен, название обязательно.'
            );
        }

        $stmt = $db->prepare("
            INSERT INTO delivery_translations
            (
                entity_type,
                entity_id,
                language_code,
                name,
                description,
                source,
                status
            )
            VALUES
            (
                :entity_type,
                :entity_id,
                :language_code,
                :name,
                :description,
                :source,
                'approved'
            )
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                description = VALUES(description),
                source = VALUES(source),
                status = 'approved'
        ");

        return $stmt->execute([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'language_code' => $languageCode,
            'name' => $name,
            'description' =>
                $description !== '' ? $description : null,
            'source' => trim((string) $source) ?: 'manual'
        ]);
    }


    public static function localize(
        $entityType,
        array $row,
        $languageCode
    ) {
        self::ensureTable();

        $entityId = (int) ($row['id'] ?? 0);
        $languageCode = strtolower(
            trim((string) $languageCode)
        );

        if ($entityId <= 0 || $languageCode === '') {
            return $row;
        }

        // Украинский хранится в самих таблицах Delivery.
        if ($languageCode === Language::SOURCE_CODE) {
            return $row;
        }

        $db = Database::connect();

        $stmt = $db->prepare("
            SELECT
                name,
                description
            FROM delivery_translations
            WHERE entity_type = :entity_type
              AND entity_id = :entity_id
              AND language_code = :language_code
              AND status = 'approved'
            LIMIT 1
        ");

        $stmt->execute([
            'entity_type' => (string) $entityType,
            'entity_id' => $entityId,
            'language_code' => $languageCode
        ]);

        $translation = $stmt->fetch(PDO::FETCH_ASSOC);

        // Нет перевода — оставляем украинский оригинал.
        if (!$translation) {
            return $row;
        }

        $row['name'] = $translation['name'];
        $row['description'] = $translation['description'];

        return $row;
    }


    public static function localizeTree(
        array $methods,
        $languageCode
    ) {
        foreach ($methods as $methodIndex => $method) {
            $methods[$methodIndex] = self::localize(
                'method',
                $method,
                $languageCode
            );

            if (
                empty($methods[$methodIndex]['services'])
                || !is_array($methods[$methodIndex]['services'])
            ) {
                continue;
            }

            foreach (
                $methods[$methodIndex]['services']
                as $serviceIndex => $service
            ) {
                $methods[$methodIndex]['services'][$serviceIndex] =
                    self::localize(
                        'service',
                        $service,
                        $languageCode
                    );

                if (
                    empty(
                        $methods[$methodIndex]
                            ['services'][$serviceIndex]['options']
                    )
                    || !is_array(
                        $methods[$methodIndex]
                            ['services'][$serviceIndex]['options']
                    )
                ) {
                    continue;
                }

                foreach (
                    $methods[$methodIndex]
                        ['services'][$serviceIndex]['options']
                    as $optionIndex => $option
                ) {
                    $methods[$methodIndex]
                        ['services'][$serviceIndex]
                        ['options'][$optionIndex] =
                            self::localize(
                                'option',
                                $option,
                                $languageCode
                            );
                }
            }
        }

        return $methods;
    }
}
