<?php

return [
    'smtp' => [
        // Приклад для SMTP з STARTTLS, зазвичай порт 587.
        'host' => 'smtp.example.com',
        'port' => 587,
        'encryption' => 'tls', // tls, ssl або none
        'username' => 'system@example.com',
        'password' => 'CHANGE_ME',
        'from_email' => 'system@example.com',
        'from_name' => 'Анабелька',
        'timeout' => 15
    ],
    'telegram' => [
        'bot_token' => 'CHANGE_ME'
    ]
];
