<?php

class FavoriteInterfaceTranslator
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
                'favorite.title' => 'Обране',
                'favorite.add' => 'Додати до обраного',
                'favorite.remove' => 'Видалити з обраного',
                'favorite.empty' => 'В обраному поки немає товарів.',
                'favorite.empty_text' => 'Додавайте товари сердечком, щоб повернутися до них пізніше.',
                'favorite.go_catalog' => 'Перейти до каталогу'
            ],
            'ru' => [
                'favorite.title' => 'Избранное',
                'favorite.add' => 'Добавить в избранное',
                'favorite.remove' => 'Удалить из избранного',
                'favorite.empty' => 'В избранном пока нет товаров.',
                'favorite.empty_text' => 'Добавляйте товары сердечком, чтобы вернуться к ним позже.',
                'favorite.go_catalog' => 'Перейти в каталог'
            ],
            'en' => [
                'favorite.title' => 'Favorites',
                'favorite.add' => 'Add to favorites',
                'favorite.remove' => 'Remove from favorites',
                'favorite.empty' => 'There are no favorite products yet.',
                'favorite.empty_text' => 'Use the heart button to save products for later.',
                'favorite.go_catalog' => 'Go to catalog'
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
