<?php

class SocialConnectionsInterfaceTranslator
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
                'public.social_connections.title' => 'Способи входу',
                'public.social_connections.hint' => 'Підключайте додаткові способи входу до одного акаунта Анабельки.',
                'public.social_connections.connected' => 'Підключено',
                'public.social_connections.not_connected' => 'Не підключено',
                'public.social_connections.connect' => 'Підключити',
                'public.social_connections.disconnect' => 'Від’єднати',
                'public.social_connections.unavailable' => 'Спосіб входу зараз недоступний.',
                'public.social_connections.not_configured' => 'Ще не налаштовано адміністратором.',
                'public.social_connections.last_method' => 'Щоб не втратити доступ до акаунта, останній підключений спосіб входу від’єднати не можна.',
                'public.social_connections.connected_success' => '{provider} успішно підключено до вашого акаунта.',
                'public.social_connections.disconnected_success' => '{provider} від’єднано від вашого акаунта.',
                'public.social_connections.email_mismatch' => 'Email зовнішнього акаунта має збігатися з email вашого акаунта Анабельки.'
            ],
            'ru' => [
                'public.social_connections.title' => 'Способы входа',
                'public.social_connections.hint' => 'Подключайте дополнительные способы входа к одному аккаунту Анабельки.',
                'public.social_connections.connected' => 'Подключено',
                'public.social_connections.not_connected' => 'Не подключено',
                'public.social_connections.connect' => 'Подключить',
                'public.social_connections.disconnect' => 'Отключить',
                'public.social_connections.unavailable' => 'Способ входа сейчас недоступен.',
                'public.social_connections.not_configured' => 'Ещё не настроено администратором.',
                'public.social_connections.last_method' => 'Чтобы не потерять доступ к аккаунту, последний подключённый способ входа отключить нельзя.',
                'public.social_connections.connected_success' => '{provider} успешно подключён к вашему аккаунту.',
                'public.social_connections.disconnected_success' => '{provider} отключён от вашего аккаунта.',
                'public.social_connections.email_mismatch' => 'Email внешнего аккаунта должен совпадать с email вашего аккаунта Анабельки.'
            ],
            'en' => [
                'public.social_connections.title' => 'Sign-in methods',
                'public.social_connections.hint' => 'Connect additional sign-in methods to the same Anabelka account.',
                'public.social_connections.connected' => 'Connected',
                'public.social_connections.not_connected' => 'Not connected',
                'public.social_connections.connect' => 'Connect',
                'public.social_connections.disconnect' => 'Disconnect',
                'public.social_connections.unavailable' => 'This sign-in method is currently unavailable.',
                'public.social_connections.not_configured' => 'Not configured by the administrator yet.',
                'public.social_connections.last_method' => 'To avoid losing access to your account, the last connected sign-in method cannot be disconnected.',
                'public.social_connections.connected_success' => '{provider} was connected to your account.',
                'public.social_connections.disconnected_success' => '{provider} was disconnected from your account.',
                'public.social_connections.email_mismatch' => 'The external account email must match your Anabelka account email.'
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
