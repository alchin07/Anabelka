<?php

class PasswordPolicyInterfaceTranslator
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
                'public.password_policy.error_weak' => 'Цей пароль надто простий. Оберіть довший і менш передбачуваний пароль.',
                'public.password_policy.hint' => 'Щонайменше 10 символів. Не використовуйте прості паролі або послідовності.'
            ],
            'ru' => [
                'public.password_policy.error_weak' => 'Этот пароль слишком простой. Выберите более длинный и менее предсказуемый пароль.',
                'public.password_policy.hint' => 'Не менее 10 символов. Не используйте простые пароли или последовательности.'
            ],
            'en' => [
                'public.password_policy.error_weak' => 'This password is too easy to guess. Choose a longer, less predictable password.',
                'public.password_policy.hint' => 'At least 10 characters. Avoid simple passwords and predictable sequences.'
            ]
        ];

        $insert = $db->prepare("
            INSERT IGNORE INTO interface_translations
            (translation_key, language_code, value, source, status)
            VALUES
            (:translation_key, :language_code, :value, 'manual', 'approved')
        ");

        foreach ($translations as $languageCode => $items) {
            foreach ($items as $key => $value) {
                $insert->execute([
                    'translation_key' => $key,
                    'language_code' => $languageCode,
                    'value' => $value
                ]);
            }
        }

        // Обновляем только старые стандартные тексты с правилом 8 символов.
        // Если администратор уже менял перевод вручную, его значение не трогаем.
        $legacy = [
            'uk' => [
                'public.auth.password_hint' => ['Щонайменше 8 символів', 'Щонайменше 10 символів. Не використовуйте прості паролі або послідовності.'],
                'public.account.password_hint' => ['Щонайменше 8 символів', 'Щонайменше 10 символів. Не використовуйте прості паролі або послідовності.'],
                'public.auth.error_password_short' => ['Пароль має містити щонайменше 8 символів.', 'Пароль має містити щонайменше 10 символів.']
            ],
            'ru' => [
                'public.auth.password_hint' => ['Не менее 8 символов', 'Не менее 10 символов. Не используйте простые пароли или последовательности.'],
                'public.account.password_hint' => ['Не менее 8 символов', 'Не менее 10 символов. Не используйте простые пароли или последовательности.'],
                'public.auth.error_password_short' => ['Пароль должен содержать не менее 8 символов.', 'Пароль должен содержать не менее 10 символов.']
            ],
            'en' => [
                'public.auth.password_hint' => ['At least 8 characters', 'At least 10 characters. Avoid simple passwords and predictable sequences.'],
                'public.account.password_hint' => ['At least 8 characters', 'At least 10 characters. Avoid simple passwords and predictable sequences.'],
                'public.auth.error_password_short' => ['Password must contain at least 8 characters.', 'Password must contain at least 10 characters.']
            ]
        ];

        $update = $db->prepare("
            UPDATE interface_translations
            SET value = :new_value,
                status = 'approved'
            WHERE translation_key = :translation_key
              AND language_code = :language_code
              AND value = :old_value
        ");

        foreach ($legacy as $languageCode => $items) {
            foreach ($items as $key => $values) {
                $update->execute([
                    'new_value' => $values[1],
                    'translation_key' => $key,
                    'language_code' => $languageCode,
                    'old_value' => $values[0]
                ]);
            }
        }

        self::$seeded = true;
    }
}
