<?php

class TranslationDashboardService
{
    public function getDashboardData()
    {
        $languages = Language::active();
        $targetLanguages = array_values(
            array_filter(
                $languages,
                function ($language) {
                    return strtolower(
                        trim((string) ($language['code'] ?? ''))
                    ) !== Language::SOURCE_CODE;
                }
            )
        );

        /*
         * Гарантируем наличие таблиц переводов до подсчётов.
         */
        Translator::activeLanguages();
        ProductInterfaceTranslator::seed();
        PublicInterfaceTranslator::seed();
        CategoryTranslator::getForCategory(0);
        ProductTranslator::getForProduct(0);
        DeliveryTranslator::getForEntity('method', 0);
        DeliveryOptionInput::getForOption(0);

        return [
            'sourceLanguage' =>
                Language::findByCode(Language::SOURCE_CODE),
            'languages' => $languages,
            'targetLanguages' => $targetLanguages,
            'coverage' =>
                $this->buildCoverage($targetLanguages)
        ];
    }


    public function getMissingTranslations($section, array $filters = [])
    {
        $section = strtolower(trim((string) $section));
        $targetLanguages = array_values(
            array_filter(
                Language::active(),
                function ($language) {
                    return strtolower(
                        trim((string) ($language['code'] ?? ''))
                    ) !== Language::SOURCE_CODE;
                }
            )
        );

        $allowed = [
            'products' => [
                'label' => 'Товары',
                'url' => '/Anabelka/admin/products'
            ],
            'categories' => [
                'label' => 'Категории',
                'url' => '/Anabelka/admin/categories'
            ],
            'delivery' => [
                'label' => 'Delivery',
                'url' => '/Anabelka/admin/delivery'
            ],
            'interface' => [
                'label' => 'Інтерфейс',
                'url' => '/Anabelka/admin/translations'
            ]
        ];

        if (!isset($allowed[$section])) {
            throw new InvalidArgumentException(
                'Неизвестный раздел переводов.'
            );
        }

        $items = [];

        if ($section === 'products') {
            $items = $this->missingProducts();
        } elseif ($section === 'categories') {
            $items = $this->missingCategories();
        } elseif ($section === 'delivery') {
            $items = $this->missingDelivery();
        } elseif ($section === 'interface') {
            $items = $this->missingInterface();
        }

        $filters = $this->normalizeMissingFilters(
            $section,
            $filters,
            $targetLanguages
        );

        $totalItems = count($items);
        $items = $this->filterMissingItems($items, $filters);

        return [
            'section' => $section,
            'sectionLabel' => $allowed[$section]['label'],
            'sectionUrl' => $allowed[$section]['url'],
            'targetLanguages' => $targetLanguages,
            'items' => $items,
            'totalItems' => $totalItems,
            'filters' => $filters
        ];
    }


    private function missingProducts()
    {
        $db = Database::connect();

        $rows = $db->query("
            SELECT
                p.id AS entity_id,
                p.name AS entity_name,
                l.code AS language_code
            FROM products AS p
            INNER JOIN languages AS l
                ON l.is_active = 1
               AND l.code <> 'uk'
            LEFT JOIN product_translations AS t
                ON t.product_id = p.id
               AND t.language_code = l.code
               AND t.status = 'approved'
               AND TRIM(t.name) <> ''
            WHERE t.product_id IS NULL
            ORDER BY p.id ASC, l.sort_order ASC, l.id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        return $this->groupMissingRows(
            $rows,
            'product',
            '/Anabelka/admin/products'
        );
    }


    private function missingCategories()
    {
        $db = Database::connect();

        $rows = $db->query("
            SELECT
                c.id AS entity_id,
                c.name AS entity_name,
                l.code AS language_code
            FROM categories AS c
            INNER JOIN languages AS l
                ON l.is_active = 1
               AND l.code <> 'uk'
            LEFT JOIN category_translations AS t
                ON t.category_id = c.id
               AND t.language_code = l.code
               AND t.status = 'approved'
               AND TRIM(t.name) <> ''
            WHERE t.category_id IS NULL
            ORDER BY c.id ASC, l.sort_order ASC, l.id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        return $this->groupMissingRows(
            $rows,
            'category',
            '/Anabelka/admin/categories'
        );
    }


    private function missingDelivery()
    {
        $db = Database::connect();

        $entityRows = $db->query("
            SELECT
                e.entity_type,
                e.entity_id,
                e.entity_name,
                l.code AS language_code
            FROM (
                SELECT
                    'method' AS entity_type,
                    id AS entity_id,
                    name AS entity_name
                FROM delivery_methods

                UNION ALL

                SELECT
                    'service' AS entity_type,
                    s.id AS entity_id,
                    s.name AS entity_name
                FROM delivery_services AS s
                INNER JOIN delivery_methods AS m
                    ON m.id = s.delivery_method_id

                UNION ALL

                SELECT
                    'option' AS entity_type,
                    o.id AS entity_id,
                    o.name AS entity_name
                FROM delivery_service_options AS o
                INNER JOIN delivery_services AS s
                    ON s.id = o.delivery_service_id
                INNER JOIN delivery_methods AS m
                    ON m.id = s.delivery_method_id
            ) AS e
            INNER JOIN languages AS l
                ON l.is_active = 1
               AND l.code <> 'uk'
            LEFT JOIN delivery_translations AS t
                ON t.entity_type = e.entity_type
               AND t.entity_id = e.entity_id
               AND t.language_code = l.code
               AND t.status = 'approved'
               AND TRIM(t.name) <> ''
            WHERE t.entity_id IS NULL
            ORDER BY e.entity_type ASC, e.entity_id ASC,
                     l.sort_order ASC, l.id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $items = $this->groupMissingRows(
            $entityRows,
            'delivery',
            '/Anabelka/admin/delivery',
            'entity_type'
        );

        $inputRows = $db->query("
            SELECT
                i.option_id AS entity_id,
                CONCAT(
                    o.name,
                    ' — поле покупателя'
                ) AS entity_name,
                l.code AS language_code
            FROM delivery_option_inputs AS i
            INNER JOIN delivery_service_options AS o
                ON o.id = i.option_id
            INNER JOIN delivery_services AS s
                ON s.id = o.delivery_service_id
            INNER JOIN delivery_methods AS m
                ON m.id = s.delivery_method_id
            INNER JOIN languages AS l
                ON l.is_active = 1
               AND l.code <> 'uk'
            LEFT JOIN delivery_option_input_translations AS t
                ON t.option_id = i.option_id
               AND t.language_code = l.code
            WHERE i.is_enabled = 1
              AND (
                    TRIM(COALESCE(i.field_label, '')) <> ''
                    OR
                    TRIM(COALESCE(i.placeholder, '')) <> ''
              )
              AND (
                    t.option_id IS NULL
                    OR
                    (
                        TRIM(COALESCE(i.field_label, '')) <> ''
                        AND TRIM(COALESCE(t.field_label, '')) = ''
                    )
                    OR
                    (
                        TRIM(COALESCE(i.placeholder, '')) <> ''
                        AND TRIM(COALESCE(t.placeholder, '')) = ''
                    )
              )
            ORDER BY i.option_id ASC, l.sort_order ASC, l.id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $inputItems = $this->groupMissingRows(
            $inputRows,
            'option_input',
            '/Anabelka/admin/delivery'
        );

        return array_values(array_merge($items, $inputItems));
    }


    private function missingInterface()
    {
        /*
         * Створює таблицю та базовий словник, якщо це перший
         * вхід до розділу перекладів після встановлення проєкту.
         */
        Translator::activeLanguages();
        ProductInterfaceTranslator::seed();
        PublicInterfaceTranslator::seed();

        $db = Database::connect();

        $rows = $db->query("
            SELECT
                source.translation_key,
                source.value AS source_value,
                l.code AS language_code
            FROM interface_translations AS source
            INNER JOIN languages AS l
                ON l.is_active = 1
               AND l.code <> 'uk'
            LEFT JOIN interface_translations AS t
                ON t.translation_key = source.translation_key
               AND t.language_code = l.code
               AND t.status = 'approved'
               AND TRIM(t.value) <> ''
            WHERE source.language_code = 'uk'
              AND TRIM(source.value) <> ''
              AND t.translation_key IS NULL
            ORDER BY
                source.translation_key ASC,
                l.sort_order ASC,
                l.id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $items = [];

        foreach ($rows as $row) {
            $key = trim(
                (string) ($row['translation_key'] ?? '')
            );

            if ($key === '') {
                continue;
            }

            if (!isset($items[$key])) {
                $items[$key] = [
                    'type' => 'interface',
                    'id' => 0,
                    'key' => $key,
                    'name' => (string) ($row['source_value'] ?? ''),
                    'missing_languages' => [],
                    'url' => '/Anabelka/admin/translations/interface'
                ];
            }

            $code = strtolower(
                trim((string) ($row['language_code'] ?? ''))
            );

            if ($code !== '') {
                $items[$key]['missing_languages'][] = $code;
            }
        }

        return array_values($items);
    }


    private function groupMissingRows(
        array $rows,
        $defaultType,
        $url,
        $typeColumn = null
    ) {
        $items = [];

        foreach ($rows as $row) {
            $type = $typeColumn
                ? (string) ($row[$typeColumn] ?? $defaultType)
                : (string) $defaultType;

            $id = (int) ($row['entity_id'] ?? 0);
            $key = $type . ':' . $id;

            if (!isset($items[$key])) {
                $items[$key] = [
                    'type' => $type,
                    'id' => $id,
                    'name' => (string) ($row['entity_name'] ?? ''),
                    'missing_languages' => [],
                    'url' => $url
                ];
            }

            $code = strtolower(
                trim((string) ($row['language_code'] ?? ''))
            );

            if ($code !== '') {
                $items[$key]['missing_languages'][] = $code;
            }
        }

        return array_values($items);
    }


    private function normalizeMissingFilters(
        $section,
        array $filters,
        array $targetLanguages
    ) {
        $language = strtolower(
            $this->filterValue($filters['language'] ?? '', 16)
        );

        $targetCodes = [];

        foreach ($targetLanguages as $targetLanguage) {
            $code = strtolower(
                trim((string) ($targetLanguage['code'] ?? ''))
            );

            if ($code !== '') {
                $targetCodes[] = $code;
            }
        }

        if (!in_array($language, $targetCodes, true)) {
            $language = '';
        }

        return [
            'language' => $language,
            'key' => $section === 'interface'
                ? $this->filterValue($filters['key'] ?? '', 190)
                : '',
            'source' => $this->filterValue(
                $filters['source'] ?? '',
                250
            )
        ];
    }


    private function filterMissingItems(array $items, array $filters)
    {
        $language = (string) ($filters['language'] ?? '');
        $key = (string) ($filters['key'] ?? '');
        $source = (string) ($filters['source'] ?? '');

        return array_values(
            array_filter(
                $items,
                function ($item) use ($language, $key, $source) {
                    $missingLanguages = array_map(
                        'strtolower',
                        array_map(
                            'strval',
                            $item['missing_languages'] ?? []
                        )
                    );

                    if (
                        $language !== ''
                        && !in_array(
                            $language,
                            $missingLanguages,
                            true
                        )
                    ) {
                        return false;
                    }

                    if (
                        $key !== ''
                        && !$this->containsText(
                            (string) ($item['key'] ?? ''),
                            $key
                        )
                    ) {
                        return false;
                    }

                    return $source === ''
                        || $this->containsText(
                            (string) ($item['name'] ?? ''),
                            $source
                        );
                }
            )
        );
    }


    private function filterValue($value, $limit)
    {
        if (!is_scalar($value)) {
            return '';
        }

        $value = trim((string) $value);
        $limit = max(1, (int) $limit);

        if (function_exists('mb_substr')) {
            return (string) mb_substr($value, 0, $limit, 'UTF-8');
        }

        return substr($value, 0, $limit);
    }


    private function containsText($haystack, $needle)
    {
        $haystack = (string) $haystack;
        $needle = (string) $needle;

        if ($needle === '') {
            return true;
        }

        if (function_exists('mb_stripos')) {
            return mb_stripos(
                $haystack,
                $needle,
                0,
                'UTF-8'
            ) !== false;
        }

        return stripos($haystack, $needle) !== false;
    }


    private function buildCoverage(array $targetLanguages)
    {
        $db = Database::connect();

        $languageCodes = [];

        foreach ($targetLanguages as $language) {
            $code = strtolower(
                trim((string) ($language['code'] ?? ''))
            );

            if ($code !== '') {
                $languageCodes[] = $code;
            }
        }

        $targetLanguageCount = count($languageCodes);

        $categoryCount = (int) $db
            ->query('SELECT COUNT(*) FROM categories')
            ->fetchColumn();

        $productCount = (int) $db
            ->query('SELECT COUNT(*) FROM products')
            ->fetchColumn();

        $deliveryEntityCount = (int) $db
            ->query("
                SELECT
                    (SELECT COUNT(*) FROM delivery_methods)
                    +
                    (
                        SELECT COUNT(*)
                        FROM delivery_services AS s
                        INNER JOIN delivery_methods AS m
                            ON m.id = s.delivery_method_id
                    )
                    +
                    (
                        SELECT COUNT(*)
                        FROM delivery_service_options AS o
                        INNER JOIN delivery_services AS s
                            ON s.id = o.delivery_service_id
                        INNER JOIN delivery_methods AS m
                            ON m.id = s.delivery_method_id
                    )
            ")
            ->fetchColumn();

        $deliveryInputCount = (int) $db
            ->query("
                SELECT COUNT(*)
                FROM delivery_option_inputs AS i
                INNER JOIN delivery_service_options AS o
                    ON o.id = i.option_id
                INNER JOIN delivery_services AS s
                    ON s.id = o.delivery_service_id
                INNER JOIN delivery_methods AS m
                    ON m.id = s.delivery_method_id
                WHERE i.is_enabled = 1
                  AND (
                        TRIM(COALESCE(i.field_label, '')) <> ''
                        OR
                        TRIM(COALESCE(i.placeholder, '')) <> ''
                  )
            ")
            ->fetchColumn();

        $interfaceKeyCount = (int) $db
            ->query("
                SELECT COUNT(*)
                FROM interface_translations
                WHERE language_code = 'uk'
                  AND TRIM(value) <> ''
            ")
            ->fetchColumn();

        $categoryRequired =
            $categoryCount * $targetLanguageCount;

        $productRequired =
            $productCount * $targetLanguageCount;

        $deliveryRequired =
            ($deliveryEntityCount + $deliveryInputCount)
            * $targetLanguageCount;

        $interfaceRequired =
            $interfaceKeyCount * $targetLanguageCount;

        if ($targetLanguageCount === 0) {
            return [
                $this->coverageItem(
                    'products',
                    'Товары',
                    $productCount,
                    0,
                    0,
                    '/Anabelka/admin/products'
                ),
                $this->coverageItem(
                    'categories',
                    'Категории',
                    $categoryCount,
                    0,
                    0,
                    '/Anabelka/admin/categories'
                ),
                $this->coverageItem(
                    'delivery',
                    'Delivery',
                    $deliveryEntityCount + $deliveryInputCount,
                    0,
                    0,
                    '/Anabelka/admin/delivery'
                ),
                $this->coverageItem(
                    'interface',
                    'Интерфейс',
                    $interfaceKeyCount,
                    0,
                    0,
                    null
                )
            ];
        }

        $languageList = implode(
            ', ',
            array_map(
                function ($code) use ($db) {
                    return $db->quote($code);
                },
                $languageCodes
            )
        );

        $productTranslated = (int) $db
            ->query("
                SELECT COUNT(*)
                FROM product_translations
                WHERE language_code IN ($languageList)
                  AND status = 'approved'
                  AND TRIM(name) <> ''
            ")
            ->fetchColumn();

        $categoryTranslated = (int) $db
            ->query("
                SELECT COUNT(*)
                FROM category_translations
                WHERE language_code IN ($languageList)
                  AND status = 'approved'
                  AND TRIM(name) <> ''
            ")
            ->fetchColumn();

        $deliveryTranslated = (int) $db
            ->query("
                SELECT COUNT(*)
                FROM delivery_translations AS t
                INNER JOIN (
                    SELECT
                        'method' AS entity_type,
                        m.id AS entity_id
                    FROM delivery_methods AS m

                    UNION ALL

                    SELECT
                        'service' AS entity_type,
                        s.id AS entity_id
                    FROM delivery_services AS s
                    INNER JOIN delivery_methods AS m
                        ON m.id = s.delivery_method_id

                    UNION ALL

                    SELECT
                        'option' AS entity_type,
                        o.id AS entity_id
                    FROM delivery_service_options AS o
                    INNER JOIN delivery_services AS s
                        ON s.id = o.delivery_service_id
                    INNER JOIN delivery_methods AS m
                        ON m.id = s.delivery_method_id
                ) AS e
                    ON e.entity_type = t.entity_type
                   AND e.entity_id = t.entity_id
                WHERE t.language_code IN ($languageList)
                  AND t.status = 'approved'
                  AND TRIM(t.name) <> ''
            ")
            ->fetchColumn();

        $deliveryInputTranslated = (int) $db
            ->query("
                SELECT COUNT(*)
                FROM delivery_option_input_translations AS t
                INNER JOIN delivery_option_inputs AS i
                    ON i.option_id = t.option_id
                INNER JOIN delivery_service_options AS o
                    ON o.id = i.option_id
                INNER JOIN delivery_services AS s
                    ON s.id = o.delivery_service_id
                INNER JOIN delivery_methods AS m
                    ON m.id = s.delivery_method_id
                WHERE i.is_enabled = 1
                  AND t.language_code IN ($languageList)
                  AND (
                        TRIM(COALESCE(i.field_label, '')) = ''
                        OR
                        TRIM(COALESCE(t.field_label, '')) <> ''
                  )
                  AND (
                        TRIM(COALESCE(i.placeholder, '')) = ''
                        OR
                        TRIM(COALESCE(t.placeholder, '')) <> ''
                  )
            ")
            ->fetchColumn();

        $interfaceTranslated = (int) $db
            ->query("
                SELECT COUNT(*)
                FROM interface_translations AS t
                INNER JOIN interface_translations AS source
                    ON source.translation_key = t.translation_key
                   AND source.language_code = 'uk'
                WHERE t.language_code IN ($languageList)
                  AND t.status = 'approved'
                  AND TRIM(t.value) <> ''
                  AND TRIM(source.value) <> ''
            ")
            ->fetchColumn();

        return [
            $this->coverageItem(
                'products',
                'Товары',
                $productCount,
                $productRequired,
                $productTranslated,
                '/Anabelka/admin/products'
            ),
            $this->coverageItem(
                'categories',
                'Категории',
                $categoryCount,
                $categoryRequired,
                $categoryTranslated,
                '/Anabelka/admin/categories'
            ),
            $this->coverageItem(
                'delivery',
                'Delivery',
                $deliveryEntityCount + $deliveryInputCount,
                $deliveryRequired,
                $deliveryTranslated + $deliveryInputTranslated,
                '/Anabelka/admin/delivery'
            ),
            $this->coverageItem(
                'interface',
                'Интерфейс',
                $interfaceKeyCount,
                $interfaceRequired,
                $interfaceTranslated,
                null
            )
        ];
    }


    private function coverageItem(
        $section,
        $label,
        $entityCount,
        $required,
        $translated,
        $url
    ) {
        $required = max(0, (int) $required);
        $translated = max(0, min((int) $translated, $required));
        $missing = max(0, $required - $translated);

        $percent = $required > 0
            ? (int) round(($translated / $required) * 100)
            : 100;

        return [
            'section' => (string) $section,
            'label' => (string) $label,
            'entity_count' => (int) $entityCount,
            'required' => $required,
            'translated' => $translated,
            'missing' => $missing,
            'percent' => $percent,
            'url' => $url
        ];
    }
}
