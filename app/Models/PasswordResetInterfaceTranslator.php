<?php

class PasswordResetInterfaceTranslator
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
                'public.password_reset.forgot_link' => 'Забули пароль?',
                'public.password_reset.request_title' => 'Відновлення пароля',
                'public.password_reset.request_heading' => 'Забули пароль?',
                'public.password_reset.request_hint' => 'Вкажіть email вашого акаунта. Ми надішлемо одноразове посилання для створення нового пароля.',
                'public.password_reset.request_button' => 'Надіслати посилання',
                'public.password_reset.request_done' => 'Якщо акаунт з таким email існує, посилання для відновлення пароля надіслано.',
                'public.password_reset.local_preview' => 'Локальний тест: відкрити посилання відновлення',
                'public.password_reset.back_login' => 'Повернутися до входу',
                'public.password_reset.reset_title' => 'Новий пароль',
                'public.password_reset.reset_heading' => 'Створіть новий пароль',
                'public.password_reset.reset_hint' => 'Посилання діє одну годину та може бути використане лише один раз.',
                'public.password_reset.new_password' => 'Новий пароль',
                'public.password_reset.confirm_password' => 'Повторіть новий пароль',
                'public.password_reset.reset_button' => 'Змінити пароль',
                'public.password_reset.password_mismatch' => 'Паролі не збігаються.',
                'public.password_reset.invalid_email' => 'Вкажіть коректний email.',
                'public.password_reset.invalid_link' => 'Посилання відновлення недійсне, використане або строк його дії завершився.',
                'public.password_reset.success' => 'Пароль змінено. Увійдіть з новим паролем.'
            ],
            'ru' => [
                'public.password_reset.forgot_link' => 'Забыли пароль?',
                'public.password_reset.request_title' => 'Восстановление пароля',
                'public.password_reset.request_heading' => 'Забыли пароль?',
                'public.password_reset.request_hint' => 'Укажите email вашего аккаунта. Мы отправим одноразовую ссылку для создания нового пароля.',
                'public.password_reset.request_button' => 'Отправить ссылку',
                'public.password_reset.request_done' => 'Если аккаунт с таким email существует, ссылка для восстановления пароля отправлена.',
                'public.password_reset.local_preview' => 'Локальный тест: открыть ссылку восстановления',
                'public.password_reset.back_login' => 'Вернуться ко входу',
                'public.password_reset.reset_title' => 'Новый пароль',
                'public.password_reset.reset_heading' => 'Создайте новый пароль',
                'public.password_reset.reset_hint' => 'Ссылка действует один час и может быть использована только один раз.',
                'public.password_reset.new_password' => 'Новый пароль',
                'public.password_reset.confirm_password' => 'Повторите новый пароль',
                'public.password_reset.reset_button' => 'Изменить пароль',
                'public.password_reset.password_mismatch' => 'Пароли не совпадают.',
                'public.password_reset.invalid_email' => 'Укажите корректный email.',
                'public.password_reset.invalid_link' => 'Ссылка восстановления недействительна, использована или срок её действия истёк.',
                'public.password_reset.success' => 'Пароль изменён. Войдите с новым паролем.'
            ],
            'en' => [
                'public.password_reset.forgot_link' => 'Forgot password?',
                'public.password_reset.request_title' => 'Password recovery',
                'public.password_reset.request_heading' => 'Forgot your password?',
                'public.password_reset.request_hint' => 'Enter the email address for your account. We will send a one-time link to create a new password.',
                'public.password_reset.request_button' => 'Send reset link',
                'public.password_reset.request_done' => 'If an account with this email exists, a password reset link has been sent.',
                'public.password_reset.local_preview' => 'Local test: open password reset link',
                'public.password_reset.back_login' => 'Back to sign in',
                'public.password_reset.reset_title' => 'New password',
                'public.password_reset.reset_heading' => 'Create a new password',
                'public.password_reset.reset_hint' => 'The link is valid for one hour and can only be used once.',
                'public.password_reset.new_password' => 'New password',
                'public.password_reset.confirm_password' => 'Repeat new password',
                'public.password_reset.reset_button' => 'Change password',
                'public.password_reset.password_mismatch' => 'Passwords do not match.',
                'public.password_reset.invalid_email' => 'Enter a valid email address.',
                'public.password_reset.invalid_link' => 'The password reset link is invalid, already used, or has expired.',
                'public.password_reset.success' => 'Password changed. Sign in with your new password.'
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
