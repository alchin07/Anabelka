<?php

// Local configuration is intentionally untracked; no analytics cookies or IDs.
$config = [
    'enabled' => false,
    'allowed_hosts' => [],
    'base_path' => '/Anabelka',
    'timezone' => 'Europe/Kyiv',
    'retention_days' => 400
];
$local = __DIR__ . '/visitor-stats.local.php';
if (is_file($local)) {
    $overrides = require $local;
    if (is_array($overrides)) {
        $config = array_replace($config, $overrides);
    }
}
return $config;
