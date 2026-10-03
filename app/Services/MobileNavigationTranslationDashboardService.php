<?php

class MobileNavigationTranslationDashboardService extends TranslationDashboardService
{
    private const SECTION = 'mobile_navigation';
    private const ENTITY_TYPE = 'mobile_navigation_item';
    private const EDITOR_URL = '/Anabelka/admin/mobile-navigation';


    public function getDashboardData()
    {
        $data = parent::getDashboardData();
        $targetLanguages = is_array($data['targetLanguages'] ?? null)
            ? $data['targetLanguages']
            : [];

        try {
            $mobileCoverage = $this->buildMobileCoverage($targetLanguages);
            $data['coverage'][] = $mobileCoverage;
            $data['languageCoverage'] = $this->augmentLanguageCoverage(
                is_array($data['languageCoverage'] ?? null)
                    ? $data['languageCoverage']
                    : [],
                $targetLanguages,
                (int) ($mobileCoverage['entity_count'] ?? 0),
                $this->missingMobileNavigation()
            );
        } catch (Throwable $e) {
            error_log(
                'Mobile navigation translation dashboard: '
                . $e->getMessage()
            );

            $data['coverage'][] = [
                'section' => self::SECTION,
                'label' => 'Мобільне меню — потрібна міграція',
                'entity_count' => 0,
                'required' => 1,
                'translated' => 0,
                'missing' => 1,
                'percent' => 0,
                'url' => self::EDITOR_URL,
                'unavailable' => true
            ];
        }

        return $data;
    }


    public function getMissingTranslations($section, array $filters = [])
    {
        $section = strtolower(trim((string) $section));

        if ($section !== self::SECTION) {
            return parent::getMissingTranslations($section, $filters);
        }

        $targetLanguages = $this->targetLanguages();
        $normalizedFilters = $this->normalizeFilters(
            $filters,
            $targetLanguages
        );

        try {
            $allItems = $this->missingMobileNavigation();
            $items = $this->filterItems(
                $allItems,
                $normalizedFilters
            );

            return [
                'section' => self::SECTION,
                'sectionLabel' => 'Мобільне меню',
                'sectionUrl' => self::EDITOR_URL,
                'targetLanguages' => $targetLanguages,
                'items' => $items,
                'totalItems' => count($allItems),
                'filters' => $normalizedFilters
            ];
        } catch (Throwable $e) {
            error_log(
                'Mobile navigation missing translations: '
                . $e->getMessage()
            );

            return [
                'section' => self::SECTION,
                'sectionLabel' => 'Мобільне меню',
                'sectionUrl' => self::EDITOR_URL,
                'targetLanguages' => $targetLanguages,
                'items' => [],
                'totalItems' => 0,
                'filters' => $normalizedFilters,
                'missingError' =>
                    'Мобільне меню ще не підготовлено. '
                    . 'Спочатку застосуйте його ручну міграцію.'
            ];
        }
    }


    public function missingMobileNavigation()
    {
        $db = Database::connect();
        $sourceCode = $db->quote(Language::SOURCE_CODE);
        $rows = $db->query("
            SELECT
                i.id AS entity_id,
                i.name_uk AS entity_name,
                l.code AS language_code,
                t.source AS translation_source,
                t.status AS translation_status,
                CASE
                    WHEN t.item_id IS NOT NULL
                     AND TRIM(COALESCE(t.name, '')) <> ''
                    THEN 1
                    ELSE 0
                END AS translation_has_content
            FROM mobile_navigation_items AS i
            INNER JOIN languages AS l
                ON l.is_active = 1
               AND l.code <> {$sourceCode}
            LEFT JOIN mobile_navigation_item_translations AS t
                ON t.item_id = i.id
               AND t.language_code = l.code
            WHERE t.item_id IS NULL
               OR TRIM(COALESCE(t.name, '')) = ''
               OR COALESCE(t.status, '') <> 'approved'
            ORDER BY
                i.sort_order ASC,
                i.id ASC,
                l.sort_order ASC,
                l.id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $items = [];

        foreach ($rows as $row) {
            $id = (int) ($row['entity_id'] ?? 0);

            if ($id <= 0) {
                continue;
            }

            if (!isset($items[$id])) {
                $items[$id] = [
                    'type' => self::ENTITY_TYPE,
                    'id' => $id,
                    'name' => (string) ($row['entity_name'] ?? ''),
                    'missing_languages' => [],
                    'language_states' => [],
                    'url' => self::EDITOR_URL
                        . '?highlight=' . $id
                ];
            }

            $code = strtolower(
                trim((string) ($row['language_code'] ?? ''))
            );

            if ($code === '') {
                continue;
            }

            $items[$id]['missing_languages'][] = $code;
            $items[$id]['language_states'][$code] =
                TranslationWorkflow::stateCode(
                    $row['translation_status'] ?? '',
                    $row['translation_source'] ?? '',
                    !empty($row['translation_has_content'])
                );
        }

        foreach ($items as &$item) {
            $focusLanguage = strtolower(
                trim((string) (($item['missing_languages'][0] ?? '')))
            );

            if ($focusLanguage !== '') {
                $item['url'] .= '&focus_language='
                    . rawurlencode($focusLanguage);
            }
        }
        unset($item);

        return array_values($items);
    }


    private function buildMobileCoverage(array $targetLanguages)
    {
        $db = Database::connect();
        $entityCount = (int) $db
            ->query('SELECT COUNT(*) FROM mobile_navigation_items')
            ->fetchColumn();
        $languageCodes = [];

        foreach ($targetLanguages as $language) {
            $code = strtolower(
                trim((string) ($language['code'] ?? ''))
            );

            if ($code !== '' && $code !== Language::SOURCE_CODE) {
                $languageCodes[] = $code;
            }
        }

        $required = $entityCount * count($languageCodes);
        $translated = 0;

        if (!empty($languageCodes)) {
            $languageList = implode(
                ', ',
                array_map(
                    function ($code) use ($db) {
                        return $db->quote($code);
                    },
                    $languageCodes
                )
            );

            $translated = (int) $db->query("
                SELECT COUNT(*)
                FROM mobile_navigation_item_translations AS t
                INNER JOIN mobile_navigation_items AS i
                    ON i.id = t.item_id
                WHERE t.language_code IN ({$languageList})
                  AND t.status = 'approved'
                  AND TRIM(t.name) <> ''
            ")->fetchColumn();
        }

        $translated = max(0, min($translated, $required));
        $missing = max(0, $required - $translated);
        $percent = $required > 0
            ? (int) round(($translated / $required) * 100)
            : 100;

        return [
            'section' => self::SECTION,
            'label' => 'Мобільне меню',
            'entity_count' => $entityCount,
            'required' => $required,
            'translated' => $translated,
            'missing' => $missing,
            'percent' => $percent,
            'url' => self::EDITOR_URL
        ];
    }


    private function augmentLanguageCoverage(
        array $languageCoverage,
        array $targetLanguages,
        $entityCount,
        array $attentionItems
    ) {
        $statesByLanguage = [];

        foreach ($attentionItems as $item) {
            foreach (($item['language_states'] ?? []) as $code => $state) {
                $code = strtolower(trim((string) $code));
                $state = (string) $state;

                if ($code === '') {
                    continue;
                }

                if (!isset($statesByLanguage[$code])) {
                    $statesByLanguage[$code] = [];
                }

                $statesByLanguage[$code][$state] =
                    (int) ($statesByLanguage[$code][$state] ?? 0) + 1;
            }
        }

        $byCode = [];

        foreach ($languageCoverage as $index => $item) {
            $code = strtolower(trim((string) ($item['code'] ?? '')));

            if ($code !== '') {
                $byCode[$code] = $index;
            }
        }

        foreach ($targetLanguages as $language) {
            $code = strtolower(
                trim((string) ($language['code'] ?? ''))
            );

            if ($code === '' || !isset($byCode[$code])) {
                continue;
            }

            $index = $byCode[$code];
            $existingStates = is_array(
                $languageCoverage[$index]['states'] ?? null
            )
                ? $languageCoverage[$index]['states']
                : [];
            $addedStates = $statesByLanguage[$code] ?? [];
            $addedAttention = 0;

            foreach ([
                'missing',
                'ai_draft',
                'manual_draft',
                'review',
                'outdated'
            ] as $state) {
                $count = (int) ($addedStates[$state] ?? 0);
                $existingStates[$state] =
                    (int) ($existingStates[$state] ?? 0) + $count;
                $addedAttention += $count;
            }

            $required = (int) (
                $languageCoverage[$index]['required'] ?? 0
            ) + (int) $entityCount;
            $approved = (int) (
                $languageCoverage[$index]['approved'] ?? 0
            ) + max(0, (int) $entityCount - $addedAttention);
            $attention = array_sum($existingStates);

            $languageCoverage[$index]['required'] = $required;
            $languageCoverage[$index]['approved'] = min(
                $approved,
                $required
            );
            $languageCoverage[$index]['attention'] = $attention;
            $languageCoverage[$index]['states'] = $existingStates;
            $languageCoverage[$index]['percent'] = $required > 0
                ? (int) round(
                    ($languageCoverage[$index]['approved'] / $required) * 100
                )
                : 100;
        }

        return $languageCoverage;
    }


    private function targetLanguages()
    {
        return array_values(
            array_filter(
                Language::active(),
                function ($language) {
                    $code = strtolower(
                        trim((string) ($language['code'] ?? ''))
                    );

                    return $code !== ''
                        && $code !== Language::SOURCE_CODE;
                }
            )
        );
    }


    private function normalizeFilters(
        array $filters,
        array $targetLanguages
    ) {
        $language = strtolower(
            $this->filterValue($filters['language'] ?? '', 16)
        );
        $targetCodes = [];

        foreach ($targetLanguages as $languageRow) {
            $code = strtolower(
                trim((string) ($languageRow['code'] ?? ''))
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
            'key' => '',
            'source' => $this->filterValue(
                $filters['source'] ?? '',
                250
            )
        ];
    }


    private function filterItems(array $items, array $filters)
    {
        $language = (string) ($filters['language'] ?? '');
        $source = (string) ($filters['source'] ?? '');

        return array_values(
            array_filter(
                $items,
                function ($item) use ($language, $source) {
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
}
