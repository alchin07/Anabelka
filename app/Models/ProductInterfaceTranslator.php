<?php

class ProductInterfaceTranslator
{
    private static $seeded = false;


    public static function seed()
    {
        if (self::$seeded) {
            return;
        }

        // Translator сам создаёт таблицу interface_translations.
        Translator::currentLanguage();

        $db = Database::connect();

        $translations = [
            'uk' => [
                'product.back_catalog' => 'Каталог',
                'product.photo' => 'Фото товару',
                'product.sku' => 'Артикул',
                'product.prices' => 'Ціни',
                'product.price' => 'Ціна',
                'product.personal_price' => 'Персональна ціна',
                'product.old_price' => 'Стара ціна',
                'product.brand' => 'Бренд',
                'product.country' => 'Країна',
                'product.description' => 'Опис',
                'product.choose_size' => 'Оберіть розмір',
                'product.in_stock' => 'В наявності',
                'product.out_of_stock' => 'Немає в наявності',
                'product.pcs' => 'шт.',
                'product.add_to_cart' => 'Додати вибране до кошика',
                'product.select_size' => 'Оберіть хоча б один розмір.',
                'product.added' => '✓ Товар додано до кошика',
                'product.add_error' => 'Не вдалося додати товар до кошика.',
                'product.php_error' => 'Помилка PHP. Дивіться текст нижче.',
                'product.size_sold_out' => 'Розмір {size} закінчився.',
                'product.stock_error' => 'Недостатньо товару на складі.'
            ],
            'ru' => [
                'product.back_catalog' => 'Каталог',
                'product.photo' => 'Фото товара',
                'product.sku' => 'Артикул',
                'product.prices' => 'Цены',
                'product.price' => 'Цена',
                'product.personal_price' => 'Персональная цена',
                'product.old_price' => 'Старая цена',
                'product.brand' => 'Бренд',
                'product.country' => 'Страна',
                'product.description' => 'Описание',
                'product.choose_size' => 'Выберите размер',
                'product.in_stock' => 'В наличии',
                'product.out_of_stock' => 'Нет в наличии',
                'product.pcs' => 'шт.',
                'product.add_to_cart' => 'Добавить выбранное в корзину',
                'product.select_size' => 'Выберите хотя бы один размер.',
                'product.added' => '✓ Товар добавлен в корзину',
                'product.add_error' => 'Не удалось добавить товар в корзину.',
                'product.php_error' => 'Ошибка PHP. Смотри текст ниже.',
                'product.size_sold_out' => 'Размер {size} закончился.',
                'product.stock_error' => 'Недостаточно товара на складе.'
            ],
            'en' => [
                'product.back_catalog' => 'Catalog',
                'product.photo' => 'Product photo',
                'product.sku' => 'SKU',
                'product.prices' => 'Prices',
                'product.price' => 'Price',
                'product.personal_price' => 'Personal price',
                'product.old_price' => 'Old price',
                'product.brand' => 'Brand',
                'product.country' => 'Country',
                'product.description' => 'Description',
                'product.choose_size' => 'Choose a size',
                'product.in_stock' => 'In stock',
                'product.out_of_stock' => 'Out of stock',
                'product.pcs' => 'pcs.',
                'product.add_to_cart' => 'Add selected to cart',
                'product.select_size' => 'Choose at least one size.',
                'product.added' => '✓ Item added to cart',
                'product.add_error' => 'Could not add the item to the cart.',
                'product.php_error' => 'PHP error. See details below.',
                'product.size_sold_out' => 'Size {size} is sold out.',
                'product.stock_error' => 'Not enough stock available.'
            ]
        ];

        $stmt = $db->prepare("
            INSERT IGNORE INTO interface_translations
            (
                translation_key,
                language_code,
                value,
                source,
                status
            )
            VALUES
            (
                :translation_key,
                :language_code,
                :value,
                'manual',
                'approved'
            )
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
