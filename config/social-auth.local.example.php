<?php

return [
    'providers' => [
        'google' => [
            // Google Cloud Console -> OAuth 2.0 Client ID (Web application).
            'client_id' => 'PASTE_GOOGLE_CLIENT_ID_HERE',
            'client_secret' => 'PASTE_GOOGLE_CLIENT_SECRET_HERE',

            // Для локального KSWEB можно оставить пустым: адрес будет собран
            // автоматически как http://localhost:8000/Anabelka/auth/google/callback.
            // На рабочем сайте при необходимости укажите точный HTTPS callback.
            'redirect_uri' => ''
        ]
    ]
];
