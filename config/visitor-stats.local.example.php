<?php
// Copy to visitor-stats.local.php only in the intended installation.
// Enable only after isolated testing and reviewing the public privacy notice.
return [
    'enabled' => false,
    'allowed_hosts' => ['anabelka.com.ua', 'www.anabelka.com.ua'],
    'base_path' => '/Anabelka', // '' when the store is served at the domain root
    'timezone' => 'Europe/Kyiv',
    'retention_days' => 400
];
