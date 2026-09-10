<?php

class GoogleOAuthProvider
{
    private static $config = null;


    public static function isConfigured()
    {
        $config = self::config();

        return trim((string) ($config['client_id'] ?? '')) !== ''
            && trim((string) ($config['client_secret'] ?? '')) !== '';
    }


    public static function authorizationUrl($state)
    {
        if (!self::isConfigured()) {
            throw new RuntimeException('Google OAuth ще не налаштовано.');
        }

        $config = self::config();
        $params = [
            'client_id' => (string) $config['client_id'],
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => (string) ($config['scope'] ?? 'openid email profile'),
            'state' => (string) $state,
            'access_type' => 'online',
            'include_granted_scopes' => 'true',
            'prompt' => 'select_account'
        ];

        return rtrim((string) $config['authorization_endpoint'], '?')
            . '?'
            . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }


    public static function exchangeCode($code)
    {
        if (!self::isConfigured()) {
            throw new RuntimeException('Google OAuth ще не налаштовано.');
        }

        $config = self::config();
        $response = self::request(
            'POST',
            (string) $config['token_endpoint'],
            [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json'
            ],
            http_build_query([
                'code' => trim((string) $code),
                'client_id' => (string) $config['client_id'],
                'client_secret' => (string) $config['client_secret'],
                'redirect_uri' => self::redirectUri(),
                'grant_type' => 'authorization_code'
            ], '', '&', PHP_QUERY_RFC3986)
        );

        $accessToken = trim((string) ($response['access_token'] ?? ''));

        if ($accessToken === '') {
            throw new RuntimeException('Google не повернув токен доступу.');
        }

        return $accessToken;
    }


    public static function userIdentity($accessToken)
    {
        $config = self::config();
        $data = self::request(
            'GET',
            (string) $config['userinfo_endpoint'],
            [
                'Authorization: Bearer ' . trim((string) $accessToken),
                'Accept: application/json'
            ]
        );

        $providerUserId = trim((string) ($data['sub'] ?? ''));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $emailVerified = filter_var(
            $data['email_verified'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );

        if (
            $providerUserId === ''
            || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || !$emailVerified
        ) {
            throw new RuntimeException('Google не підтвердив email цього акаунта.');
        }

        return [
            'provider' => 'google',
            'provider_user_id' => $providerUserId,
            'email' => $email,
            'email_verified' => true,
            'name' => trim((string) ($data['name'] ?? '')),
            'picture' => trim((string) ($data['picture'] ?? '')),
            'profile' => [
                'sub' => $providerUserId,
                'email' => $email,
                'name' => trim((string) ($data['name'] ?? '')),
                'picture' => trim((string) ($data['picture'] ?? ''))
            ]
        ];
    }


    public static function redirectUri()
    {
        $configured = trim((string) (self::config()['redirect_uri'] ?? ''));

        if ($configured !== '') {
            return $configured;
        }

        $https = strtolower(trim((string) ($_SERVER['HTTPS'] ?? '')));
        $scheme = ($https !== '' && $https !== 'off' && $https !== '0')
            ? 'https'
            : 'http';
        $forwarded = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));

        if ($forwarded === 'https') {
            $scheme = 'https';
        }

        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost:8000'));

        if ($host === '') {
            $host = 'localhost:8000';
        }

        return $scheme . '://' . $host . '/Anabelka/auth/google/callback';
    }


    private static function config()
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $path = __DIR__ . '/../../config/social-auth.php';
        $config = is_file($path) ? require $path : [];
        self::$config = is_array($config['providers']['google'] ?? null)
            ? $config['providers']['google']
            : [];

        return self::$config;
    }


    private static function request($method, $url, array $headers = [], $body = null)
    {
        $method = strtoupper(trim((string) $method));
        $url = trim((string) $url);
        $timeout = max(5, (int) (self::config()['timeout'] ?? 20));

        if ($url === '') {
            throw new RuntimeException('Некоректна адреса Google OAuth.');
        }

        if (!function_exists('curl_init')) {
            throw new RuntimeException('Для Google OAuth потрібне PHP-розширення cURL.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string) $body);
        }

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $error !== '') {
            throw new RuntimeException('Не вдалося з’єднатися з Google OAuth.');
        }

        $data = json_decode((string) $raw, true);

        if ($status < 200 || $status >= 300 || !is_array($data)) {
            throw new RuntimeException('Google OAuth повернув некоректну відповідь.');
        }

        return $data;
    }
}
