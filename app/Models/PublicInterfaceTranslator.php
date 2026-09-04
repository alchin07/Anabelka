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
                'public.catalog.color' => 'Колір',
                'public.catalog.material' => 'Матеріал',
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
                'public.auth.error_all_fields' => 'Заповніть усі поля.',
                'public.auth.error_email' => 'Некоректний email.',
                'public.auth.error_email_exists' => 'Користувач із таким email уже існує.',
                'public.auth.error_login_fields' => 'Заповніть email і пароль.',
                'public.auth.error_user_not_found' => 'Користувача не знайдено.',
                'public.auth.error_password' => 'Невірний пароль.',
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
                'public.order.back_catalog' => 'Повернутися до каталогу',
                'public.order.error_method' => 'Некоректний спосіб доставки.',
                'public.order.error_choose_service' => 'Оберіть службу доставки.',
                'public.order.error_service' => 'Некоректна служба доставки.',
                'public.order.error_choose_option' => 'Оберіть варіант отримання.',
                'public.order.error_option' => 'Некоректний варіант отримання.',
                'public.order.error_fill_field' => 'Заповніть поле «{field}».',
                'public.order.error_delivery_data' => 'Вкажіть дані доставки.',
                'public.order.error_name_email' => 'Заповніть ім’я та Email.',
                'public.order.error_email' => 'Некоректний Email.',
                'public.order.error_empty_cart' => 'Кошик порожній.',
                'public.404' => '404 — Сторінку не знайдено'
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
                'public.catalog.color' => 'Цвет',
                'public.catalog.material' => 'Материал',
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
                'public.auth.error_all_fields' => 'Заполните все поля.',
                'public.auth.error_email' => 'Некорректный email.',
                'public.auth.error_email_exists' => 'Пользователь с таким email уже существует.',
                'public.auth.error_login_fields' => 'Заполните email и пароль.',
                'public.auth.error_user_not_found' => 'Пользователь не найден.',
                'public.auth.error_password' => 'Неверный пароль.',
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
                'public.order.back_catalog' => 'Вернуться в каталог',
                'public.order.error_method' => 'Некорректный способ доставки.',
                'public.order.error_choose_service' => 'Выберите службу доставки.',
                'public.order.error_service' => 'Некорректная служба доставки.',
                'public.order.error_choose_option' => 'Выберите вариант получения.',
                'public.order.error_option' => 'Некорректный вариант получения.',
                'public.order.error_fill_field' => 'Заполните поле «{field}».',
                'public.order.error_delivery_data' => 'Укажите данные доставки.',
                'public.order.error_name_email' => 'Заполните имя и Email.',
                'public.order.error_email' => 'Некорректный Email.',
                'public.order.error_empty_cart' => 'Корзина пуста.',
                'public.404' => '404 — Страница не найдена'
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
                'public.catalog.color' => 'Color',
                'public.catalog.material' => 'Material',
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
                'public.auth.error_all_fields' => 'Fill in all fields.',
                'public.auth.error_email' => 'Invalid email address.',
                'public.auth.error_email_exists' => 'A user with this email already exists.',
                'public.auth.error_login_fields' => 'Enter your email and password.',
                'public.auth.error_user_not_found' => 'User not found.',
                'public.auth.error_password' => 'Incorrect password.',
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
                'public.order.back_catalog' => 'Back to catalog',
                'public.order.error_method' => 'Invalid delivery method.',
                'public.order.error_choose_service' => 'Choose a delivery service.',
                'public.order.error_service' => 'Invalid delivery service.',
                'public.order.error_choose_option' => 'Choose a delivery option.',
                'public.order.error_option' => 'Invalid delivery option.',
                'public.order.error_fill_field' => 'Fill in the “{field}” field.',
                'public.order.error_delivery_data' => 'Enter the delivery details.',
                'public.order.error_name_email' => 'Enter your name and Email.',
                'public.order.error_email' => 'Invalid Email.',
                'public.order.error_empty_cart' => 'Your cart is empty.',
                'public.404' => '404 — Page not found'
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
