<?php

// Приклад локальної конфігурації соціальної авторизації.
// Скопіюйте цей файл як config/social-auth.local.php і заповніть значення.
// social-auth.local.php ігнорується Git та не повинен потрапляти до репозиторію.

return [
    'providers' => [
        'google' => [
            // Google Cloud Console -> OAuth 2.0 Client ID (Web application).
            'client_id' => 'PASTE_GOOGLE_CLIENT_ID_HERE',
            'client_secret' => 'PASTE_GOOGLE_CLIENT_SECRET_HERE',
            'redirect_uri' => 'http://localhost:8000/Anabelka/auth/google/callback'
        ],
        'facebook' => [
            // Meta for Developers -> App ID / App Secret.
            'client_id' => 'PASTE_FACEBOOK_APP_ID_HERE',
            'client_secret' => 'PASTE_FACEBOOK_APP_SECRET_HERE',
            'redirect_uri' => 'http://localhost:8000/Anabelka/auth/facebook/callback'
        ]
    ]
];
