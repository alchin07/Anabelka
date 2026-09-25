<?php
$entries = is_array($entries ?? null) ? $entries : [];
$auditUnreadTotal = max(0, (int) ($auditUnreadTotal ?? 0));
$auditUnreadByActor = is_array($auditUnreadByActor ?? null)
    ? $auditUnreadByActor
    : [];
$auditUnreadEntryIds = is_array($auditUnreadEntryIds ?? null)
    ? array_values(array_unique(array_map('intval', $auditUnreadEntryIds)))
    : [];
$auditUnreadEntryLookup = array_fill_keys($auditUnreadEntryIds, true);
$canClearAuditUnread = !empty($canClearAuditUnread);
$csrfToken = (string) ($csrfToken ?? '');
$flash = is_array($flash ?? null) ? $flash : null;
$recentEntries = is_array($recentEntries ?? null)
    ? $recentEntries
    : array_slice($entries, 0, 3);
$olderAuditGroups = is_array($olderAuditGroups ?? null)
    ? $olderAuditGroups
    : AdminManagement::groupAuditEntriesByAdministrator(
        array_slice($entries, 3)
    );
$auditAdministrators = is_array($auditAdministrators ?? null)
    ? $auditAdministrators
    : [];
$auditActions = is_array($auditActions ?? null)
    ? $auditActions
    : [];
$filters = AdminManagement::normalizeAuditFilters(
    is_array($filters ?? null) ? $filters : []
);
$actionLabels = [
    'admin.owner_created' => 'Створено розробника',
    'admin.login' => 'Вхід адміністратора',
    'admin.login_failed' => 'Невдала спроба входу',
    'admin.logout' => 'Вихід адміністратора',
    'admin.created' => 'Створено адміністратора',
    'admin.invitation_created' => 'Створено запрошення адміністратора',
    'admin.invitation_reissued' => 'Повторно видано запрошення адміністратора',
    'admin.invitation_revoked' => 'Відкликано запрошення адміністратора',
    'admin.invitation_accepted' => 'Адміністратор прийняв запрошення',
    'admin.role_changed' => 'Змінено роль адміністратора',
    'admin.access_toggled' => 'Змінено доступ адміністратора',
    'admin.password_reset' => 'Змінено пароль адміністратора',
    'admin.deleted' => 'Видалено адміністратора',
    'admin.role_created' => 'Створено власну роль',
    'admin.role_renamed' => 'Перейменовано власну роль',
    'admin.role_deleted' => 'Видалено власну роль',
    'admin.role_permissions_updated' => 'Змінено права ролі',
    'admin.profile_updated' => 'Оновлено власний профіль',
    'admin.password_changed' => 'Змінено власний пароль',
    'admin.notification_badge_preferences_updated' => 'Оновлено налаштування бейджа сповіщень',

    'dashboard.builder.block.create' => 'Створено блок адмін-главної',
    'dashboard.builder.block.update' => 'Оновлено блок адмін-главної',
    'dashboard.builder.block.toggle' => 'Змінено активність блока адмін-главної',
    'dashboard.builder.block.delete' => 'Видалено блок адмін-главної',
    'dashboard.builder.link.create' => 'Створено ярлик адмін-главної',
    'dashboard.builder.link.update' => 'Оновлено ярлик адмін-главної',
    'dashboard.builder.link.toggle' => 'Змінено активність ярлика адмін-главної',
    'dashboard.builder.link.delete' => 'Видалено ярлик адмін-главної',
    'dashboard.builder.blocks.reorder' => 'Змінено порядок блоків адмін-главної',
    'dashboard.builder.links.reorder' => 'Змінено порядок ярликів адмін-главної',

    'order.status_changed' => 'Змінено стан замовлення',

    'product.created' => 'Створено товар',
    'product.updated' => 'Оновлено товар',
    'product.visibility_changed' => 'Змінено видимість товару',
    'product.duplicated' => 'Створено копію товару',
    'product.variant_stock_updated' => 'Оновлено залишки варіантів товару',

    'category.created' => 'Створено категорію',
    'category.updated' => 'Оновлено категорію',
    'category.reordered' => 'Змінено порядок категорій',
    'category.moved' => 'Переміщено категорію',
    'category.flag_changed' => 'Змінено стан категорії',
    'category.deleted' => 'Видалено категорію',

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

    'mobile_navigation.create' => 'Створено пункт мобільного меню',
    'mobile_navigation.update' => 'Оновлено пункт мобільного меню',
    'mobile_navigation.toggle' => 'Змінено активність пункту мобільного меню',
    'mobile_navigation.move' => 'Переміщено пункт мобільного меню',
    'mobile_navigation.delete' => 'Видалено пункт мобільного меню',

    'news.create' => 'Створено новину',
    'news.update' => 'Оновлено новину',
    'news.publish' => 'Змінено публікацію новини',
    'news.delete' => 'Видалено новину',

    'review.moderate' => 'Змінено модерацію відгуку',
    'review.delete' => 'Видалено відгук',

    'social_auth.provider_toggled' => 'Змінено активність способу входу',
    'social_auth.provider_moved' => 'Змінено порядок способів входу',

    'system.error_status.update' => 'Змінено статус системної помилки',
    'system.error_note.update' => 'Оновлено нотатку системної помилки',
    'system.error_external_notifications.update' => 'Оновлено зовнішні сповіщення про помилки',
    'system.error_external_notifications.test' => 'Виконано тест зовнішніх сповіщень',
    'system.error_test' => 'Виконано тест обробника помилок',
    'system.backup_created' => 'Створено резервну копію',
    'system.backup_downloaded' => 'Завантажено резервну копію',

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
    'old_name' => 'Попередня назва',
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

    'block_id' => 'ID блока',
    'block_ids' => 'Блоки',
    'link_id' => 'ID ярлика',
    'link_ids' => 'Ярлики',
    'service_key' => 'Служба',
    'provider' => 'Провайдер',
    'enabled' => 'Увімкнено',
    'reference' => 'Код помилки',
    'status' => 'Статус',
    'group_key' => 'Група помилки',
    'action' => 'Дія',

    'name' => 'Назва',
    'is_active' => 'Активний'
];

$formatDetail = static function ($key, $value) {
    if (in_array($key, ['is_active', 'is_enabled', 'enabled', 'name_changed'], true)) {
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
    <link rel="stylesheet" href="/Anabelka/css/admin-administrators.css?v=9">
</head>
<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main
    class="admin-security-page"
    data-admin-audit-journal
    data-audit-seen-endpoint="/Anabelka/admin/audit/seen"
    data-audit-csrf="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"
>
    <section class="admin-security-hero">
        <div>
            <span class="admin-security-kicker">Безпека</span>
            <h2>Журнал адміністративних дій</h2>
            <p>
                Показано <?= count($entries) ?> записів.
                Паролі, токени та тексти перекладів у журнал не записуються.
            </p>
        </div>
        <?php if (AdminAccess::can('administrators.view')): ?>
            <a class="admin-security-audit-link" href="/Anabelka/admin/administrators">Адміністратори</a>
        <?php endif; ?>
    </section>

    <?php if ($flash): ?>
        <div class="admin-security-message <?= ($flash['type'] ?? '') === 'error' ? 'is-error' : 'is-success' ?>" role="status">
            <?= htmlspecialchars((string) ($flash['message'] ?? '')) ?>
        </div>
    <?php endif; ?>

    <section class="admin-security-panel">
        <form
            class="admin-audit-filters"
            method="get"
            action="/Anabelka/admin/audit"
        >
            <label>
                <span>Адміністратор</span>
                <select name="admin_id">
                    <option value="0">Усі адміністратори</option>
                    <?php foreach ($auditAdministrators as $auditAdmin): ?>
                        <?php $filterAdminId = (int) ($auditAdmin['id'] ?? 0); ?>
                        <option
                            value="<?= $filterAdminId ?>"
                            <?= $filters['admin_id'] === $filterAdminId ? 'selected' : '' ?>
                        >
                            <?= htmlspecialchars(
                                trim(
                                    (string) ($auditAdmin['name'] ?? '')
                                    . ' · '
                                    . (string) ($auditAdmin['email'] ?? '')
                                )
                            ) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span>Дія</span>
                <select name="action">
                    <option value="">Усі дії</option>
                    <?php foreach ($auditActions as $auditAction): ?>
                        <?php $auditAction = (string) $auditAction; ?>
                        <option
                            value="<?= htmlspecialchars($auditAction, ENT_QUOTES, 'UTF-8') ?>"
                            <?= $filters['action'] === $auditAction ? 'selected' : '' ?>
                        >
                            <?= htmlspecialchars($actionLabels[$auditAction] ?? $auditAction) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span>Від</span>
                <input
                    type="date"
                    name="date_from"
                    value="<?= htmlspecialchars($filters['date_from'], ENT_QUOTES, 'UTF-8') ?>"
                >
            </label>

            <label>
                <span>До</span>
                <input
                    type="date"
                    name="date_to"
                    value="<?= htmlspecialchars($filters['date_to'], ENT_QUOTES, 'UTF-8') ?>"
                >
            </label>

            <button type="submit">Фільтрувати</button>
            <a href="/Anabelka/admin/audit">Скинути</a>
        </form>
    </section>

    <?php
    $renderAuditEntry = static function (
        array $entry,
        $open
    ) use (
        $actionLabels,
        $detailLabels,
        $formatDetail,
        $auditUnreadEntryLookup
    ) {
        $details = json_decode(
            (string) ($entry['details'] ?? ''),
            true
        );
        $details = is_array($details) ? $details : [];
        $action = (string) ($entry['action'] ?? '');
        $actorName = trim((string) ($entry['admin_name'] ?? ''));
        $actorEmail = trim((string) ($entry['admin_email'] ?? ''));
        $actorId = (int) ($entry['admin_user_id'] ?? 0);
        $actorKey = $actorId > 0
            ? 'admin-' . $actorId
            : 'system';
        $entryId = (int) ($entry['id'] ?? 0);
        $entryUnread = $entryId > 0
            && isset($auditUnreadEntryLookup[$entryId]);
        ?>
        <details
            class="admin-audit-item"
            data-audit-entry-id="<?= $entryId ?>"
            data-audit-actor-key="<?= htmlspecialchars($actorKey, ENT_QUOTES, 'UTF-8') ?>"
            data-audit-unread="<?= $entryUnread ? '1' : '0' ?>"
            <?= $open ? 'open' : '' ?>
        >
            <summary class="admin-audit-summary">
                <span class="admin-audit-summary-main">
                    <strong>
                        <?= htmlspecialchars($actionLabels[$action] ?? $action) ?>
                    </strong>
                    <small class="admin-audit-summary-actor">
                        <span>
                            <?= htmlspecialchars(
                                $actorName !== ''
                                    ? $actorName
                                    : 'Система / невідомий адміністратор'
                            ) ?>
                        </span>
                        <?php if ($entryUnread): ?>
                            <b
                                class="admin-audit-new-badge"
                                data-audit-entry-new-badge
                            >
                                1 нове
                            </b>
                        <?php endif; ?>
                    </small>
                </span>
                <span class="admin-audit-summary-side">
                    <time><?= htmlspecialchars($entry['created_at'] ?? '') ?></time>
                    <span class="admin-audit-chevron" aria-hidden="true">⌄</span>
                </span>
            </summary>

            <div class="admin-audit-body">
                <div class="admin-audit-head">
                    <strong><?= htmlspecialchars($actionLabels[$action] ?? $action) ?></strong>
                    <time><?= htmlspecialchars($entry['created_at'] ?? '') ?></time>
                </div>

                <div class="admin-audit-actor">
                    <?php if ($actorName !== ''): ?>
                        <span><?= htmlspecialchars($actorName) ?></span>
                        <?php if ($actorEmail !== ''): ?>
                            <small><?= htmlspecialchars($actorEmail) ?></small>
                        <?php endif; ?>
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
            </div>
        </details>
        <?php
    };
    ?>

    <section class="admin-security-panel">
        <?php if (empty($entries)): ?>
            <div class="admin-audit-empty">За вибраними фільтрами записів немає.</div>
        <?php else: ?>
            <?php if (!empty($recentEntries)): ?>
                <section class="admin-audit-group is-recent">
                    <div class="admin-audit-group-head">
                        <div>
                            <span>Останні</span>
                            <h3>3 найсвіжіші дії</h3>
                        </div>
                        <strong><?= count($recentEntries) ?></strong>
                    </div>

                    <div class="admin-audit-list">
                        <?php foreach ($recentEntries as $entry): ?>
                            <?php $renderAuditEntry($entry, true); ?>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php if (!empty($olderAuditGroups)): ?>
                <div class="admin-audit-older-title">
                    <span>Старіші записи</span>
                    <strong>За адміністратором</strong>
                </div>

                <?php foreach ($olderAuditGroups as $group): ?>
                    <?php
                    $groupEntries = is_array($group['entries'] ?? null)
                        ? $group['entries']
                        : [];
                    $groupKey = (string) ($group['key'] ?? 'system');
                    $groupUnread = max(
                        0,
                        (int) ($auditUnreadByActor[$groupKey]['count'] ?? 0)
                    );
                    ?>
                    <section class="admin-audit-group">
                        <div class="admin-audit-group-head">
                            <div>
                                <span>Адміністратор</span>
                                <div class="admin-audit-group-name-row">
                                    <h3><?= htmlspecialchars($group['name'] ?? 'Система / невідомий адміністратор') ?></h3>
                                    <?php if ($groupUnread > 0): ?>
                                        <span
                                            class="admin-audit-new-badge"
                                            data-audit-group-badge="<?= htmlspecialchars($groupKey, ENT_QUOTES, 'UTF-8') ?>"
                                            data-audit-count="<?= $groupUnread ?>"
                                        >
                                            <?= $groupUnread ?> нових
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($group['email'])): ?>
                                    <small><?= htmlspecialchars($group['email']) ?></small>
                                <?php endif; ?>
                            </div>
                            <strong><?= (int) ($group['count'] ?? count($groupEntries)) ?></strong>
                        </div>

                        <div class="admin-audit-list">
                            <?php foreach ($groupEntries as $entry): ?>
                                <?php $renderAuditEntry($entry, false); ?>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <?php if ($canClearAuditUnread): ?>
        <section class="admin-audit-clear-all">
            <form
                method="post"
                action="/Anabelka/admin/audit/seen-all"
                data-anabelka-confirm="Позначити всі поточні дії прочитаними? Історія журналу не буде видалена."
                data-anabelka-confirm-title="Обнулити нові дії"
                data-anabelka-confirm-confirm-text="Позначити прочитаними"
            >
                <input
                    type="hidden"
                    name="_csrf"
                    value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"
                >
                <button
                    type="submit"
                    <?= $auditUnreadTotal > 0 ? '' : 'disabled' ?>
                >
                    Позначити всі дії прочитаними
                </button>
            </form>
            <small>
                Доступно лише Розробнику та Власнику. Записи журналу не видаляються.
            </small>
        </section>
    <?php endif; ?>
</main>

<script src="/Anabelka/js/admin-audit-read-state.js?v=1" defer></script>
</body>
</html>
