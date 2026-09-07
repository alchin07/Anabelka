<?php

class SearchInterfaceTranslator
{
    private static $seeded = false;


    public static function seed()
    {
        if (self::$seeded) {
            return;
        }

        Translator::currentLanguage();
        $db = Database::connect();

        $translations = [
            'uk' => [
                'search.placeholder' => 'Пошук товарів, категорій, SKU…',
                'search.button' => 'Знайти',
                'search.title' => 'Пошук',
                'search.results_for' => 'Результати пошуку для',
                'search.categories' => 'Категорії',
                'search.products' => 'Товари',
                'search.empty' => 'Нічого не знайдено. Спробуйте інший запит.',
                'search.start' => 'Введіть назву товару, категорію або SKU.',
                'search.found' => 'Знайдено',
                'search.suggest_all' => 'Показати всі результати'
            ],
            'ru' => [
                'search.placeholder' => 'Поиск товаров, категорий, SKU…',
                'search.button' => 'Найти',
                'search.title' => 'Поиск',
                'search.results_for' => 'Результаты поиска для',
                'search.categories' => 'Категории',
                'search.products' => 'Товары',
                'search.empty' => 'Ничего не найдено. Попробуйте другой запрос.',
                'search.start' => 'Введите название товара, категорию или SKU.',
                'search.found' => 'Найдено',
                'search.suggest_all' => 'Показать все результаты'
            ],
            'en' => [
                'search.placeholder' => 'Search products, categories, SKU…',
                'search.button' => 'Search',
                'search.title' => 'Search',
                'search.results_for' => 'Search results for',
                'search.categories' => 'Categories',
                'search.products' => 'Products',
                'search.empty' => 'Nothing found. Try a different query.',
                'search.start' => 'Enter a product name, category or SKU.',
                'search.found' => 'Found',
                'search.suggest_all' => 'Show all results'
            ]
        ];

        $stmt = $db->prepare("
            INSERT IGNORE INTO interface_translations
            (translation_key, language_code, value, source, status)
            VALUES
            (:translation_key, :language_code, :value, 'manual', 'approved')
        ");

        foreach ($translations as $languageCode => $items) {
            foreach ($items as $key => $value) {
                $stmt->execute([
                    'translation_key' => $key,
                    'language_code' => $languageCode,
                    'value' => $value
                ]);
            }
        }

        self::$seeded = true;
    }
}
