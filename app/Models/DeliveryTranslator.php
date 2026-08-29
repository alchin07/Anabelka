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

        self::seedKnownBaseTranslations($db);

        self::$schemaReady = true;
    }


    private static function dictionary()
    {
        return [
            'method' => [
                'courier' => [
                    'aliases' => ['Курьер', 'Кур’єр', "Кур'єр"],
                    'uk' => ['Кур’єр', 'Доставка кур’єром.'],
                    'ru' => ['Курьер', 'Доставка курьером.'],
                    'en' => ['Courier', 'Courier delivery.']
                ],
                'pickup' => [
                    'aliases' => ['Самовывоз', 'Самовивіз'],
                    'uk' => ['Самовивіз', 'Забрати замовлення самостійно'],
                    'ru' => ['Самовывоз', 'Забрать заказ самостоятельно'],
                    'en' => ['Pickup', 'Pick up the order yourself']
                ],
                'post' => [
                    'aliases' => ['Почтовая доставка', 'Поштова доставка'],
                    'uk' => ['Поштова доставка', 'Доставка через поштову службу'],
                    'ru' => ['Почтовая доставка', 'Доставка через почтовую службу'],
                    'en' => ['Postal delivery', 'Delivery via a postal service']
                ]
            ],
            'service' => [
                'nova_poshta' => [
                    'aliases' => ['Нова пошта', 'Новая почта', 'Nova Poshta'],
                    'slugs' => ['nova_poshta', 'nova-poshta'],
                    'uk' => ['Нова пошта', null],
                    'ru' => ['Нова пошта', null],
                    'en' => ['Nova Poshta', null]
                ],
                'ukrposhta' => [
                    'aliases' => ['Укрпошта', 'Укрпочта', 'Ukrposhta'],
                    'uk' => ['Укрпошта', null],
                    'ru' => ['Укрпочта', null],
                    'en' => ['Ukrposhta', null]
                ],
                'delivery' => [
                    'aliases' => ['Delivery'],
                    'uk' => ['Delivery', null],
                    'ru' => ['Delivery', null],
                    'en' => ['Delivery', null]
                ]
            ],
            'option' => [
                'branch' => [
                    'aliases' => [
                        'Доставка в отделение',
                        'Доставка у відділення',
                        'Delivery to a branch'
                    ],
                    'uk' => ['Доставка у відділення', 'Доставка у відділення'],
                    'ru' => ['Доставка в отделение', 'Доставка в отделение'],
                    'en' => ['Delivery to a branch', 'Delivery to a branch']
                ],
                'parcel_locker' => [
                    'aliases' => [
                        'Доставка в почтомат',
                        'Доставка у поштомат',
                        'Delivery to a parcel locker'
                    ],
                    'uk' => ['Доставка у поштомат', 'Доставка у поштомат'],
                    'ru' => ['Доставка в почтомат', 'Доставка в почтомат'],
                    'en' => ['Delivery to a parcel locker', 'Delivery to a parcel locker']
                ],
                'address' => [
                    'aliases' => [
                        'Адресная доставка',
                        'Адресна доставка',
                        'Доставка по адресу',
                        'Доставка за адресою',
                        'Address delivery'
                    ],
                    'uk' => ['Адресна доставка', 'Доставка за адресою'],
                    'ru' => ['Адресная доставка', 'Доставка по адресу'],
                    'en' => ['Address delivery', 'Delivery to an address']
                ]
            ]
        ];
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


    private static function seedKnownBaseTranslations(PDO $db)
    {
        $dictionary = self::dictionary();

        $insert = $db->prepare("
            INSERT IGNORE INTO delivery_translations
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
                'system',
                'approved'
            )
        ");

        foreach ($dictionary as $entityType => $items) {
            $table = self::tableForType($entityType);

            if (!$table) {
                continue;
            }

            foreach ($items as $key => $data) {
                $entityIds = [];

                $slugs = $data['slugs'] ?? [$key];

                foreach ($slugs as $slug) {
                    $stmt = $db->prepare(
                        "SELECT id FROM {$table} WHERE slug = :slug"
                    );

                    $stmt->execute([
                        'slug' => $slug
                    ]);

                    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                        $entityIds[(int) $id] = true;
                    }
                }

                foreach ($data['aliases'] ?? [] as $alias) {
                    $stmt = $db->prepare(
                        "SELECT id FROM {$table} WHERE name = :name"
                    );

                    $stmt->execute([
                        'name' => $alias
                    ]);

                    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                        $entityIds[(int) $id] = true;
                    }
                }

                foreach (array_keys($entityIds) as $entityId) {
                    if ($entityId <= 0) {
                        continue;
                    }

                    foreach (['uk', 'ru', 'en'] as $languageCode) {
                        if (empty($data[$languageCode])) {
                            continue;
                        }

                        $translation = $data[$languageCode];

                        $insert->execute([
                            'entity_type' => $entityType,
                            'entity_id' => $entityId,
                            'language_code' => $languageCode,
                            'name' => $translation[0],
                            'description' => $translation[1]
                        ]);
                    }
                }
            }
        }
    }


    private static function lower($value)
    {
        $value = trim((string) $value);

        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }

        return strtolower($value);
    }


    private static function contains($haystack, $needle)
    {
        if ($needle === '') {
            return false;
        }

        return strpos($haystack, $needle) !== false;
    }


    /**
     * Определяет стандартную сущность Delivery даже для старых записей,
     * у которых slug был создан автоматически и не совпадает с текущим.
     */
    private static function detectDictionaryKey(
        $entityType,
        array $row
    ) {
        $slug = self::lower($row['slug'] ?? '');
        $name = self::lower($row['name'] ?? '');
        $text = $slug . ' ' . $name;

        if ($entityType === 'service') {
            if (
                self::contains($text, 'нова пошта')
                || self::contains($text, 'новая почта')
                || self::contains($text, 'nova poshta')
                || (
                    self::contains($text, 'nova')
                    && (
                        self::contains($text, 'poshta')
                        || self::contains($text, 'pochta')
                    )
                )
            ) {
                return 'nova_poshta';
            }

            if (
                self::contains($text, 'укрпошта')
                || self::contains($text, 'укрпочта')
                || self::contains($text, 'ukrposhta')
            ) {
                return 'ukrposhta';
            }

            if ($name === 'delivery' || $slug === 'delivery') {
                return 'delivery';
            }
        }

        if ($entityType === 'option') {
            if (
                self::contains($text, 'отдел')
                || self::contains($text, 'відділ')
                || self::contains($text, 'branch')
                || self::contains($text, 'otdelen')
                || self::contains($text, 'viddil')
            ) {
                return 'branch';
            }

            if (
                self::contains($text, 'почтомат')
                || self::contains($text, 'поштомат')
                || self::contains($text, 'parcel locker')
                || self::contains($text, 'parcel_locker')
                || self::contains($text, 'parcel-locker')
            ) {
                return 'parcel_locker';
            }

            if (
                self::contains($text, 'адрес')
                || self::contains($text, 'address')
            ) {
                return 'address';
            }
        }

        if ($entityType === 'method') {
            if (
                self::contains($text, 'курьер')
                || self::contains($text, 'кур’єр')
                || self::contains($text, "кур'єр")
                || self::contains($text, 'courier')
            ) {
                return 'courier';
            }

            if (
                self::contains($text, 'самовывоз')
                || self::contains($text, 'самовивіз')
                || self::contains($text, 'pickup')
            ) {
                return 'pickup';
            }

            if (
                self::contains($text, 'почтов')
                || self::contains($text, 'поштов')
                || self::contains($text, 'postal')
            ) {
                return 'post';
            }
        }

        return null;
    }


    private static function fallbackTranslation(
        $entityType,
        array $row,
        $languageCode
    ) {
        $dictionary = self::dictionary();
        $items = $dictionary[$entityType] ?? [];

        $rowSlug = trim((string) ($row['slug'] ?? ''));
        $rowName = trim((string) ($row['name'] ?? ''));

        foreach ($items as $key => $data) {
            $slugs = $data['slugs'] ?? [$key];
            $aliases = $data['aliases'] ?? [];

            $matchesSlug =
                $rowSlug !== ''
                && in_array($rowSlug, $slugs, true);

            $matchesName =
                $rowName !== ''
                && in_array($rowName, $aliases, true);

            if (!$matchesSlug && !$matchesName) {
                continue;
            }

            $translation = $data[$languageCode] ?? null;

            if (!$translation) {
                return null;
            }

            return [
                'name' => $translation[0],
                'description' => $translation[1]
            ];
        }

        $detectedKey = self::detectDictionaryKey(
            $entityType,
            $row
        );

        if (!$detectedKey || empty($items[$detectedKey][$languageCode])) {
            return null;
        }

        $translation = $items[$detectedKey][$languageCode];

        return [
            'name' => $translation[0],
            'description' => $translation[1]
        ];
    }


    public static function localize(
        $entityType,
        array $row,
        $languageCode
    ) {
        self::ensureTable();

        $entityId = (int) ($row['id'] ?? 0);
        $languageCode = trim((string) $languageCode);

        if (
            $entityId <= 0
            || $languageCode === ''
        ) {
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

        if (!$translation) {
            $translation = self::fallbackTranslation(
                $entityType,
                $row,
                $languageCode
            );
        }

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
        foreach ($methods as &$method) {
            $method = self::localize(
                'method',
                $method,
                $languageCode
            );

            foreach ($method['services'] ?? [] as &$service) {
                $service = self::localize(
                    'service',
                    $service,
                    $languageCode
                );

                foreach ($service['options'] ?? [] as &$option) {
                    $option = self::localize(
                        'option',
                        $option,
                        $languageCode
                    );
                }

                unset($option);
            }

            unset($service);
        }

        unset($method);

        return $methods;
    }
}
