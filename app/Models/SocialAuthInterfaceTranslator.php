<?php

class SocialAuthInterfaceTranslator
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
                'public.social_auth.or' => 'або',
                'public.social_auth.google' => 'Продовжити з Google',
                'public.social_auth.google_unavailable' => 'Вхід через Google ще не налаштовано',
                'public.social_auth.error_generic' => 'Не вдалося увійти через Google. Спробуйте ще раз.',
                'public.social_auth.complete_title' => 'Завершення реєстрації',
                'public.social_auth.complete_heading' => 'Завершіть реєстрацію через Google',
                'public.social_auth.complete_hint' => 'Google підтвердив ваш email. Для створення нового акаунта Анабельки потрібно прийняти умови.',
                'public.social_auth.continue' => 'Створити акаунт і продовжити'
            ],
            'ru' => [
                'public.social_auth.or' => 'или',
                'public.social_auth.google' => 'Продолжить с Google',
                'public.social_auth.google_unavailable' => 'Вход через Google ещё не настроен',
                'public.social_auth.error_generic' => 'Не удалось войти через Google. Попробуйте ещё раз.',
                'public.social_auth.complete_title' => 'Завершение регистрации',
                'public.social_auth.complete_heading' => 'Завершите регистрацию через Google',
                'public.social_auth.complete_hint' => 'Google подтвердил ваш email. Для создания нового аккаунта Анабельки нужно принять условия.',
                'public.social_auth.continue' => 'Создать аккаунт и продолжить'
            ],
            'en' => [
                'public.social_auth.or' => 'or',
                'public.social_auth.google' => 'Continue with Google',
                'public.social_auth.google_unavailable' => 'Google sign-in is not configured yet',
                'public.social_auth.error_generic' => 'Could not sign in with Google. Please try again.',
                'public.social_auth.complete_title' => 'Complete registration',
                'public.social_auth.complete_heading' => 'Complete registration with Google',
                'public.social_auth.complete_hint' => 'Google verified your email. Accept the terms to create your Anabelka account.',
                'public.social_auth.continue' => 'Create account and continue'
            ]
        ];

        $stmt = $db->prepare("\n            INSERT IGNORE INTO interface_translations\n            (translation_key, language_code, value, source, status)\n            VALUES\n            (:translation_key, :language_code, :value, 'manual', 'approved')\n        ");

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
