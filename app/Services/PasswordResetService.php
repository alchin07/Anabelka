<?php

class PasswordResetService
{
    private const PREVIEW_SESSION_KEY = 'customer_password_reset_preview';


    public static function requestForEmail($email)
    {
        $email = strtolower(trim((string) $email));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException(
                Translator::t(
                    'public.password_reset.invalid_email',
                    'Вкажіть коректний email.'
                )
            );
        }

        $user = User::findByEmailAnyStatus($email);

        if (!$user || empty($user['is_active'])) {
            self::clearPreview();
            return [
                'sent' => false,
                'local' => self::isLocalEnvironment(),
                'preview_url' => ''
            ];
        }

        try {
            $issued = CustomerPasswordReset::issueForUser(
                (int) $user['id'],
                $email
            );
        } catch (RuntimeException $e) {
            $preview = self::currentPreviewForEmail($email);

            if ($preview !== '') {
                return [
                    'sent' => false,
                    'local' => true,
                    'preview_url' => $preview
                ];
            }

            throw $e;
        }

        $url = self::resetUrl($issued['token']);
        $language = class_exists('Translator')
            ? (Translator::currentLanguage()['code'] ?? 'uk')
            : 'uk';
        $delivery = self::sendMail(
            $email,
            (string) ($user['name'] ?? ''),
            $url,
            $language
        );

        if (!empty($delivery['local'])) {
            $_SESSION[self::PREVIEW_SESSION_KEY] = [
                'user_id' => (int) $user['id'],
                'email' => $email,
                'url' => $url,
                'expires_at' => (string) ($issued['expires_at'] ?? '')
            ];
        } else {
            self::clearPreview();
        }

        if (empty($delivery['sent']) && empty($delivery['local'])) {
            error_log('Password reset mail was not sent for user #' . (int) $user['id']);
        }

        return [
            'sent' => !empty($delivery['sent']),
            'local' => !empty($delivery['local']),
            'preview_url' => !empty($delivery['local']) ? $url : ''
        ];
    }


    public static function validate($token)
    {
        return CustomerPasswordReset::validateToken($token);
    }


    public static function reset($token, $newPassword)
    {
        $result = CustomerPasswordReset::resetPassword($token, $newPassword);
        $preview = is_array($_SESSION[self::PREVIEW_SESSION_KEY] ?? null)
            ? $_SESSION[self::PREVIEW_SESSION_KEY]
            : [];

        if ((int) ($preview['user_id'] ?? 0) === (int) ($result['user_id'] ?? 0)) {
            self::clearPreview();
        }

        return $result;
    }


    private static function resetUrl($token)
    {
        $scheme = self::requestScheme();
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost:8000'));

        if ($host === '') {
            $host = 'localhost:8000';
        }

        return $scheme
            . '://'
            . $host
            . '/Anabelka/reset-password?token='
            . rawurlencode((string) $token);
    }


    private static function sendMail($email, $name, $url, $languageCode)
    {
        if (self::isLocalEnvironment()) {
            return [
                'sent' => false,
                'local' => true,
                'transport' => 'local-preview'
            ];
        }

        $copy = self::mailCopy($languageCode);
        $greetingName = trim((string) $name) !== '' ? trim((string) $name) : $email;
        $body = str_replace(
            ['{name}', '{url}'],
            [$greetingName, $url],
            $copy['body']
        );
        $encodedSubject = '=?UTF-8?B?'
            . base64_encode($copy['subject'])
            . '?=';
        $headers = implode("\r\n", [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit'
        ]);
        $sent = false;

        if (function_exists('mail')) {
            $sent = @mail(
                $email,
                $encodedSubject,
                $body,
                $headers
            );
        }

        return [
            'sent' => (bool) $sent,
            'local' => false,
            'transport' => 'php-mail'
        ];
    }


    private static function currentPreviewForEmail($email)
    {
        $preview = is_array($_SESSION[self::PREVIEW_SESSION_KEY] ?? null)
            ? $_SESSION[self::PREVIEW_SESSION_KEY]
            : [];

        if (strtolower(trim((string) ($preview['email'] ?? ''))) !== $email) {
            return '';
        }

        $expiresAt = strtotime((string) ($preview['expires_at'] ?? ''));

        if ($expiresAt === false || $expiresAt < time()) {
            self::clearPreview();
            return '';
        }

        return (string) ($preview['url'] ?? '');
    }


    private static function clearPreview()
    {
        unset($_SESSION[self::PREVIEW_SESSION_KEY]);
    }


    private static function isLocalEnvironment()
    {
        if (class_exists('EmailVerificationMailer')) {
            return EmailVerificationMailer::isLocalEnvironment();
        }

        $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost')));
        $host = preg_replace('/:\d+$/', '', $host);

        return $host === 'localhost' || $host === '127.0.0.1';
    }


    private static function requestScheme()
    {
        $https = strtolower(trim((string) ($_SERVER['HTTPS'] ?? '')));

        if ($https !== '' && $https !== 'off' && $https !== '0') {
            return 'https';
        }

        $forwarded = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));

        return $forwarded === 'https' ? 'https' : 'http';
    }


    private static function mailCopy($languageCode)
    {
        $copies = [
            'uk' => [
                'subject' => 'Відновлення пароля — Анабелька',
                'body' => "Вітаємо, {name}!\n\nДля створення нового пароля перейдіть за посиланням:\n{url}\n\nПосилання діє одну годину та може бути використане лише один раз.\n\nЯкщо ви не запитували відновлення пароля, просто проігноруйте цей лист."
            ],
            'ru' => [
                'subject' => 'Восстановление пароля — Анабелька',
                'body' => "Здравствуйте, {name}!\n\nЧтобы создать новый пароль, перейдите по ссылке:\n{url}\n\nСсылка действует один час и может быть использована только один раз.\n\nЕсли вы не запрашивали восстановление пароля, просто проигнорируйте это письмо."
            ],
            'en' => [
                'subject' => 'Password reset — Anabelka',
                'body' => "Hello, {name}!\n\nUse this link to create a new password:\n{url}\n\nThe link is valid for one hour and can only be used once.\n\nIf you did not request a password reset, simply ignore this email."
            ]
        ];

        return $copies[strtolower((string) $languageCode)] ?? $copies['uk'];
    }
}
