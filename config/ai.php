<?php

$config = [
    'default_provider' => 'openai',
    'providers' => [
        'openai' => [
            'api_key' => getenv('OPENAI_API_KEY') ?: '',
            'model' => 'gpt-5.6-luna',
            'timeout' => 30
        ],
        'gemini' => [
            'api_key' => getenv('GEMINI_API_KEY') ?: '',
            'model' => 'gemini-3.7-flash',
            'timeout' => 30
        ],
        'groq' => [
            'api_key' => getenv('GROQ_API_KEY') ?: '',
            'model' => 'openai/gpt-oss-20b',
            'timeout' => 30
        ],
        'deepl' => [
            'api_key' => getenv('DEEPL_API_KEY') ?: '',
            'endpoint' => 'https://api.deepl.com/v2/translate',
            'timeout' => 30
        ]
    ]
];

$localConfigFile = __DIR__ . '/ai.local.php';

if (is_file($localConfigFile)) {
    $localConfig = require $localConfigFile;

    if (is_array($localConfig)) {
        if (isset($localConfig['default_provider'])) {
            $config['default_provider'] =
                $localConfig['default_provider'];
        }

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
