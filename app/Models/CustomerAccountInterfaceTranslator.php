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
                'public.account.edit_profile' => 'Змінити ім’я або email',
                'public.account.current_password' => 'Поточний пароль',
                'public.account.save_profile' => 'Зберегти дані',
                'public.account.change_password' => 'Змінити пароль',
                'public.account.new_password' => 'Новий пароль',
                'public.account.confirm_password' => 'Повторіть новий пароль',
                'public.account.password_hint' => 'Щонайменше 8 символів',
                'public.account.profile_saved' => 'Дані профілю збережено.',
                'public.account.password_saved' => 'Пароль успішно змінено.',
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
                'public.account.edit_profile' => 'Изменить имя или email',
                'public.account.current_password' => 'Текущий пароль',
                'public.account.save_profile' => 'Сохранить данные',
                'public.account.change_password' => 'Изменить пароль',
                'public.account.new_password' => 'Новый пароль',
                'public.account.confirm_password' => 'Повторите новый пароль',
                'public.account.password_hint' => 'Не менее 8 символов',
                'public.account.profile_saved' => 'Данные профиля сохранены.',
                'public.account.password_saved' => 'Пароль успешно изменён.',
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
                'public.account.edit_profile' => 'Change name or email',
                'public.account.current_password' => 'Current password',
                'public.account.save_profile' => 'Save details',
                'public.account.change_password' => 'Change password',
                'public.account.new_password' => 'New password',
                'public.account.confirm_password' => 'Repeat new password',
                'public.account.password_hint' => 'At least 8 characters',
                'public.account.profile_saved' => 'Profile details saved.',
                'public.account.password_saved' => 'Password changed successfully.',
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
