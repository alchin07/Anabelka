<?php

header('Content-Type: application/json; charset=utf-8');

$response = [
    'success' => true,
    'app' => 'Анабелька',
    'message' => 'API работает',
    'version' => '1.0'
];

echo json_encode(
    $response,
    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
);