<?php

class HomePageBlock
{
    private static $schemaReady = false;

    private const ZONES = [
        'main',
        'right_rail'
    ];

    private const PRODUCT_SOURCES = [
        'latest',
        'new',
        'discounts'
    ];

    private const DEFAULTS = [
        [
            'system_key' => 'hero',
            'block_type' => 'hero',
            'zone' => 'main',
            'sort_order' => -30,
            'settings' => []
        ],
        [
            'system_key' => 'directions',
            'block_type' => 'directions',
            'zone' => 'main',
            'sort_order' => -20,
            'settings' => []
        ],
        [
            'system_key' => 'adult_entry',
            'block_type' => 'adult_entry',
            'zone' => 'main',
            'sort_order' => -10,
            'settings' => []
        ],
        [
            'system_key' => 'product_collection_latest',
            'block_type' => 'product_collection',
            'zone' => 'main',
            'sort_order' => 10,
            'settings' => [
                'source' => 'latest',
                'limit' => 8
            ]
        ],
        [
            'system_key' => 'useful',
            'block_type' => 'useful',
            'zone' => 'main',
            'sort_order' => 20,
            'settings' => []
        ],
        [
            'system_key' => 'info_row',
            'block_type' => 'info_row',
            'zone' => 'main',
            'sort_order' => 30,
            'settings' => []
        ],
        [
            'system_key' => 'news',
            'block_type' => 'news',
            'zone' => 'right_rail',
            'sort_order' => 10,
            'settings' => [
                'limit' => 3
            ]
        ],
        [
            'system_key' => 'reviews',
            'block_type' => 'reviews',
            'zone' => 'right_rail',
            'sort_order' => 20,
            'settings' => [
                'limit' => 2
            ]
        ],
        [
            'system_key' => 'gift_certificate',
            'block_type' => 'gift_certificate',
            'zone' => 'right_rail',
            'sort_order' => 30,
            'settings' => []
        ]
    ];


    public static function catalog()
    {
        return [
            'hero' => [
                'label' => 'Hero',
                'description' => 'Головний промо-блок із заголовком та переходом до каталогу.',
                'zones' => ['main']
            ],
            'directions' => [
                'label' => 'Напрямки магазину',
                'description' => 'Картки основних напрямків та категорій магазину.',
                'zones' => ['main']
            ],
            'adult_entry' => [
                'label' => 'Блок 18+',
                'description' => 'Окремий вхід до повнолітнього розділу, якщо такі категорії активні.',
                'zones' => ['main']
            ],
            'product_collection' => [
                'label' => 'Товарна підбірка',
                'description' => 'Товари на головній: останні, нові без акцій або зі знижками.',
                'zones' => ['main'],
                'creatable' => true
            ],
            'news' => [
                'label' => 'Новини',
                'description' => 'Останні опубліковані новини Анабельки.',
                'zones' => ['right_rail'],
                'creatable' => true
            ],
            'reviews' => [
                'label' => 'Відгуки',
                'description' => 'Останні схвалені відгуки покупців.',
                'zones' => ['right_rail'],
                'creatable' => true
            ],
            'gift_certificate' => [
                'label' => 'Подарунковий сертифікат',
                'description' => 'Інформаційна картка електронного сертифіката.',
                'zones' => ['right_rail'],
                'creatable' => true
            ],
            'useful' => [
                'label' => 'Корисне',
                'description' => 'Компактне меню новин, відгуків і подарункових сертифікатів.',
                'zones' => ['main']
            ],
            'info_row' => [
                'label' => 'Інформація магазину',
                'description' => 'Нижній рядок Доставка · Оплата · Повернення · Контакти.',
                'zones' => ['main']
            ]
        ];
    }


    public static function zoneLabels()
    {
        return [
            'main' => 'Основний контент',
            'right_rail' => 'Правий сайдбар (desktop ≥ 1250 px)'
        ];
    }


    public static function ensureSchema()
    {
        if (self::$schemaReady) {
            return;
        }

        $db = Database::connect();

        $db->exec("
            CREATE TABLE IF NOT EXISTS home_page_blocks
            (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                system_key VARCHAR(80) NOT NULL,
                block_type VARCHAR(60) NOT NULL,
                zone VARCHAR(30) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                settings_json LONGTEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_home_page_blocks_system_key (system_key),
                KEY idx_home_page_blocks_zone_order
                    (zone, is_active, sort_order, id)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        self::seedDefaults($db);
        self::$schemaReady = true;
    }


    public static function fallbackActiveByZone()
    {
        return self::groupRows(self::defaultRows());
    }


    public static function activeByZone()
    {
        self::ensureSchema();

        $rows = Database::connect()->query("
            SELECT
                id,
                system_key,
                block_type,
                zone,
                is_active,
                sort_order,
                settings_json
            FROM home_page_blocks
            WHERE is_active = 1
            ORDER BY
                CASE zone
                    WHEN 'main' THEN 1
                    WHEN 'right_rail' THEN 2
                    ELSE 9
                END,
                sort_order ASC,
                id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        return self::groupRows(
            self::normalizeRows($rows)
        );
    }


    public static function allForAdmin()
    {
        self::ensureSchema();

        $rows = Database::connect()->query("
            SELECT
                id,
                system_key,
                block_type,
                zone,
                is_active,
                sort_order,
                settings_json
            FROM home_page_blocks
            ORDER BY
                CASE zone
                    WHEN 'main' THEN 1
                    WHEN 'right_rail' THEN 2
                    ELSE 9
                END,
                sort_order ASC,
                id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        return self::groupRows(
            self::normalizeRows($rows)
        );
    }


    public static function find($blockId)
    {
        self::ensureSchema();

        $stmt = Database::connect()->prepare("
            SELECT
                id,
                system_key,
                block_type,
                zone,
                is_active,
                sort_order,
                settings_json
            FROM home_page_blocks
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([
            'id' => (int) $blockId
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $rows = self::normalizeRows([$row]);

        return $rows[0] ?? null;
    }


    public static function create($blockType, $zone)
    {
        self::ensureSchema();

        $blockType = strtolower(trim((string) $blockType));
        $zone = strtolower(trim((string) $zone));
        $catalog = self::catalog();
        $meta = $catalog[$blockType] ?? null;

        if (
            !is_array($meta)
            || empty($meta['creatable'])
            || !in_array($zone, self::ZONES, true)
            || !in_array($zone, $meta['zones'] ?? [], true)
        ) {
            throw new InvalidArgumentException(
                'Цей тип блоку не можна додати до вибраної зони.'
            );
        }

        $settings = self::defaultSettingsForType($blockType);
        $encoded = json_encode(
            $settings,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($encoded === false) {
            throw new RuntimeException(
                'Не вдалося підготувати налаштування нового блоку.'
            );
        }

        $db = Database::connect();
        $db->beginTransaction();

        try {
            $orderStmt = $db->prepare("
                SELECT id, sort_order
                FROM home_page_blocks
                WHERE zone = :zone
                ORDER BY sort_order DESC, id DESC
                LIMIT 1
                FOR UPDATE
            ");
            $orderStmt->execute([
                'zone' => $zone
            ]);
            $lastRow = $orderStmt->fetch(PDO::FETCH_ASSOC);
            $sortOrder = $lastRow
                ? (int) ($lastRow['sort_order'] ?? 0) + 10
                : 10;
            $systemKey = self::uniqueSystemKey(
                $db,
                $blockType
            );

            $insert = $db->prepare("
                INSERT INTO home_page_blocks
                (
                    system_key,
                    block_type,
                    zone,
                    is_active,
                    sort_order,
                    settings_json
                )
                VALUES
                (
                    :system_key,
                    :block_type,
                    :zone,
                    1,
                    :sort_order,
                    :settings_json
                )
            ");
            $insert->execute([
                'system_key' => $systemKey,
                'block_type' => $blockType,
                'zone' => $zone,
                'sort_order' => $sortOrder,
                'settings_json' => $encoded
            ]);

            $blockId = (int) $db->lastInsertId();
            $db->commit();

            return $blockId;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }
    }


    public static function delete($blockId)
    {
        $block = self::find($blockId);

        if (!$block) {
            throw new InvalidArgumentException(
                'Блок головної сторінки не знайдено.'
            );
        }

        if (!empty($block['is_system'])) {
            throw new DomainException(
                'Базовий системний блок не можна видалити. Його можна вимкнути.'
            );
        }

        $db = Database::connect();
        $db->beginTransaction();

        try {
            $stmt = $db->prepare("
                DELETE FROM home_page_blocks
                WHERE id = :id
            ");
            $stmt->execute([
                'id' => (int) $block['id']
            ]);

            self::normalizeZoneOrder(
                $db,
                (string) ($block['zone'] ?? '')
            );

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }
    }


    public static function toggle($blockId)
    {
        $block = self::find($blockId);

        if (!$block) {
            throw new InvalidArgumentException('Блок головної сторінки не знайдено.');
        }

        $stmt = Database::connect()->prepare("
            UPDATE home_page_blocks
            SET is_active = :is_active
            WHERE id = :id
        ");
        $stmt->execute([
            'is_active' => !empty($block['is_active']) ? 0 : 1,
            'id' => (int) $block['id']
        ]);
    }


    public static function updateSettings($blockId, array $input)
    {
        $block = self::find($blockId);

        if (!$block) {
            throw new InvalidArgumentException('Блок головної сторінки не знайдено.');
        }

        $settings = self::normalizeSettings(
            (string) ($block['block_type'] ?? ''),
            $input
        );

        $encoded = json_encode(
            $settings,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($encoded === false) {
            throw new RuntimeException('Не вдалося зберегти налаштування блоку.');
        }

        $stmt = Database::connect()->prepare("
            UPDATE home_page_blocks
            SET settings_json = :settings_json
            WHERE id = :id
        ");
        $stmt->execute([
            'settings_json' => $encoded,
            'id' => (int) $block['id']
        ]);
    }


    public static function move($blockId, $direction)
    {
        self::ensureSchema();

        $blockId = (int) $blockId;
        $direction = strtolower(trim((string) $direction));

        if (!in_array($direction, ['up', 'down'], true)) {
            throw new InvalidArgumentException('Некоректний напрямок переміщення.');
        }

        $db = Database::connect();
        $db->beginTransaction();

        try {
            $blockStmt = $db->prepare("
                SELECT id, zone
                FROM home_page_blocks
                WHERE id = :id
                LIMIT 1
                FOR UPDATE
            ");
            $blockStmt->execute([
                'id' => $blockId
            ]);
            $block = $blockStmt->fetch(PDO::FETCH_ASSOC);

            if (!$block) {
                throw new InvalidArgumentException(
                    'Блок головної сторінки не знайдено.'
                );
            }

            $zone = (string) ($block['zone'] ?? '');
            $rowsStmt = $db->prepare("
                SELECT id
                FROM home_page_blocks
                WHERE zone = :zone
                ORDER BY sort_order ASC, id ASC
                FOR UPDATE
            ");
            $rowsStmt->execute([
                'zone' => $zone
            ]);
            $ids = array_map(
                'intval',
                $rowsStmt->fetchAll(PDO::FETCH_COLUMN)
            );

            $index = array_search($blockId, $ids, true);

            if ($index === false) {
                throw new RuntimeException('Блок не знайдено у своїй зоні.');
            }

            $targetIndex = $direction === 'up'
                ? $index - 1
                : $index + 1;

            if ($targetIndex < 0 || $targetIndex >= count($ids)) {
                $db->commit();
                return;
            }

            [$ids[$index], $ids[$targetIndex]] = [
                $ids[$targetIndex],
                $ids[$index]
            ];

            $update = $db->prepare("
                UPDATE home_page_blocks
                SET sort_order = :sort_order
                WHERE id = :id
            ");

            foreach ($ids as $position => $id) {
                $update->execute([
                    'sort_order' => ($position + 1) * 10,
                    'id' => (int) $id
                ]);
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }
    }


    public static function reorder($zone, array $blockIds)
    {
        self::ensureSchema();

        $zone = strtolower(trim((string) $zone));

        if (!in_array($zone, self::ZONES, true)) {
            throw new InvalidArgumentException(
                'Некоректна зона конструктора головної сторінки.'
            );
        }

        $ids = [];

        foreach ($blockIds as $value) {
            $raw = trim((string) $value);

            if ($raw === '' || !preg_match('/^\\d+$/', $raw)) {
                throw new InvalidArgumentException(
                    'Некоректний список блоків для сортування.'
                );
            }

            $id = (int) $raw;

            if ($id <= 0) {
                throw new InvalidArgumentException(
                    'Некоректний ідентифікатор блоку.'
                );
            }

            $ids[] = $id;
        }

        if (empty($ids)) {
            throw new InvalidArgumentException(
                'Список блоків для сортування порожній.'
            );
        }

        if (count($ids) !== count(array_unique($ids))) {
            throw new InvalidArgumentException(
                'Список блоків містить дублікати.'
            );
        }

        $db = Database::connect();
        $db->beginTransaction();

        try {
            $rowsStmt = $db->prepare("
                SELECT id
                FROM home_page_blocks
                WHERE zone = :zone
                ORDER BY sort_order ASC, id ASC
                FOR UPDATE
            ");
            $rowsStmt->execute([
                'zone' => $zone
            ]);
            $currentIds = array_map(
                'intval',
                $rowsStmt->fetchAll(PDO::FETCH_COLUMN)
            );

            $expected = $currentIds;
            $submitted = $ids;
            sort($expected, SORT_NUMERIC);
            sort($submitted, SORT_NUMERIC);

            if ($expected !== $submitted) {
                throw new InvalidArgumentException(
                    'Склад блоків змінився. Оновіть сторінку та повторіть переміщення.'
                );
            }

            if ($currentIds === $ids) {
                $db->commit();
                return false;
            }

            $update = $db->prepare("
                UPDATE home_page_blocks
                SET sort_order = :sort_order
                WHERE id = :id
            ");

            foreach ($ids as $position => $id) {
                $update->execute([
                    'sort_order' => ($position + 1) * 10,
                    'id' => (int) $id
                ]);
            }

            $db->commit();
            return true;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }
    }


    public static function settingInt(
        array $block,
        $key,
        $fallback,
        $min,
        $max
    ) {
        $settings = is_array($block['settings'] ?? null)
            ? $block['settings']
            : [];
        $value = (int) ($settings[$key] ?? $fallback);

        return max(
            (int) $min,
            min((int) $max, $value)
        );
    }


    public static function productSource(array $block)
    {
        $settings = is_array($block['settings'] ?? null)
            ? $block['settings']
            : [];
        $source = strtolower(trim((string) ($settings['source'] ?? 'latest')));

        return in_array($source, self::PRODUCT_SOURCES, true)
            ? $source
            : 'latest';
    }


    private static function normalizeSettings($blockType, array $input)
    {
        switch ($blockType) {
            case 'product_collection':
                $source = strtolower(trim((string) ($input['source'] ?? 'latest')));

                if (!in_array($source, self::PRODUCT_SOURCES, true)) {
                    $source = 'latest';
                }

                return [
                    'source' => $source,
                    'limit' => self::boundedInt(
                        $input['limit'] ?? 8,
                        1,
                        24,
                        8
                    )
                ];

            case 'news':
                return [
                    'limit' => self::boundedInt(
                        $input['limit'] ?? 3,
                        1,
                        10,
                        3
                    )
                ];

            case 'reviews':
                return [
                    'limit' => self::boundedInt(
                        $input['limit'] ?? 2,
                        1,
                        10,
                        2
                    )
                ];

            case 'hero':
            case 'directions':
            case 'adult_entry':
            case 'gift_certificate':
            case 'useful':
            case 'info_row':
                return [];

            default:
                throw new InvalidArgumentException(
                    'Невідомий тип блоку головної сторінки.'
                );
        }
    }


    private static function boundedInt($value, $min, $max, $fallback)
    {
        if (!is_numeric($value)) {
            return (int) $fallback;
        }

        return max(
            (int) $min,
            min((int) $max, (int) $value)
        );
    }


    private static function normalizeRows(array $rows)
    {
        $catalog = self::catalog();
        $result = [];

        foreach ($rows as $row) {
            $type = (string) ($row['block_type'] ?? '');
            $zone = (string) ($row['zone'] ?? '');

            if (
                !isset($catalog[$type])
                || !in_array($zone, self::ZONES, true)
                || !in_array($zone, $catalog[$type]['zones'], true)
            ) {
                continue;
            }

            $decoded = json_decode(
                (string) ($row['settings_json'] ?? ''),
                true
            );

            $row['settings'] = is_array($decoded)
                ? $decoded
                : [];
            $row['id'] = (int) ($row['id'] ?? 0);
            $row['sort_order'] = (int) ($row['sort_order'] ?? 0);
            $row['is_active'] = !empty($row['is_active']) ? 1 : 0;
            $row['is_system'] = self::isSystemKey(
                (string) ($row['system_key'] ?? '')
            ) ? 1 : 0;
            $row['type_meta'] = $catalog[$type];
            $result[] = $row;
        }

        return $result;
    }


    private static function groupRows(array $rows)
    {
        $grouped = [
            'main' => [],
            'right_rail' => []
        ];

        foreach ($rows as $row) {
            $zone = (string) ($row['zone'] ?? '');

            if (array_key_exists($zone, $grouped)) {
                $grouped[$zone][] = $row;
            }
        }

        return $grouped;
    }


    private static function defaultRows()
    {
        $rows = [];

        foreach (self::DEFAULTS as $default) {
            $rows[] = [
                'id' => 0,
                'system_key' => (string) $default['system_key'],
                'block_type' => (string) $default['block_type'],
                'zone' => (string) $default['zone'],
                'is_active' => 1,
                'is_system' => 1,
                'sort_order' => (int) $default['sort_order'],
                'settings_json' => json_encode(
                    $default['settings'],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
                'settings' => $default['settings'],
                'type_meta' => self::catalog()[$default['block_type']]
            ];
        }

        return $rows;
    }


    private static function defaultSettingsForType($blockType)
    {
        switch ((string) $blockType) {
            case 'product_collection':
                return [
                    'source' => 'latest',
                    'limit' => 8
                ];

            case 'news':
                return [
                    'limit' => 3
                ];

            case 'reviews':
                return [
                    'limit' => 2
                ];

            case 'gift_certificate':
                return [];

            default:
                throw new InvalidArgumentException(
                    'Невідомий тип блоку для створення.'
                );
        }
    }


    private static function uniqueSystemKey(PDO $db, $blockType)
    {
        $base = preg_replace(
            '/[^a-z0-9_]+/',
            '_',
            strtolower(trim((string) $blockType))
        );
        $base = trim((string) $base, '_');

        if ($base === '') {
            $base = 'block';
        }

        $base = substr($base, 0, 68);
        $candidate = $base;
        $suffix = 2;

        while (true) {
            $stmt = $db->prepare("
                SELECT 1
                FROM home_page_blocks
                WHERE system_key = :system_key
                LIMIT 1
            ");
            $stmt->execute([
                'system_key' => $candidate
            ]);

            if (!$stmt->fetchColumn()) {
                return $candidate;
            }

            $suffixText = '-' . $suffix;
            $candidate = substr(
                $base,
                0,
                80 - strlen($suffixText)
            ) . $suffixText;
            $suffix++;
        }
    }


    private static function isSystemKey($systemKey)
    {
        $systemKey = (string) $systemKey;

        foreach (self::DEFAULTS as $default) {
            if (
                (string) ($default['system_key'] ?? '')
                === $systemKey
            ) {
                return true;
            }
        }

        return false;
    }


    private static function normalizeZoneOrder(PDO $db, $zone)
    {
        $zone = (string) $zone;

        if (!in_array($zone, self::ZONES, true)) {
            return;
        }

        $stmt = $db->prepare("
            SELECT id
            FROM home_page_blocks
            WHERE zone = :zone
            ORDER BY sort_order ASC, id ASC
            FOR UPDATE
        ");
        $stmt->execute([
            'zone' => $zone
        ]);
        $ids = array_map(
            'intval',
            $stmt->fetchAll(PDO::FETCH_COLUMN)
        );

        $update = $db->prepare("
            UPDATE home_page_blocks
            SET sort_order = :sort_order
            WHERE id = :id
        ");

        foreach ($ids as $position => $id) {
            $update->execute([
                'sort_order' => ($position + 1) * 10,
                'id' => (int) $id
            ]);
        }
    }


    private static function seedDefaults(PDO $db)
    {
        $stmt = $db->prepare("
            INSERT IGNORE INTO home_page_blocks
            (
                system_key,
                block_type,
                zone,
                is_active,
                sort_order,
                settings_json
            )
            VALUES
            (
                :system_key,
                :block_type,
                :zone,
                1,
                :sort_order,
                :settings_json
            )
        ");

        foreach (self::DEFAULTS as $default) {
            $encoded = json_encode(
                $default['settings'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            $stmt->execute([
                'system_key' => (string) $default['system_key'],
                'block_type' => (string) $default['block_type'],
                'zone' => (string) $default['zone'],
                'sort_order' => (int) $default['sort_order'],
                'settings_json' => $encoded !== false ? $encoded : '{}'
            ]);
        }
    }
}
