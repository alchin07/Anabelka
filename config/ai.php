<?php

$config = [
    'provider' => 'openai',
    'model' => 'gpt-5.6-luna',
    'api_key' => getenv('OPENAI_API_KEY') ?: '',
    'timeout' => 30
];

$localConfigFile = __DIR__ . '/ai.local.php';

if (is_file($localConfigFile)) {
    $localConfig = require $localConfigFile;

    if (is_array($localConfig)) {
        $config = array_merge($config, $localConfig);
    }
}

return $config;
