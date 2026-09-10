<?php

class EmailVerificationMailer
{
    public static function verificationUrl($token)
    {
        $token = trim((string) $token);
        $scheme = self::requestScheme();
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost:8000'));

        if ($host === '') {
            $host = 'localhost:8000';
        }

        return $scheme
            . '://'
            . $host
            . '/Anabelka/verify-email?token='
            . rawurlencode($token);
    }


    public static function send($email, $name, $verificationUrl, $languageCode = 'uk')
    {
        $email = strtolower(trim((string) $email));
        $name = trim((string) $name);
        $verificationUrl = trim((string) $verificationUrl);
        $languageCode = strtolower(trim((string) $languageCode));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $verificationUrl === '') {
            return [
                'sent' => false,
                'local' => self::isLocalEnvironment(),
                'transport' => 'none'
            ];
        }

        if (self::isLocalEnvironment()) {
            return [
                'sent' => false,
                'local' => true,
                'transport' => 'local-preview'
            ];
        }

        $copy = self::copy($languageCode);
        $subject = $copy['subject'];
        $greetingName = $name !== '' ? $name : $email;
        $body = str_replace(
            ['{name}', '{url}'],
            [$greetingName, $verificationUrl],
            $copy['body']
        );

        $encodedSubject = '=?UTF-8?B?'
            . base64_encode($subject)
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


    public static function isLocalEnvironment()
    {
        $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost')));
        $host = preg_replace('/:\d+$/', '', $host);
        $host = trim((string) $host, '[]');

        if ($host === '' || $host === 'localhost' || $host === '::1') {
            return true;
        }

        return strpos($host, '127.') === 0
            || strpos($host, '10.') === 0
            || strpos($host, '192.168.') === 0
            || preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $host) === 1;
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


    private static function copy($languageCode)
    {
        $copies = [
            'uk' => [
                'subject' => 'Підтвердження email — Анабелька',
                'body' => "Вітаємо, {name}!\n\nПідтвердіть email вашого акаунта Анабелька за посиланням:\n{url}\n\nПосилання діє 24 години та може бути використане лише один раз.\n\nЯкщо ви не створювали акаунт, просто проігноруйте цей лист."
            ],
            'ru' => [
                'subject' => 'Подтверждение email — Анабелька',
                'body' => "Здравствуйте, {name}!\n\nПодтвердите email вашего аккаунта Анабелька по ссылке:\n{url}\n\nСсылка действует 24 часа и может быть использована только один раз.\n\nЕсли вы не создавали аккаунт, просто проигнорируйте это письмо."
            ],
            'en' => [
                'subject' => 'Confirm your email — Anabelka',
                'body' => "Hello, {name}!\n\nConfirm the email address for your Anabelka account:\n{url}\n\nThe link is valid for 24 hours and can be used only once.\n\nIf you did not create this account, simply ignore this email."
            ]
        ];

        return $copies[$languageCode] ?? $copies['uk'];
    }
}
