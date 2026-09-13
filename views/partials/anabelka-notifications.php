<?php

if (defined('ANABELKA_NOTIFICATIONS_PARTIAL_RENDERED')) {
    return;
}

define('ANABELKA_NOTIFICATIONS_PARTIAL_RENDERED', true);

$anabelkaInitialNotifications = class_exists('AnabelkaFlash')
    ? AnabelkaFlash::consume()
    : [];
$anabelkaNotificationPayload = json_encode(
    $anabelkaInitialNotifications,
    JSON_HEX_TAG
    | JSON_HEX_AMP
    | JSON_HEX_APOS
    | JSON_HEX_QUOT
    | JSON_UNESCAPED_UNICODE
);

if ($anabelkaNotificationPayload === false) {
    $anabelkaNotificationPayload = '[]';
}

?>

<link
    rel="stylesheet"
    href="/Anabelka/css/anabelka-notifications.css?v=1"
>

<div
    id="anabelka-notifications"
    class="anabelka-notifications"
    data-close-label="Закрити сповіщення"
></div>

<script
    id="anabelka-notifications-bootstrap"
    type="application/json"
><?= $anabelkaNotificationPayload ?></script>

<script src="/Anabelka/js/anabelka-notifications.js?v=1"></script>
