<?php

$config = [
    'smtp' => [
        'host' => getenv('ANABELKA_SMTP_HOST') ?: '',
        'port' => (int) (getenv('ANABELKA_SMTP_PORT') ?: 587),
        'encryption' => getenv('ANABELKA_SMTP_ENCRYPTION') ?: 'tls',
        'username' => getenv('ANABELKA_SMTP_USERNAME') ?: '',
        'password' => getenv('ANABELKA_SMTP_PASSWORD') ?: '',
        'from_email' => getenv('ANABELKA_SMTP_FROM_EMAIL') ?: '',
        'from_name' => getenv('ANABELKA_SMTP_FROM_NAME') ?: 'Анабелька',
        'timeout' => (int) (getenv('ANABELKA_SMTP_TIMEOUT') ?: 15)
    ],
    'telegram' => [
        'bot_token' => getenv('ANABELKA_TELEGRAM_BOT_TOKEN') ?: '',
        'api_base' => 'https://api.telegram.org',
        'timeout' => 15
    ]
];

$localConfigFile = __DIR__ . '/error-notifications.local.php';

if (is_file($localConfigFile)) {
    $localConfig = require $localConfigFile;

    if (is_array($localConfig)) {
        foreach (['smtp', 'telegram'] as $section) {
            if (!is_array($localConfig[$section] ?? null)) {
                continue;
            }

            $config[$section] = array_merge(
                $config[$section] ?? [],
                $localConfig[$section]
            );
        }
    }
}

return $config;
