<?php

class DeliveryTranslator
{
    private static $schemaReady = false;


    /**
     * Создаёт отдельное хранилище переводов
     * для способов, служб и опций доставки.
     */
    private static function ensureTable()
    {
        if (self::$schemaReady) {
            return;
        }

        // Таблица языков должна существовать раньше переводов.
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


    /**
     * Стартовые переводы для уже существующей
     * базовой структуры Delivery.
     *
     * INSERT IGNORE важен: ручные переводы
     * администратора не перезаписываются кодом.
     */
    private static function seedKnownBaseTranslations(PDO $db)
    {
        $dictionary = [
            'method' => [
                'delivery_methods' => [
                    'courier' => [
                        'uk' => ['Кур’єр', 'Доставка кур’єром.'],
                        'ru' => ['Курьер', 'Доставка курьером.'],
                        'en' => ['Courier', 'Courier delivery.']
                    ],
                    'pickup' => [
                        'uk' => ['Самовивіз', 'Забрати замовлення самостійно'],
                        'ru' => ['Самовывоз', 'Забрать заказ самостоятельно'],
                        'en' => ['Pickup', 'Pick up the order yourself']
                    ],
                    'post' => [
                        'uk' => ['Поштова доставка', 'Доставка через поштову службу'],
                        'ru' => ['Почтовая доставка', 'Доставка через почтовую службу'],
                        'en' => ['Postal delivery', 'Delivery via a postal service']
                    ]
                ]
            ],
            'service' => [
                'delivery_services' => [
                    'nova_poshta' => [
                        'uk' => ['Нова пошта', null],
                        'ru' => ['Нова пошта', null],
                        'en' => ['Nova Poshta', null]
                    ],
                    'nova-poshta' => [
                        'uk' => ['Нова пошта', null],
                        'ru' => ['Нова пошта', null],
                        'en' => ['Nova Poshta', null]
                    ],
                    'ukrposhta' => [
                        'uk' => ['Укрпошта', null],
                        'ru' => ['Укрпочта', null],
                        'en' => ['Ukrposhta', null]
                    ],
                    'delivery' => [
                        'uk' => ['Delivery', null],
                        'ru' => ['Delivery', null],
                        'en' => ['Delivery', null]
                    ]
                ]
            ],
            'option' => [
                'delivery_service_options' => [
                    'branch' => [
                        'uk' => ['Доставка у відділення', 'Доставка у відділення'],
                        'ru' => ['Доставка в отделение', 'Доставка в отделение'],
                        'en' => ['Delivery to a branch', 'Delivery to a branch']
                    ],
                    'parcel_locker' => [
                        'uk' => ['Доставка у поштомат', 'Доставка у поштомат'],
                        'ru' => ['Доставка в почтомат', 'Доставка в почтомат'],
                        'en' => ['Delivery to a parcel locker', 'Delivery to a parcel locker']
                    ],
                    'address' => [
                        'uk' => ['Адресна доставка', 'Доставка за адресою'],
                        'ru' => ['Адресная доставка', 'Доставка по адресу'],
                        'en' => ['Address delivery', 'Delivery to an address']
                    ]
                ]
            ]
        ];

        /*
         * Старые записи Delivery могли быть созданы до того,
         * как были зафиксированы системные slug courier/pickup/post.
         * Поэтому базовые сущности дополнительно узнаём по названию.
         */
        $nameAliases = [
            'method' => [
                'courier' => ['Курьер', 'Кур’єр', "Кур'єр"],
                'pickup' => ['Самовывоз', 'Самовивіз'],
                'post' => ['Почтовая доставка', 'Поштова доставка']
            ],
            'service' => [
                'nova_poshta' => ['Нова пошта', 'Новая почта'],
                'nova-poshta' => ['Нова пошта', 'Новая почта'],
                'ukrposhta' => ['Укрпошта', 'Укрпочта'],
                'delivery' => ['Delivery']
            ],
            'option' => [
                'branch' => ['Доставка в отделение', 'Доставка у відділення'],
                'parcel_locker' => [
                    'Доставка в почтомат',
                    'Доставка у поштомат'
                ],
                'address' => [
                    'Адресная доставка',
                    'Адресна доставка',
                    'Доставка по адресу',
                    'Доставка за адресою'
                ]
            ]
        ];

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

        foreach ($dictionary as $entityType => $tables) {
            foreach ($tables as $table => $items) {
                foreach ($items as $slug => $translations) {
                    $stmt = $db->prepare(
                        "SELECT id FROM {$table} WHERE slug = :slug LIMIT 1"
                    );

                    $stmt->execute([
                        'slug' => $slug
                    ]);

                    $entityId = (int) $stmt->fetchColumn();

                    /*
                     * Если системный slug не совпал со старой записью,
                     * ищем её по одному из известных исходных названий.
                     */
                    if (
                        $entityId <= 0
                        && !empty($nameAliases[$entityType][$slug])
                    ) {
                        foreach (
                            $nameAliases[$entityType][$slug]
                            as $alias
                        ) {
                            $nameStmt = $db->prepare(
                                "SELECT id FROM {$table} WHERE name = :name LIMIT 1"
                            );

                            $nameStmt->execute([
                                'name' => $alias
                            ]);

                            $entityId =
                                (int) $nameStmt->fetchColumn();

                            if ($entityId > 0) {
                                break;
                            }
                        }
                    }

                    if ($entityId <= 0) {
                        continue;
                    }

                    foreach ($translations as $languageCode => $translation) {
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


    /**
     * Возвращает локализованные name / description.
     * Если перевода нет — исходные данные остаются без изменений.
     */
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
            return $row;
        }

        $row['name'] = $translation['name'];
        $row['description'] = $translation['description'];

        return $row;
    }


    /**
     * Локализует всю вложенную структуру Delivery.
     */
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
