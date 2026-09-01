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
        CategoryTranslator::getForCategory(0);
        ProductTranslator::getForProduct(0);
        DeliveryTranslator::getForEntity('method', 0);
        DeliveryOptionInput::getForOption(0);

        $aiService = new AITranslationService();

        return [
            'sourceLanguage' =>
                Language::findByCode(Language::SOURCE_CODE),
            'languages' => $languages,
            'targetLanguages' => $targetLanguages,
            'providers' => $aiService->getProviders(),
            'selectedProvider' =>
                $aiService->getDefaultProviderCode(),
            'coverage' =>
                $this->buildCoverage($targetLanguages)
        ];
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
                    (SELECT COUNT(*) FROM delivery_services)
                    +
                    (SELECT COUNT(*) FROM delivery_service_options)
            ")
            ->fetchColumn();

        $deliveryInputCount = (int) $db
            ->query("
                SELECT COUNT(*)
                FROM delivery_option_inputs
                WHERE is_enabled = 1
                  AND (
                        TRIM(COALESCE(field_label, '')) <> ''
                        OR
                        TRIM(COALESCE(placeholder, '')) <> ''
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
                    'Товары',
                    $productCount,
                    0,
                    0,
                    '/Anabelka/admin/products'
                ),
                $this->coverageItem(
                    'Категории',
                    $categoryCount,
                    0,
                    0,
                    '/Anabelka/admin/categories'
                ),
                $this->coverageItem(
                    'Delivery',
                    $deliveryEntityCount + $deliveryInputCount,
                    0,
                    0,
                    '/Anabelka/admin/delivery'
                ),
                $this->coverageItem(
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
                FROM delivery_translations
                WHERE language_code IN ($languageList)
                  AND status = 'approved'
                  AND TRIM(name) <> ''
            ")
            ->fetchColumn();

        $deliveryInputTranslated = (int) $db
            ->query("
                SELECT COUNT(*)
                FROM delivery_option_input_translations AS t
                INNER JOIN delivery_option_inputs AS i
                    ON i.option_id = t.option_id
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
            ")
            ->fetchColumn();

        return [
            $this->coverageItem(
                'Товары',
                $productCount,
                $productRequired,
                $productTranslated,
                '/Anabelka/admin/products'
            ),
            $this->coverageItem(
                'Категории',
                $categoryCount,
                $categoryRequired,
                $categoryTranslated,
                '/Anabelka/admin/categories'
            ),
            $this->coverageItem(
                'Delivery',
                $deliveryEntityCount + $deliveryInputCount,
                $deliveryRequired,
                $deliveryTranslated + $deliveryInputTranslated,
                '/Anabelka/admin/delivery'
            ),
            $this->coverageItem(
                'Интерфейс',
                $interfaceKeyCount,
                $interfaceRequired,
                $interfaceTranslated,
                null
            )
        ];
    }


    private function coverageItem(
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
