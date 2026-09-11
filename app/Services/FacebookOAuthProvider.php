<?php

class FacebookOAuthProvider
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
            throw new RuntimeException('Facebook OAuth ще не налаштовано.');
        }

        $config = self::config();
        $params = [
            'client_id' => (string) $config['client_id'],
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => (string) ($config['scope'] ?? 'email,public_profile'),
            'state' => (string) $state
        ];

        return rtrim((string) $config['authorization_endpoint'], '?')
            . '?'
            . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }


    public static function exchangeCode($code)
    {
        if (!self::isConfigured()) {
            throw new RuntimeException('Facebook OAuth ще не налаштовано.');
        }

        $config = self::config();
        $params = [
            'client_id' => (string) $config['client_id'],
            'client_secret' => (string) $config['client_secret'],
            'redirect_uri' => self::redirectUri(),
            'code' => trim((string) $code)
        ];

        $response = self::requestJson(
            rtrim((string) $config['token_endpoint'], '?')
            . '?'
            . http_build_query($params, '', '&', PHP_QUERY_RFC3986)
        );

        $accessToken = trim((string) ($response['access_token'] ?? ''));

        if ($accessToken === '') {
            throw new RuntimeException('Facebook не повернув токен доступу.');
        }

        return $accessToken;
    }


    public static function userIdentity($accessToken)
    {
        $config = self::config();
        $accessToken = trim((string) $accessToken);

        if ($accessToken === '') {
            throw new RuntimeException('Facebook OAuth не має токена доступу.');
        }

        $params = [
            'fields' => 'id,name,email',
            'access_token' => $accessToken
        ];

        $secret = trim((string) ($config['client_secret'] ?? ''));
        if ($secret !== '') {
            $params['appsecret_proof'] = hash_hmac(
                'sha256',
                $accessToken,
                $secret
            );
        }

        $data = self::requestJson(
            rtrim((string) $config['userinfo_endpoint'], '?')
            . '?'
            . http_build_query($params, '', '&', PHP_QUERY_RFC3986)
        );

        $providerUserId = trim((string) ($data['id'] ?? ''));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $name = trim((string) ($data['name'] ?? ''));

        if ($providerUserId === '') {
            throw new RuntimeException('Facebook не повернув ідентифікатор користувача.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException(
                'Facebook не надав підтверджений email. Перевірте email акаунта Facebook і дозвіл email.'
            );
        }

        return [
            'provider' => 'facebook',
            'provider_user_id' => $providerUserId,
            'email' => $email,
            'email_verified' => true,
            'name' => $name,
            'picture' => '',
            'profile' => [
                'id' => $providerUserId,
                'email' => $email,
                'name' => $name
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

        return $scheme . '://' . $host . '/Anabelka/auth/facebook/callback';
    }


    private static function config()
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $path = __DIR__ . '/../../config/social-auth.php';
        $config = is_file($path) ? require $path : [];
        self::$config = is_array($config['providers']['facebook'] ?? null)
            ? $config['providers']['facebook']
            : [];

        return self::$config;
    }


    private static function requestJson($url)
    {
        $url = trim((string) $url);
        $timeout = max(5, (int) (self::config()['timeout'] ?? 20));

        if ($url === '') {
            throw new RuntimeException('Некоректна адреса Facebook OAuth.');
        }

        if (!function_exists('curl_init')) {
            throw new RuntimeException('Для Facebook OAuth потрібне PHP-розширення cURL.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $curlError !== '') {
            throw new RuntimeException('Не вдалося з’єднатися з Facebook OAuth.');
        }

        $data = json_decode((string) $raw, true);

        if (!is_array($data)) {
            throw new RuntimeException('Facebook OAuth повернув некоректну відповідь.');
        }

        if ($status < 200 || $status >= 300 || !empty($data['error'])) {
            $metaError = is_array($data['error'] ?? null) ? $data['error'] : [];
            $message = trim((string) ($metaError['message'] ?? ''));
            $code = (int) ($metaError['code'] ?? 0);
            $details = $message !== '' ? ': ' . $message : '';

            if ($code > 0) {
                $details .= ' [code ' . $code . ']';
            }

            throw new RuntimeException(
                'Facebook OAuth повернув помилку' . $details
            );
        }

        return $data;
    }
}
