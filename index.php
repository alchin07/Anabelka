<?php

require_once __DIR__ . '/app/Core/ErrorHandler.php';
ErrorHandler::register(__DIR__);

require_once __DIR__ . '/app/Core/App.php';

$app = new App();

$app->run();