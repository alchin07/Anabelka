<?php

class CustomerEmailVerificationInterfaceTranslator
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
                'public.email_verification.title' => 'Підтвердження email',
                'public.email_verification.verified' => 'Email підтверджено',
                'public.email_verification.unverified' => 'Email не підтверджено',
                'public.email_verification.verified_hint' => 'Ця адреса підтверджена та належить вашому акаунту.',
                'public.email_verification.unverified_hint' => 'Підтвердження email не блокує покупки, але підвищує безпеку акаунта та знадобиться для відновлення доступу.',
                'public.email_verification.send' => 'Надіслати лист підтвердження',
                'public.email_verification.resend' => 'Надіслати ще раз',
                'public.email_verification.sent' => 'Лист підтвердження надіслано.',
                'public.email_verification.local_ready' => 'Локальний режим: тестове посилання готове нижче.',
                'public.email_verification.local_link' => 'Відкрити тестове посилання підтвердження',
                'public.email_verification.local_hint' => 'Це посилання показується лише на локальному сервері для тестування. На робочому сайті воно буде надсилатися поштою.',
                'public.email_verification.mail_unavailable' => 'Лист не вдалося передати поштовому серверу. Спробуйте пізніше.',
                'public.email_verification.success' => 'Email успішно підтверджено.',
                'public.email_verification.invalid' => 'Не вдалося підтвердити email.',
                'public.email_verification.login_success' => 'Email підтверджено. Тепер можна увійти до акаунта.',
                'public.email_verification.wait' => 'Повторне надсилання стане доступним приблизно через хвилину.'
            ],
            'ru' => [
                'public.email_verification.title' => 'Подтверждение email',
                'public.email_verification.verified' => 'Email подтверждён',
                'public.email_verification.unverified' => 'Email не подтверждён',
                'public.email_verification.verified_hint' => 'Этот адрес подтверждён и принадлежит вашему аккаунту.',
                'public.email_verification.unverified_hint' => 'Подтверждение email не блокирует покупки, но повышает безопасность аккаунта и понадобится для восстановления доступа.',
                'public.email_verification.send' => 'Отправить письмо подтверждения',
                'public.email_verification.resend' => 'Отправить ещё раз',
                'public.email_verification.sent' => 'Письмо подтверждения отправлено.',
                'public.email_verification.local_ready' => 'Локальный режим: тестовая ссылка готова ниже.',
                'public.email_verification.local_link' => 'Открыть тестовую ссылку подтверждения',
                'public.email_verification.local_hint' => 'Эта ссылка показывается только на локальном сервере для тестирования. На рабочем сайте она будет отправляться по почте.',
                'public.email_verification.mail_unavailable' => 'Письмо не удалось передать почтовому серверу. Попробуйте позже.',
                'public.email_verification.success' => 'Email успешно подтверждён.',
                'public.email_verification.invalid' => 'Не удалось подтвердить email.',
                'public.email_verification.login_success' => 'Email подтверждён. Теперь можно войти в аккаунт.',
                'public.email_verification.wait' => 'Повторная отправка станет доступна примерно через минуту.'
            ],
            'en' => [
                'public.email_verification.title' => 'Email verification',
                'public.email_verification.verified' => 'Email verified',
                'public.email_verification.unverified' => 'Email not verified',
                'public.email_verification.verified_hint' => 'This address is verified and belongs to your account.',
                'public.email_verification.unverified_hint' => 'Email verification does not block purchases, but it improves account security and will be used for account recovery.',
                'public.email_verification.send' => 'Send verification email',
                'public.email_verification.resend' => 'Send again',
                'public.email_verification.sent' => 'Verification email sent.',
                'public.email_verification.local_ready' => 'Local mode: a test verification link is ready below.',
                'public.email_verification.local_link' => 'Open test verification link',
                'public.email_verification.local_hint' => 'This link is shown only on the local server for testing. On the live site it will be delivered by email.',
                'public.email_verification.mail_unavailable' => 'The message could not be handed to the mail server. Please try again later.',
                'public.email_verification.success' => 'Email verified successfully.',
                'public.email_verification.invalid' => 'Email could not be verified.',
                'public.email_verification.login_success' => 'Email verified. You can now sign in to your account.',
                'public.email_verification.wait' => 'You can resend the message in about a minute.'
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
