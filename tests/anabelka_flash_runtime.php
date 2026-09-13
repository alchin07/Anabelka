<?php

require_once __DIR__ . '/../app/Core/AnabelkaFlash.php';

$checks = 0;

$assert = static function ($condition, $message) use (&$checks) {
    $checks++;

    if (!$condition) {
        throw new RuntimeException($message);
    }
};

set_error_handler(static function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$assert(
    AnabelkaFlash::success('No active session') === false,
    'push must fail safely when no session is active'
);

session_id('anabelka-flash-' . getmypid());
$assert(session_start(), 'test session must start');
$_SESSION = [];

$assert(
    AnabelkaFlash::push([], 'Invalid array type') === false,
    'array type must be rejected without a warning'
);
$assert(
    AnabelkaFlash::push(new stdClass(), 'Invalid object type') === false,
    'object type must be rejected without an exception'
);
$assert(
    AnabelkaFlash::success('Saved', ['duration' => 1250]) === true,
    'valid success must be stored'
);
$assert(
    AnabelkaFlash::success('Saved', ['persistent' => true]) === false,
    'same type and message must be deduplicated'
);
$assert(
    AnabelkaFlash::error('Failed', ['persistent' => true]) === true,
    'valid persistent error must be stored'
);
$assert(
    AnabelkaFlash::info('0') === true,
    'numeric-looking string message must remain valid'
);

$items = AnabelkaFlash::consume();
$assert(count($items) === 3, 'consume must return every unique valid item');
$assert(
    $items[0]['options']['duration'] === 1250,
    'duration option must survive the bridge'
);
$assert(
    $items[1]['options']['persistent'] === true,
    'persistent option must survive the bridge'
);
$assert(
    !array_key_exists('anabelka_flash', $_SESSION),
    'consume must delete the session namespace before returning'
);
$assert(
    AnabelkaFlash::consume() === [],
    'a consumed flash must not appear twice'
);

$_SESSION['anabelka_flash'] = [
    'version' => 1,
    'items' => [
        ['type' => [], 'message' => 'Invalid'],
        ['type' => new stdClass(), 'message' => 'Invalid'],
        ['type' => 'warning', 'message' => '  Valid warning  '],
        ['type' => 'warning', 'message' => 'Valid warning']
    ]
];

$items = AnabelkaFlash::consume();
$assert(count($items) === 1, 'malformed payload entries must be discarded');
$assert(
    $items[0]['message'] === 'Valid warning',
    'valid payload message must be trimmed and deduplicated'
);
$assert(
    !array_key_exists('anabelka_flash', $_SESSION),
    'malformed payload consumption must still be one-shot'
);

session_destroy();
restore_error_handler();

fwrite(
    STDOUT,
    'anabelka flash runtime checks passed (' . $checks . " checks)\n"
);
