<?php

class RegistrationInterfaceTranslator
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
                'public.registration.show_password' => 'Показати пароль',
                'public.registration.hide_password' => 'Сховати пароль',
                'public.registration.passwords_match' => 'Паролі збігаються.',
                'public.registration.passwords_mismatch' => 'Паролі не збігаються.',
                'public.registration.agree_prefix' => 'Я погоджуюся з',
                'public.registration.terms' => 'Умовами користування',
                'public.registration.and' => 'та',
                'public.registration.privacy' => 'Політикою конфіденційності',
                'public.registration.marketing' => 'Хочу отримувати новини, персональні пропозиції та рекламні повідомлення від Анабельки.',
                'public.registration.marketing_optional' => 'Необов’язково. Цю згоду можна буде відкликати.',
                'public.registration.consent_required' => 'Для реєстрації потрібно погодитися з Умовами користування та Політикою конфіденційності.',
                'public.legal.draft_badge' => 'Чернетка для розробки',
                'public.legal.draft_notice' => 'Цей документ використовується для тестування реєстрації. Перед публічним запуском магазину його потрібно замінити остаточною юридично перевіреною редакцією.',
                'public.legal.version' => 'Версія',
                'public.legal.back_register' => 'Повернутися до реєстрації',
                'public.legal.terms_title' => 'Умови користування',
                'public.legal.terms_intro' => 'Реєструючись в Анабельці, користувач створює особистий акаунт для роботи з магазином, замовленнями, обраним та іншими функціями сайту.',
                'public.legal.terms_account_title' => 'Акаунт користувача',
                'public.legal.terms_account_text' => 'Користувач надає актуальні дані, самостійно захищає пароль і не передає доступ до акаунта стороннім особам.',
                'public.legal.terms_orders_title' => 'Замовлення та покупки',
                'public.legal.terms_orders_text' => 'Остаточні правила оплати, доставки, повернення, гарантій та обробки замовлень будуть визначені в робочій редакції умов відповідно до законодавства та налаштувань магазину.',
                'public.legal.terms_adult_title' => 'Розділ 18+',
                'public.legal.terms_adult_text' => 'Доступ до окремого розділу товарів 18+ дозволяється лише повнолітнім користувачам відповідно до правил магазину та вимог законодавства.',
                'public.legal.privacy_title' => 'Політика конфіденційності',
                'public.legal.privacy_intro' => 'Анабелька обробляє лише ті персональні дані, які потрібні для роботи акаунта, оформлення та виконання замовлень, підтримки, безпеки й обраних користувачем функцій.',
                'public.legal.privacy_data_title' => 'Які дані можуть оброблятися',
                'public.legal.privacy_data_text' => 'Ім’я, email, номер телефону, адреси доставки, дані акаунта, історія замовлень та технічні дані, необхідні для безпечної роботи сервісу.',
                'public.legal.privacy_purpose_title' => 'Для чого використовуються дані',
                'public.legal.privacy_purpose_text' => 'Для створення та захисту акаунта, виконання замовлень, доставки, підтримки, сервісних повідомлень і виконання законних обов’язків магазину.',
                'public.legal.privacy_marketing_title' => 'Рекламні повідомлення',
                'public.legal.privacy_marketing_text' => 'Рекламні та персональні пропозиції надсилаються лише за окремою добровільною згодою користувача. Від такої згоди можна буде відмовитися.'
            ],
            'ru' => [
                'public.registration.show_password' => 'Показать пароль',
                'public.registration.hide_password' => 'Скрыть пароль',
                'public.registration.passwords_match' => 'Пароли совпадают.',
                'public.registration.passwords_mismatch' => 'Пароли не совпадают.',
                'public.registration.agree_prefix' => 'Я соглашаюсь с',
                'public.registration.terms' => 'Условиями использования',
                'public.registration.and' => 'и',
                'public.registration.privacy' => 'Политикой конфиденциальности',
                'public.registration.marketing' => 'Хочу получать новости, персональные предложения и рекламные сообщения от Анабельки.',
                'public.registration.marketing_optional' => 'Необязательно. Это согласие можно будет отозвать.',
                'public.registration.consent_required' => 'Для регистрации необходимо согласиться с Условиями использования и Политикой конфиденциальности.',
                'public.legal.draft_badge' => 'Черновик для разработки',
                'public.legal.draft_notice' => 'Этот документ используется для тестирования регистрации. Перед публичным запуском магазина его нужно заменить окончательной юридически проверенной редакцией.',
                'public.legal.version' => 'Версия',
                'public.legal.back_register' => 'Вернуться к регистрации',
                'public.legal.terms_title' => 'Условия использования',
                'public.legal.terms_intro' => 'Регистрируясь в Анабельке, пользователь создаёт личный аккаунт для работы с магазином, заказами, избранным и другими функциями сайта.',
                'public.legal.terms_account_title' => 'Аккаунт пользователя',
                'public.legal.terms_account_text' => 'Пользователь предоставляет актуальные данные, самостоятельно защищает пароль и не передаёт доступ к аккаунту посторонним лицам.',
                'public.legal.terms_orders_title' => 'Заказы и покупки',
                'public.legal.terms_orders_text' => 'Окончательные правила оплаты, доставки, возврата, гарантий и обработки заказов будут определены в рабочей редакции условий в соответствии с законодательством и настройками магазина.',
                'public.legal.terms_adult_title' => 'Раздел 18+',
                'public.legal.terms_adult_text' => 'Доступ к отдельному разделу товаров 18+ разрешается только совершеннолетним пользователям в соответствии с правилами магазина и требованиями законодательства.',
                'public.legal.privacy_title' => 'Политика конфиденциальности',
                'public.legal.privacy_intro' => 'Анабелька обрабатывает только те персональные данные, которые нужны для работы аккаунта, оформления и выполнения заказов, поддержки, безопасности и выбранных пользователем функций.',
                'public.legal.privacy_data_title' => 'Какие данные могут обрабатываться',
                'public.legal.privacy_data_text' => 'Имя, email, номер телефона, адреса доставки, данные аккаунта, история заказов и технические данные, необходимые для безопасной работы сервиса.',
                'public.legal.privacy_purpose_title' => 'Для чего используются данные',
                'public.legal.privacy_purpose_text' => 'Для создания и защиты аккаунта, выполнения заказов, доставки, поддержки, сервисных сообщений и выполнения законных обязанностей магазина.',
                'public.legal.privacy_marketing_title' => 'Рекламные сообщения',
                'public.legal.privacy_marketing_text' => 'Рекламные и персональные предложения отправляются только при отдельном добровольном согласии пользователя. От такого согласия можно будет отказаться.'
            ],
            'en' => [
                'public.registration.show_password' => 'Show password',
                'public.registration.hide_password' => 'Hide password',
                'public.registration.passwords_match' => 'Passwords match.',
                'public.registration.passwords_mismatch' => 'Passwords do not match.',
                'public.registration.agree_prefix' => 'I agree to the',
                'public.registration.terms' => 'Terms of Use',
                'public.registration.and' => 'and',
                'public.registration.privacy' => 'Privacy Policy',
                'public.registration.marketing' => 'I want to receive news, personalised offers and promotional messages from Anabelka.',
                'public.registration.marketing_optional' => 'Optional. You will be able to withdraw this consent.',
                'public.registration.consent_required' => 'You must agree to the Terms of Use and Privacy Policy to register.',
                'public.legal.draft_badge' => 'Development draft',
                'public.legal.draft_notice' => 'This document is used to test registration. Before the public launch of the store it must be replaced with a final legally reviewed version.',
                'public.legal.version' => 'Version',
                'public.legal.back_register' => 'Back to registration',
                'public.legal.terms_title' => 'Terms of Use',
                'public.legal.terms_intro' => 'By registering with Anabelka, the user creates a personal account for using the store, orders, favourites and other website features.',
                'public.legal.terms_account_title' => 'User account',
                'public.legal.terms_account_text' => 'The user provides current information, protects their password and does not give account access to third parties.',
                'public.legal.terms_orders_title' => 'Orders and purchases',
                'public.legal.terms_orders_text' => 'Final payment, delivery, return, warranty and order-processing rules will be defined in the production version in accordance with applicable law and store settings.',
                'public.legal.terms_adult_title' => '18+ section',
                'public.legal.terms_adult_text' => 'Access to the separate 18+ product section is available only to adults in accordance with store rules and applicable law.',
                'public.legal.privacy_title' => 'Privacy Policy',
                'public.legal.privacy_intro' => 'Anabelka processes only the personal data needed to operate the account, fulfil orders, provide support and security, and deliver features selected by the user.',
                'public.legal.privacy_data_title' => 'Data that may be processed',
                'public.legal.privacy_data_text' => 'Name, email, phone number, delivery addresses, account data, order history and technical data required for secure service operation.',
                'public.legal.privacy_purpose_title' => 'Why data is used',
                'public.legal.privacy_purpose_text' => 'To create and protect the account, fulfil orders, provide delivery and support, send service messages and meet the store’s lawful obligations.',
                'public.legal.privacy_marketing_title' => 'Promotional messages',
                'public.legal.privacy_marketing_text' => 'Promotional and personalised offers are sent only with separate voluntary consent. The user will be able to withdraw that consent.'
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
