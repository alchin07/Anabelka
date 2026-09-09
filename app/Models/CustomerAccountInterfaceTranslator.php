<?php

class CustomerAccountInterfaceTranslator
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
                'public.account.title' => 'Мій акаунт',
                'public.account.heading' => 'Мій акаунт',
                'public.account.profile' => 'Профіль',
                'public.account.rank' => 'Мій ранг',
                'public.account.orders' => 'Мої замовлення',
                'public.account.favorites' => 'Обране',
                'public.account.edit_profile' => 'Змінити ім’я, email або телефон',
                'public.account.phone' => 'Телефон',
                'public.account.current_password' => 'Поточний пароль',
                'public.account.save_profile' => 'Зберегти дані',
                'public.account.change_password' => 'Змінити пароль',
                'public.account.new_password' => 'Новий пароль',
                'public.account.confirm_password' => 'Повторіть новий пароль',
                'public.account.password_hint' => 'Щонайменше 8 символів',
                'public.account.profile_saved' => 'Дані профілю збережено.',
                'public.account.password_saved' => 'Пароль успішно змінено.',
                'public.account.addresses' => 'Адреси доставки',
                'public.account.addresses_hint' => 'Основна адреса автоматично підставляється під час оформлення замовлення.',
                'public.account.address_add' => 'Додати адресу',
                'public.account.address_label' => 'Назва адреси',
                'public.account.address_label_placeholder' => 'Наприклад: Дім',
                'public.account.address_country' => 'Країна',
                'public.account.address_city' => 'Місто',
                'public.account.address_address' => 'Адреса',
                'public.account.address_postcode' => 'Поштовий індекс',
                'public.account.address_default' => 'Основна',
                'public.account.address_make_default' => 'Зробити основною',
                'public.account.address_edit' => 'Редагувати',
                'public.account.address_save' => 'Зберегти адресу',
                'public.account.address_delete' => 'Видалити',
                'public.account.address_empty' => 'Збережених адрес поки немає.',
                'public.account.address_added' => 'Адресу додано.',
                'public.account.address_saved' => 'Адресу збережено.',
                'public.account.address_default_saved' => 'Основну адресу змінено.',
                'public.account.address_deleted' => 'Адресу видалено.',
                'public.auth.confirm_password' => 'Повторіть пароль',
                'public.auth.password_hint' => 'Щонайменше 8 символів',
                'public.auth.error_password_mismatch' => 'Паролі не збігаються.',
                'public.auth.error_password_short' => 'Пароль має містити щонайменше 8 символів.',
                'public.auth.error_csrf' => 'Сесію форми застаріло. Оновіть сторінку та спробуйте ще раз.'
            ],
            'ru' => [
                'public.account.title' => 'Мой аккаунт',
                'public.account.heading' => 'Мой аккаунт',
                'public.account.profile' => 'Профиль',
                'public.account.rank' => 'Мой ранг',
                'public.account.orders' => 'Мои заказы',
                'public.account.favorites' => 'Избранное',
                'public.account.edit_profile' => 'Изменить имя, email или телефон',
                'public.account.phone' => 'Телефон',
                'public.account.current_password' => 'Текущий пароль',
                'public.account.save_profile' => 'Сохранить данные',
                'public.account.change_password' => 'Изменить пароль',
                'public.account.new_password' => 'Новый пароль',
                'public.account.confirm_password' => 'Повторите новый пароль',
                'public.account.password_hint' => 'Не менее 8 символов',
                'public.account.profile_saved' => 'Данные профиля сохранены.',
                'public.account.password_saved' => 'Пароль успешно изменён.',
                'public.account.addresses' => 'Адреса доставки',
                'public.account.addresses_hint' => 'Основной адрес автоматически подставляется при оформлении заказа.',
                'public.account.address_add' => 'Добавить адрес',
                'public.account.address_label' => 'Название адреса',
                'public.account.address_label_placeholder' => 'Например: Дом',
                'public.account.address_country' => 'Страна',
                'public.account.address_city' => 'Город',
                'public.account.address_address' => 'Адрес',
                'public.account.address_postcode' => 'Почтовый индекс',
                'public.account.address_default' => 'Основной',
                'public.account.address_make_default' => 'Сделать основным',
                'public.account.address_edit' => 'Редактировать',
                'public.account.address_save' => 'Сохранить адрес',
                'public.account.address_delete' => 'Удалить',
                'public.account.address_empty' => 'Сохранённых адресов пока нет.',
                'public.account.address_added' => 'Адрес добавлен.',
                'public.account.address_saved' => 'Адрес сохранён.',
                'public.account.address_default_saved' => 'Основной адрес изменён.',
                'public.account.address_deleted' => 'Адрес удалён.',
                'public.auth.confirm_password' => 'Повторите пароль',
                'public.auth.password_hint' => 'Не менее 8 символов',
                'public.auth.error_password_mismatch' => 'Пароли не совпадают.',
                'public.auth.error_password_short' => 'Пароль должен содержать не менее 8 символов.',
                'public.auth.error_csrf' => 'Сессия формы устарела. Обновите страницу и попробуйте ещё раз.'
            ],
            'en' => [
                'public.account.title' => 'My account',
                'public.account.heading' => 'My account',
                'public.account.profile' => 'Profile',
                'public.account.rank' => 'My rank',
                'public.account.orders' => 'My orders',
                'public.account.favorites' => 'Favorites',
                'public.account.edit_profile' => 'Change name, email or phone',
                'public.account.phone' => 'Phone',
                'public.account.current_password' => 'Current password',
                'public.account.save_profile' => 'Save details',
                'public.account.change_password' => 'Change password',
                'public.account.new_password' => 'New password',
                'public.account.confirm_password' => 'Repeat new password',
                'public.account.password_hint' => 'At least 8 characters',
                'public.account.profile_saved' => 'Profile details saved.',
                'public.account.password_saved' => 'Password changed successfully.',
                'public.account.addresses' => 'Delivery addresses',
                'public.account.addresses_hint' => 'The default address is filled automatically during checkout.',
                'public.account.address_add' => 'Add address',
                'public.account.address_label' => 'Address name',
                'public.account.address_label_placeholder' => 'For example: Home',
                'public.account.address_country' => 'Country',
                'public.account.address_city' => 'City',
                'public.account.address_address' => 'Address',
                'public.account.address_postcode' => 'Postal code',
                'public.account.address_default' => 'Default',
                'public.account.address_make_default' => 'Make default',
                'public.account.address_edit' => 'Edit',
                'public.account.address_save' => 'Save address',
                'public.account.address_delete' => 'Delete',
                'public.account.address_empty' => 'No saved addresses yet.',
                'public.account.address_added' => 'Address added.',
                'public.account.address_saved' => 'Address saved.',
                'public.account.address_default_saved' => 'Default address changed.',
                'public.account.address_deleted' => 'Address deleted.',
                'public.auth.confirm_password' => 'Repeat password',
                'public.auth.password_hint' => 'At least 8 characters',
                'public.auth.error_password_mismatch' => 'Passwords do not match.',
                'public.auth.error_password_short' => 'Password must contain at least 8 characters.',
                'public.auth.error_csrf' => 'The form session has expired. Refresh the page and try again.'
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
