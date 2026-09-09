<?php
$entries = is_array($entries ?? null) ? $entries : [];
$actionLabels = [
    'admin.owner_created' => 'Створено розробника',
    'admin.login' => 'Вхід адміністратора',
    'admin.login_failed' => 'Невдала спроба входу',
    'admin.logout' => 'Вихід адміністратора',
    'admin.created' => 'Створено адміністратора',
    'admin.role_changed' => 'Змінено роль адміністратора',
    'admin.access_toggled' => 'Змінено доступ адміністратора',
    'admin.password_reset' => 'Змінено пароль адміністратора',
    'admin.role_created' => 'Створено власну роль',
    'admin.role_permissions_updated' => 'Змінено права ролі',
    'admin.profile_updated' => 'Оновлено власний профіль',
    'admin.password_changed' => 'Змінено власний пароль',

    'order.status_changed' => 'Змінено стан замовлення',

    'product.created' => 'Створено товар',
    'product.updated' => 'Оновлено товар',
    'product.visibility_changed' => 'Змінено видимість товару',
    'product.duplicated' => 'Створено копію товару',
    'product.variant_stock_updated' => 'Оновлено залишки варіантів товару',

    'category.updated' => 'Оновлено категорію',

    'customer.invitation_created' => 'Створено запрошення покупця',
    'customer.invitation_marked_sent' => 'Запрошення позначено як надіслане',
    'customer.rank_changed' => 'Змінено ранг покупця',
    'customer.rank_request_approved' => 'Схвалено запит на підвищення рангу',
    'customer.rank_request_rejected' => 'Відхилено запит на підвищення рангу',
    'customer.account_deleted' => 'Видалено акаунт покупця',
    'customer.account_deactivated' => 'Деактивовано акаунт покупця',

    'rank.created' => 'Створено ранг',
    'rank.updated' => 'Оновлено ранг',
    'rank.moved' => 'Змінено порядок рангу',
    'rank.toggled' => 'Змінено активність рангу',
    'rank.default_changed' => 'Змінено ранг для нових користувачів',

    'delivery.method_toggled' => 'Змінено активність способу доставки',
    'delivery.service_toggled' => 'Змінено активність служби доставки',
    'delivery.option_toggled' => 'Змінено активність опції доставки',
    'delivery.entity_updated' => 'Оновлено елемент доставки',
    'delivery.entity_deleted' => 'Видалено елемент доставки',
    'delivery.method_created' => 'Створено спосіб доставки',
    'delivery.service_created' => 'Створено службу доставки',
    'delivery.option_created' => 'Створено опцію доставки',
    'delivery.option_field_updated' => 'Оновлено додаткове поле доставки',
    'delivery.translations_updated' => 'Оновлено переклади доставки',

    'language.created' => 'Додано мову',
    'language.updated' => 'Оновлено назву мови',
    'language.toggled' => 'Змінено активність мови',
    'language.default_changed' => 'Змінено основну мову сайту',
    'language.deleted' => 'Видалено мову',

    'translation.interface_updated' => 'Оновлено переклад інтерфейсу'
];

$detailLabels = [
    'admin_id' => 'ID адміністратора',
    'created_admin_id' => 'ID нового адміністратора',
    'target_admin_id' => 'ID адміністратора',
    'role_id' => 'ID ролі',
    'role_name' => 'Роль',
    'role_slug' => 'Код ролі',
    'old_role' => 'Попередня роль',
    'new_role' => 'Нова роль',
    'email' => 'Email',
    'old_email' => 'Попередній email',
    'new_email' => 'Новий email',
    'name_changed' => 'Ім’я змінено',

    'order_id' => 'Замовлення',
    'order_type' => 'Тип замовлення',
    'old_status' => 'Попередній стан',
    'new_status' => 'Новий стан',

    'product_id' => 'ID товару',
    'source_product_id' => 'ID вихідного товару',
    'category_id' => 'ID категорії',
    'sku' => 'SKU',
    'stock_mode' => 'Облік залишків',

    'user_id' => 'ID користувача',
    'request_id' => 'ID запиту',
    'old_rank_id' => 'Попередній ранг',
    'new_rank_id' => 'Новий ранг',
    'new_rank_name' => 'Назва нового рангу',
    'rank_id' => 'ID рангу',
    'channel' => 'Канал запрошення',
    'direction' => 'Напрямок',
    'note' => 'Примітка',

    'method_id' => 'ID способу доставки',
    'service_id' => 'ID служби доставки',
    'option_id' => 'ID опції доставки',
    'delivery_method_id' => 'ID способу доставки',
    'delivery_service_id' => 'ID служби доставки',
    'type' => 'Тип елемента',
    'id' => 'ID елемента',
    'is_enabled' => 'Додаткове поле увімкнено',

    'language_id' => 'ID мови',
    'language_code' => 'Код мови',
    'translation_key' => 'Ключ перекладу',

    'name' => 'Назва',
    'is_active' => 'Активний'
];

$formatDetail = static function ($key, $value) {
    if (in_array($key, ['is_active', 'is_enabled', 'name_changed'], true)) {
        return (string) ((int) $value === 1 ? 'Так' : 'Ні');
    }

    if ($key === 'order_type') {
        return (string) ($value === 'regular'
            ? 'Звичайне'
            : ($value === 'quick' ? 'Швидке' : $value));
    }

    if (in_array($key, ['old_status', 'new_status'], true)) {
        $labels = [
            'new' => 'Нове',
            'processing' => 'В обробці',
            'completed' => 'Завершене',
            'cancelled' => 'Скасоване'
        ];

        return (string) ($labels[(string) $value] ?? $value);
    }

    if ($key === 'direction') {
        return (string) ($value === 'up'
            ? 'Вгору'
            : ($value === 'down' ? 'Вниз' : $value));
    }

    if (!is_scalar($value) && $value !== null) {
        return (string) json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    return (string) $value;
};
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Журнал дій') ?></title>
    <link rel="stylesheet" href="/Anabelka/css/admin-administrators.css?v=1">
</head>
<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="admin-security-page">
    <section class="admin-security-hero">
        <div>
            <span class="admin-security-kicker">Безпека</span>
            <h2>Журнал адміністративних дій</h2>
            <p>Останні <?= count($entries) ?> записів. Паролі, токени та тексти перекладів у журнал не записуються.</p>
        </div>
        <?php if (AdminAccess::can('administrators.view')): ?>
            <a class="admin-security-audit-link" href="/Anabelka/admin/administrators">Адміністратори</a>
        <?php endif; ?>
    </section>

    <section class="admin-security-panel">
        <?php if (empty($entries)): ?>
            <div class="admin-audit-empty">Журнал поки порожній.</div>
        <?php else: ?>
            <div class="admin-audit-list">
                <?php foreach ($entries as $entry): ?>
                    <?php
                    $details = json_decode((string) ($entry['details'] ?? ''), true);
                    $details = is_array($details) ? $details : [];
                    $action = (string) ($entry['action'] ?? '');
                    ?>
                    <article class="admin-audit-item">
                        <div class="admin-audit-head">
                            <strong><?= htmlspecialchars($actionLabels[$action] ?? $action) ?></strong>
                            <time><?= htmlspecialchars($entry['created_at'] ?? '') ?></time>
                        </div>
                        <div class="admin-audit-actor">
                            <?php if (!empty($entry['admin_name'])): ?>
                                <span><?= htmlspecialchars($entry['admin_name']) ?></span>
                                <small><?= htmlspecialchars($entry['admin_email'] ?? '') ?></small>
                            <?php else: ?>
                                <span>Система / невідомий адміністратор</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($details)): ?>
                            <div class="admin-audit-details">
                                <?php foreach ($details as $key => $value): ?>
                                    <span>
                                        <b><?= htmlspecialchars($detailLabels[$key] ?? (string) $key) ?>:</b>
                                        <?= htmlspecialchars($formatDetail((string) $key, $value)) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>

</body>
</html>
