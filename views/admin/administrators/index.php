<?php
$administrators = is_array($administrators ?? null) ? $administrators : [];
$roles = is_array($roles ?? null) ? $roles : [];
$assignableRoles = is_array($assignableRoles ?? null) ? $assignableRoles : [];
$permissions = is_array($permissions ?? null) ? $permissions : [];
$csrfToken = (string) ($csrfToken ?? '');
$currentAdminId = AdminAccess::currentId();
$canManage = AdminAccess::can('administrators.manage');
$canAudit = AdminAccess::can('audit.view');

$groupLabels = [
    'system' => 'Система',
    'dashboard' => 'Головна',
    'orders' => 'Замовлення',
    'search' => 'Пошук',
    'users' => 'Користувачі',
    'ranks' => 'Ранги',
    'catalog' => 'Каталог',
    'delivery' => 'Доставка',
    'translations' => 'Мови та переклади',
    'security' => 'Безпека'
];

$permissionGroups = [];
foreach ($permissions as $permission) {
    $group = (string) ($permission['group_key'] ?? 'other');
    $permissionGroups[$group][] = $permission;
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Адміністратори') ?></title>
    <link rel="stylesheet" href="/Anabelka/css/admin-administrators.css?v=1">
</head>
<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="admin-security-page">
    <section class="admin-security-hero">
        <div>
            <span class="admin-security-kicker">Безпека</span>
            <h2>Адміністратори, ролі та права</h2>
            <p>Акаунти працівників відокремлені від покупців. Власник має повний доступ і захищений від випадкового вимкнення.</p>
        </div>
        <?php if ($canAudit): ?>
            <a class="admin-security-audit-link" href="/Anabelka/admin/audit">Журнал дій</a>
        <?php endif; ?>
    </section>

    <?php if (!empty($message)): ?>
        <div class="admin-security-message is-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="admin-security-message is-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($canManage): ?>
        <section class="admin-security-panel">
            <div class="admin-security-panel-head">
                <div>
                    <h3>Новий адміністратор</h3>
                    <p>Створіть окремий службовий акаунт і одразу призначте роль.</p>
                </div>
            </div>

            <form class="admin-create-form" method="post" action="/Anabelka/admin/administrators/create" autocomplete="off">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <label>
                    <span>Ім’я</span>
                    <input type="text" name="name" maxlength="120" autocomplete="off" required>
                </label>
                <label>
                    <span>Email</span>
                    <input type="email" name="email" maxlength="190" autocomplete="off" required>
                </label>
                <label>
                    <span>Початковий пароль</span>
                    <input type="password" name="password" minlength="10" autocomplete="new-password" required>
                    <small>Щонайменше 10 символів</small>
                </label>
                <label>
                    <span>Роль</span>
                    <select name="role_id" required>
                        <?php foreach ($assignableRoles as $role): ?>
                            <option value="<?= (int) ($role['id'] ?? 0) ?>">
                                <?= htmlspecialchars($role['name'] ?? '') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit">Створити</button>
            </form>
        </section>
    <?php endif; ?>

    <section class="admin-security-panel">
        <div class="admin-security-panel-head">
            <div>
                <h3>Адміністратори</h3>
                <p>Всього службових акаунтів: <?= count($administrators) ?></p>
            </div>
        </div>

        <div class="admin-staff-list">
            <?php foreach ($administrators as $admin): ?>
                <?php
                $adminId = (int) ($admin['id'] ?? 0);
                $isOwner = ($admin['role_slug'] ?? '') === 'owner';
                $isCurrent = $adminId === $currentAdminId;
                $isActive = !empty($admin['is_active']);
                ?>
                <article class="admin-staff-card <?= $isOwner ? 'is-owner' : '' ?>">
                    <div class="admin-staff-main">
                        <div class="admin-staff-identity">
                            <strong><?= htmlspecialchars($admin['name'] ?? '') ?></strong>
                            <span><?= htmlspecialchars($admin['email'] ?? '') ?></span>
                            <small>
                                <?= !empty($admin['last_login_at'])
                                    ? 'Останній вхід: ' . htmlspecialchars($admin['last_login_at'])
                                    : 'Ще не входив' ?>
                            </small>
                        </div>
                        <div class="admin-staff-badges">
                            <span class="admin-staff-role"><?= htmlspecialchars($admin['role_name'] ?? '') ?></span>
                            <span class="admin-staff-status <?= $isActive ? 'is-active' : 'is-disabled' ?>">
                                <?= $isActive ? 'Активний' : 'Вимкнений' ?>
                            </span>
                            <?php if ($isCurrent): ?>
                                <span class="admin-staff-current">Це ви</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($isOwner): ?>
                        <div class="admin-owner-protection">
                            Власник має повний доступ. Його роль і доступ не можна змінити з цієї сторінки.
                        </div>
                    <?php elseif ($canManage): ?>
                        <div class="admin-staff-actions">
                            <form method="post" action="/Anabelka/admin/administrators/role" onsubmit="return confirm('Змінити роль цього адміністратора?');">
                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="admin_id" value="<?= $adminId ?>">
                                <label>
                                    <span>Роль</span>
                                    <select name="role_id" <?= $isCurrent ? 'disabled' : '' ?>>
                                        <?php foreach ($assignableRoles as $role): ?>
                                            <option
                                                value="<?= (int) ($role['id'] ?? 0) ?>"
                                                <?= (int) ($role['id'] ?? 0) === (int) ($admin['role_id'] ?? 0) ? 'selected' : '' ?>
                                            ><?= htmlspecialchars($role['name'] ?? '') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <button type="submit" <?= $isCurrent ? 'disabled' : '' ?>>Зберегти роль</button>
                            </form>

                            <form method="post" action="/Anabelka/admin/administrators/password" autocomplete="off" onsubmit="return confirm('Встановити новий пароль цьому адміністратору?');">
                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="admin_id" value="<?= $adminId ?>">
                                <label>
                                    <span>Новий пароль</span>
                                    <input type="password" name="password" minlength="10" autocomplete="new-password" required>
                                </label>
                                <button type="submit">Змінити пароль</button>
                            </form>

                            <form class="admin-access-toggle-form" method="post" action="/Anabelka/admin/administrators/toggle" onsubmit="return confirm('<?= $isActive ? 'Вимкнути доступ цього адміністратора?' : 'Увімкнути доступ цього адміністратора?' ?>');">
                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="admin_id" value="<?= $adminId ?>">
                                <button class="<?= $isActive ? 'is-danger' : 'is-enable' ?>" type="submit" <?= $isCurrent ? 'disabled' : '' ?>>
                                    <?= $isActive ? 'Вимкнути доступ' : 'Увімкнути доступ' ?>
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="admin-security-panel">
        <div class="admin-security-panel-head">
            <div>
                <h3>Ролі та права</h3>
                <p>Системні ролі — готові шаблони. Для іншого набору прав створіть власну роль.</p>
            </div>
        </div>

        <?php if ($canManage): ?>
            <details class="admin-custom-role-create">
                <summary>+ Створити власну роль</summary>
                <form method="post" action="/Anabelka/admin/administrators/roles/create">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <label class="admin-role-name-field">
                        <span>Назва ролі</span>
                        <input type="text" name="role_name" maxlength="100" placeholder="Наприклад: Менеджер каталогу" required>
                    </label>
                    <p class="admin-role-base-note">Базовий доступ до адмін-панелі та головної сторінки додається автоматично.</p>
                    <div class="admin-permission-groups">
                        <?php foreach ($permissionGroups as $groupKey => $items): ?>
                            <?php if ($groupKey === 'system' || $groupKey === 'dashboard') continue; ?>
                            <fieldset>
                                <legend><?= htmlspecialchars($groupLabels[$groupKey] ?? $groupKey) ?></legend>
                                <?php foreach ($items as $permission): ?>
                                    <label class="admin-permission-option">
                                        <input type="checkbox" name="permissions[]" value="<?= htmlspecialchars($permission['permission_key'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                        <span><?= htmlspecialchars($permission['name'] ?? '') ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                        <?php endforeach; ?>
                    </div>
                    <button class="admin-role-save" type="submit">Створити роль</button>
                </form>
            </details>
        <?php endif; ?>

        <div class="admin-role-list">
            <?php foreach ($roles as $role): ?>
                <?php
                $isSystem = !empty($role['is_system']);
                $isOwnerRole = ($role['slug'] ?? '') === 'owner';
                $rolePermissions = array_fill_keys($role['permissions'] ?? [], true);
                ?>
                <details class="admin-role-card" <?= !$isSystem ? 'open' : '' ?>>
                    <summary>
                        <span>
                            <strong><?= htmlspecialchars($role['name'] ?? '') ?></strong>
                            <small><?= $isSystem ? 'Системна роль' : 'Власна роль' ?></small>
                        </span>
                        <b>Адміністраторів: <?= (int) ($role['admin_count'] ?? 0) ?></b>
                    </summary>

                    <?php if ($isSystem): ?>
                        <div class="admin-system-role-note">
                            <?= $isOwnerRole
                                ? 'Власник завжди має всі права.'
                                : 'Це готовий системний шаблон. Його набір прав не редагується; для іншої комбінації створіть власну роль.' ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="/Anabelka/admin/administrators/roles/permissions">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="role_id" value="<?= (int) ($role['id'] ?? 0) ?>">

                        <div class="admin-permission-groups">
                            <?php foreach ($permissionGroups as $groupKey => $items): ?>
                                <fieldset>
                                    <legend><?= htmlspecialchars($groupLabels[$groupKey] ?? $groupKey) ?></legend>
                                    <?php foreach ($items as $permission): ?>
                                        <?php $permissionKey = (string) ($permission['permission_key'] ?? ''); ?>
                                        <label class="admin-permission-option">
                                            <input
                                                type="checkbox"
                                                name="permissions[]"
                                                value="<?= htmlspecialchars($permissionKey, ENT_QUOTES, 'UTF-8') ?>"
                                                <?= isset($rolePermissions[$permissionKey]) ? 'checked' : '' ?>
                                                <?= ($isSystem || !$canManage || in_array($permissionKey, ['admin.access', 'dashboard.view'], true)) ? 'disabled' : '' ?>
                                            >
                                            <span><?= htmlspecialchars($permission['name'] ?? '') ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </fieldset>
                            <?php endforeach; ?>
                        </div>

                        <?php if (!$isSystem && $canManage): ?>
                            <button class="admin-role-save" type="submit">Зберегти права</button>
                        <?php endif; ?>
                    </form>
                </details>
            <?php endforeach; ?>
        </div>
    </section>
</main>

</body>
</html>
