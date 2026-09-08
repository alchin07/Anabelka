<?php
$entries = is_array($entries ?? null) ? $entries : [];
$actionLabels = [
    'admin.owner_created' => 'Створено власника',
    'admin.login' => 'Вхід адміністратора',
    'admin.login_failed' => 'Невдала спроба входу',
    'admin.logout' => 'Вихід адміністратора',
    'admin.created' => 'Створено адміністратора',
    'admin.role_changed' => 'Змінено роль адміністратора',
    'admin.access_toggled' => 'Змінено доступ адміністратора',
    'admin.password_reset' => 'Змінено пароль адміністратора',
    'admin.role_created' => 'Створено власну роль',
    'admin.role_permissions_updated' => 'Змінено права ролі',
    'customer.account_deleted' => 'Видалено акаунт покупця',
    'customer.account_deactivated' => 'Деактивовано акаунт покупця'
];
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
            <p>Останні <?= count($entries) ?> записів. Паролі та інші секретні значення в журнал не записуються.</p>
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
                                        <b><?= htmlspecialchars((string) $key) ?>:</b>
                                        <?= htmlspecialchars(is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>
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
