<?php

$config = [
    'providers' => [
        'google' => [
            'client_id' => getenv('GOOGLE_CLIENT_ID') ?: '',
            'client_secret' => getenv('GOOGLE_CLIENT_SECRET') ?: '',
            'redirect_uri' => getenv('GOOGLE_REDIRECT_URI') ?: '',
            'authorization_endpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_endpoint' => 'https://oauth2.googleapis.com/token',
            'userinfo_endpoint' => 'https://openidconnect.googleapis.com/v1/userinfo',
            'scope' => 'openid email profile',
            'timeout' => 20
        ],
        'facebook' => [
            'client_id' => getenv('FACEBOOK_APP_ID') ?: '',
            'client_secret' => getenv('FACEBOOK_APP_SECRET') ?: '',
            'redirect_uri' => getenv('FACEBOOK_REDIRECT_URI') ?: '',
            'api_version' => 'v26.0',
            'authorization_endpoint' => 'https://www.facebook.com/v26.0/dialog/oauth',
            'token_endpoint' => 'https://graph.facebook.com/v26.0/oauth/access_token',
            'userinfo_endpoint' => 'https://graph.facebook.com/v26.0/me',
            'scope' => 'email,public_profile',
            'timeout' => 20
        ]
    ]
];

$localConfigFile = __DIR__ . '/social-auth.local.php';

if (is_file($localConfigFile)) {
    $localConfig = require $localConfigFile;

    if (is_array($localConfig)) {
        foreach ($localConfig['providers'] ?? [] as $provider => $providerConfig) {
            if (!is_array($providerConfig)) {
                continue;
            }

            $config['providers'][$provider] = array_merge(
                $config['providers'][$provider] ?? [],
                $providerConfig
            );
        }
    }
}

return $config;
