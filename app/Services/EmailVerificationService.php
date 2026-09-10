<?php

class EmailVerificationService
{
    private const PREVIEW_SESSION_KEY = 'customer_email_verification_preview';


    public static function issueForUser(array $user, $ignoreCooldown = false)
    {
        $userId = (int) ($user['id'] ?? 0);
        $email = strtolower(trim((string) ($user['email'] ?? '')));
        $name = trim((string) ($user['name'] ?? ''));

        if ($userId <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Не вдалося підготувати підтвердження email.');
        }

        $issued = CustomerEmailVerification::issueForUser(
            $userId,
            $email,
            (bool) $ignoreCooldown
        );
        $url = EmailVerificationMailer::verificationUrl($issued['token']);
        $language = class_exists('Translator')
            ? (Translator::currentLanguage()['code'] ?? 'uk')
            : 'uk';
        $delivery = EmailVerificationMailer::send(
            $email,
            $name,
            $url,
            $language
        );

        if (!empty($delivery['local'])) {
            $_SESSION[self::PREVIEW_SESSION_KEY] = [
                'user_id' => $userId,
                'email' => $email,
                'url' => $url,
                'expires_at' => (string) ($issued['expires_at'] ?? '')
            ];
        } else {
            unset($_SESSION[self::PREVIEW_SESSION_KEY]);
        }

        return [
            'sent' => !empty($delivery['sent']),
            'local' => !empty($delivery['local']),
            'transport' => (string) ($delivery['transport'] ?? 'none'),
            'expires_at' => (string) ($issued['expires_at'] ?? ''),
            'preview_url' => !empty($delivery['local']) ? $url : ''
        ];
    }


    public static function statusForUser(array $user)
    {
        $userId = (int) ($user['id'] ?? 0);
        $email = strtolower(trim((string) ($user['email'] ?? '')));
        $status = CustomerEmailVerification::statusForUser($userId, $email);
        $status['preview_url'] = '';

        $preview = is_array($_SESSION[self::PREVIEW_SESSION_KEY] ?? null)
            ? $_SESSION[self::PREVIEW_SESSION_KEY]
            : [];

        if (
            empty($status['verified'])
            && (int) ($preview['user_id'] ?? 0) === $userId
            && strtolower(trim((string) ($preview['email'] ?? ''))) === $email
            && !empty($preview['url'])
        ) {
            $previewExpires = strtotime((string) ($preview['expires_at'] ?? ''));

            if ($previewExpires !== false && $previewExpires >= time()) {
                $status['preview_url'] = (string) $preview['url'];
            } else {
                unset($_SESSION[self::PREVIEW_SESSION_KEY]);
            }
        }

        return $status;
    }


    public static function verify($token)
    {
        $result = CustomerEmailVerification::verifyToken($token);
        $preview = is_array($_SESSION[self::PREVIEW_SESSION_KEY] ?? null)
            ? $_SESSION[self::PREVIEW_SESSION_KEY]
            : [];

        if ((int) ($preview['user_id'] ?? 0) === (int) ($result['user_id'] ?? 0)) {
            unset($_SESSION[self::PREVIEW_SESSION_KEY]);
        }

        return $result;
    }


    public static function clearPreviewForUser($userId)
    {
        $preview = is_array($_SESSION[self::PREVIEW_SESSION_KEY] ?? null)
            ? $_SESSION[self::PREVIEW_SESSION_KEY]
            : [];

        if ((int) ($preview['user_id'] ?? 0) === (int) $userId) {
            unset($_SESSION[self::PREVIEW_SESSION_KEY]);
        }
    }
}
