<?php

class PublicInterfaceTranslator
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
                'public.home.shop' => 'Інтернет-магазин',
                'public.home.catalog' => 'Перейти до каталогу',
                'public.catalog.title' => 'Каталог',
                'public.catalog.categories' => 'Категорії',
                'public.catalog.products' => 'Товари',
                'public.catalog.subcategories' => 'Підкатегорії',
                'public.catalog.product_photo' => 'Фото товару',
                'public.catalog.product_name' => 'Назва товару',
                'public.catalog.home' => 'На головну',
                'public.catalog.empty' => 'У цій категорії поки немає товарів.',
                'public.auth.login_title' => 'Вхід',
                'public.auth.login_heading' => 'Увійти до акаунта',
                'public.auth.password' => 'Пароль',
                'public.auth.login_button' => 'Увійти',
                'public.auth.no_account' => 'Немає акаунта?',
                'public.auth.register_link' => 'Зареєструватися',
                'public.auth.register_title' => 'Реєстрація',
                'public.auth.register_heading' => 'Створити акаунт',
                'public.auth.name' => 'Ім’я',
                'public.auth.register_button' => 'Зареєструватися',
                'public.auth.have_account' => 'Вже є акаунт?',
                'public.order.success_title' => 'Замовлення оформлено',
                'public.order.thanks' => 'Дякуємо за замовлення!',
                'public.order.success_text' => 'Ваше замовлення успішно оформлено.',
                'public.order.number' => 'Номер замовлення',
                'public.order.sum' => 'Сума',
                'public.order.continue' => 'Продовжити покупки',
                'public.order.error_title' => 'Помилка замовлення',
                'public.order.order' => 'Замовлення',
                'public.order.not_found' => 'Замовлення не знайдено',
                'public.order.not_found_text' => 'Можливо, посилання застаріло або було змінено.',
                'public.order.back_catalog' => 'Повернутися до каталогу'
            ],
            'ru' => [
                'public.home.shop' => 'Интернет-магазин',
                'public.home.catalog' => 'Перейти в каталог',
                'public.catalog.title' => 'Каталог',
                'public.catalog.categories' => 'Категории',
                'public.catalog.products' => 'Товары',
                'public.catalog.subcategories' => 'Подкатегории',
                'public.catalog.product_photo' => 'Фото товара',
                'public.catalog.product_name' => 'Название товара',
                'public.catalog.home' => 'На главную',
                'public.catalog.empty' => 'В этой категории пока нет товаров.',
                'public.auth.login_title' => 'Вход',
                'public.auth.login_heading' => 'Войти в аккаунт',
                'public.auth.password' => 'Пароль',
                'public.auth.login_button' => 'Войти',
                'public.auth.no_account' => 'Нет аккаунта?',
                'public.auth.register_link' => 'Зарегистрироваться',
                'public.auth.register_title' => 'Регистрация',
                'public.auth.register_heading' => 'Создать аккаунт',
                'public.auth.name' => 'Имя',
                'public.auth.register_button' => 'Зарегистрироваться',
                'public.auth.have_account' => 'Уже есть аккаунт?',
                'public.order.success_title' => 'Заказ оформлен',
                'public.order.thanks' => 'Спасибо за заказ!',
                'public.order.success_text' => 'Ваш заказ успешно оформлен.',
                'public.order.number' => 'Номер заказа',
                'public.order.sum' => 'Сумма',
                'public.order.continue' => 'Продолжить покупки',
                'public.order.error_title' => 'Ошибка заказа',
                'public.order.order' => 'Заказ',
                'public.order.not_found' => 'Заказ не найден',
                'public.order.not_found_text' => 'Возможно, ссылка устарела или была изменена.',
                'public.order.back_catalog' => 'Вернуться в каталог'
            ],
            'en' => [
                'public.home.shop' => 'Online store',
                'public.home.catalog' => 'Go to catalog',
                'public.catalog.title' => 'Catalog',
                'public.catalog.categories' => 'Categories',
                'public.catalog.products' => 'Products',
                'public.catalog.subcategories' => 'Subcategories',
                'public.catalog.product_photo' => 'Product photo',
                'public.catalog.product_name' => 'Product name',
                'public.catalog.home' => 'Home',
                'public.catalog.empty' => 'There are no products in this category yet.',
                'public.auth.login_title' => 'Sign in',
                'public.auth.login_heading' => 'Sign in to your account',
                'public.auth.password' => 'Password',
                'public.auth.login_button' => 'Sign in',
                'public.auth.no_account' => 'No account?',
                'public.auth.register_link' => 'Register',
                'public.auth.register_title' => 'Registration',
                'public.auth.register_heading' => 'Create an account',
                'public.auth.name' => 'Name',
                'public.auth.register_button' => 'Register',
                'public.auth.have_account' => 'Already have an account?',
                'public.order.success_title' => 'Order placed',
                'public.order.thanks' => 'Thank you for your order!',
                'public.order.success_text' => 'Your order has been placed successfully.',
                'public.order.number' => 'Order number',
                'public.order.sum' => 'Total',
                'public.order.continue' => 'Continue shopping',
                'public.order.error_title' => 'Order error',
                'public.order.order' => 'Order',
                'public.order.not_found' => 'Order not found',
                'public.order.not_found_text' => 'The link may be outdated or may have changed.',
                'public.order.back_catalog' => 'Back to catalog'
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
