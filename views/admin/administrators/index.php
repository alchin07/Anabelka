<?php
$administrators = is_array($administrators ?? null) ? $administrators : [];
$roles = is_array($roles ?? null) ? $roles : [];
$assignableRoles = is_array($assignableRoles ?? null) ? $assignableRoles : [];
$permissions = is_array($permissions ?? null) ? $permissions : [];
$csrfToken = (string) ($csrfToken ?? '');
$inviteFlash = is_array($inviteFlash ?? null) ? $inviteFlash : null;
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
    'content' => 'Контент',
    'translations' => 'Мови та переклади',
    'security' => 'Безпека'
];

$inviteChannelLabels = [
    'viber' => 'Viber',
    'whatsapp' => 'WhatsApp',
    'telegram' => 'Telegram',
    'other' => 'Інший месенджер'
];

$inviteUrl = '';
$inviteMessage = '';

if ($inviteFlash && !empty($inviteFlash['invite_token'])) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        ? 'https'
        : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $inviteUrl = ($host !== '' ? $scheme . '://' . $host : '')
        . '/Anabelka/admin-invite?token='
        . rawurlencode((string) $inviteFlash['invite_token']);
    $inviteMessage = "Вас запросили до адмін-панелі магазину «Анабелька».\n"
        . "Логін: " . (string) ($inviteFlash['email'] ?? '') . "\n"
        . "Роль: " . (string) ($inviteFlash['role_name'] ?? '') . "\n"
        . "Встановіть власний пароль за одноразовим посиланням:\n"
        . $inviteUrl;
}

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
    <link rel="stylesheet" href="/Anabelka/css/admin-administrators.css?v=3">
</head>
<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="admin-security-page">
    <section class="admin-security-hero">
        <div>
            <span class="admin-security-kicker">Безпека</span>
            <h2>Адміністратори, ролі та права</h2>
            <p>Акаунти працівників відокремлені від покупців. Розробник має повний доступ і захищений від випадкового вимкнення.</p>
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

    <?php if ($inviteFlash && $inviteMessage !== ''): ?>
        <section class="admin-security-panel admin-invite-result">
            <div class="admin-security-panel-head">
                <div>
                    <h3>Запрошення готове</h3>
                    <p>
                        Одноразове посилання діє до
                        <?= htmlspecialchars((string) ($inviteFlash['expires_at'] ?? '')) ?>.
                        Пароль у повідомленні не передається.
                    </p>
                </div>
                <span class="admin-invite-channel">
                    <?= htmlspecialchars(
                        $inviteChannelLabels[$inviteFlash['channel'] ?? 'other']
                            ?? 'Месенджер'
                    ) ?>
                </span>
            </div>

            <div class="admin-invite-meta">
                <span>
                    <b>Адміністратор:</b>
                    <?= htmlspecialchars((string) ($inviteFlash['name'] ?? '')) ?>
                </span>
                <span>
                    <b>Логін:</b>
                    <?= htmlspecialchars((string) ($inviteFlash['email'] ?? '')) ?>
                </span>
                <span>
                    <b>Роль:</b>
                    <?= htmlspecialchars((string) ($inviteFlash['role_name'] ?? '')) ?>
                </span>
                <span>
                    <b>Контакт:</b>
                    <?= htmlspecialchars((string) ($inviteFlash['contact'] ?? '')) ?>
                </span>
            </div>

            <label class="admin-invite-message-field">
                <span>Готовий текст запрошення</span>
                <textarea id="admin-invite-message" rows="7" readonly><?= htmlspecialchars($inviteMessage) ?></textarea>
            </label>

            <div class="admin-invite-actions">
                <button
                    type="button"
                    id="admin-invite-share"
                    data-invite-message="<?= htmlspecialchars($inviteMessage, ENT_QUOTES, 'UTF-8') ?>"
                >
                    Поділитися через месенджер
                </button>
                <button type="button" id="admin-invite-copy">
                    Копіювати текст
                </button>
                <span id="admin-invite-copy-status" aria-live="polite"></span>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($canManage): ?>
        <section class="admin-security-panel">
            <div class="admin-security-panel-head">
                <div>
                    <h3>Запросити адміністратора</h3>
                    <p>
                        Створіть службовий акаунт, призначте роль і передайте
                        одноразове посилання через Viber, WhatsApp, Telegram
                        або інший месенджер. Пароль адміністратор встановить сам.
                    </p>
                </div>
            </div>

            <form class="admin-create-form admin-invite-create-form" method="post" action="/Anabelka/admin/administrators/invite" autocomplete="off">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <label>
                    <span>Ім’я</span>
                    <input type="text" name="name" maxlength="120" autocomplete="off" required>
                </label>
                <label>
                    <span>Email / логін</span>
                    <input type="email" name="email" maxlength="190" autocomplete="off" required>
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
                <label>
                    <span>Месенджер</span>
                    <select name="invite_channel" required>
                        <option value="viber">Viber</option>
                        <option value="whatsapp">WhatsApp</option>
                        <option value="telegram">Telegram</option>
                        <option value="other">Інший</option>
                    </select>
                </label>
                <label>
                    <span>Телефон / username</span>
                    <input
                        type="text"
                        name="invite_contact"
                        maxlength="160"
                        autocomplete="off"
                        placeholder="+49… або @username"
                        required
                    >
                </label>
                <button type="submit">Створити запрошення</button>
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
                $inviteStatus = trim((string) ($admin['invitation_status'] ?? ''));
                $isPendingInvite = in_array(
                    $inviteStatus,
                    ['created', 'sent'],
                    true
                );
                $isExpiredInvite = $inviteStatus === 'expired';
                $isRevokedInvite = $inviteStatus === 'revoked';
                $isInvitationRestricted = (
                    $isPendingInvite
                    || $isExpiredInvite
                    || $isRevokedInvite
                );
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
                            <?php if ($isPendingInvite): ?>
                                <span class="admin-staff-status is-pending">
                                    Очікує активації
                                </span>
                            <?php elseif ($isExpiredInvite): ?>
                                <span class="admin-staff-status is-expired">
                                    Запрошення прострочено
                                </span>
                            <?php elseif ($isRevokedInvite): ?>
                                <span class="admin-staff-status is-revoked">
                                    Запрошення відкликано
                                </span>
                            <?php else: ?>
                                <span class="admin-staff-status <?= $isActive ? 'is-active' : 'is-disabled' ?>">
                                    <?= $isActive ? 'Активний' : 'Вимкнений' ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($isCurrent): ?>
                                <span class="admin-staff-current">Це ви</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($isOwner): ?>
                        <div class="admin-owner-protection">
                            Розробник має повний доступ. Його роль і доступ не можна змінити з цієї сторінки.
                        </div>
                    <?php elseif ($canManage): ?>
                        <div class="admin-staff-actions<?= $isInvitationRestricted ? ' has-invitation' : '' ?>">
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

                            <?php if ($isInvitationRestricted): ?>
                                <div class="admin-invite-card-actions">
                                    <div class="admin-invite-card-copy">
                                        <strong>
                                            <?= $isRevokedInvite
                                                ? 'Активацію скасовано'
                                                : ($isExpiredInvite
                                                    ? 'Потрібне нове запрошення'
                                                    : 'Очікуємо активацію') ?>
                                        </strong>
                                        <span>
                                            <?php if ($isPendingInvite): ?>
                                                Посилання діє до
                                                <?= htmlspecialchars((string) ($admin['invitation_expires_at'] ?? '')) ?>.
                                            <?php elseif ($isExpiredInvite): ?>
                                                Попереднє посилання вже не працює.
                                            <?php else: ?>
                                                Старе посилання недійсне.
                                            <?php endif; ?>
                                        </span>
                                        <?php if (!empty($admin['invitation_contact'])): ?>
                                            <small>
                                                Контакт:
                                                <?= htmlspecialchars((string) $admin['invitation_contact']) ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>

                                    <div class="admin-invite-card-buttons">
                                        <form
                                            method="post"
                                            action="/Anabelka/admin/administrators/invite/reissue"
                                            data-anabelka-confirm="Створити нове одноразове запрошення? Попереднє посилання одразу перестане працювати."
                                            data-anabelka-confirm-title="Нове запрошення"
                                            data-anabelka-confirm-confirm-text="Створити"
                                        >
                                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="admin_id" value="<?= $adminId ?>">
                                            <button type="submit">
                                                Повторно видати запрошення
                                            </button>
                                        </form>

                                        <?php if (!$isRevokedInvite): ?>
                                            <form
                                                method="post"
                                                action="/Anabelka/admin/administrators/invite/revoke"
                                                data-anabelka-confirm="Відкликати запрошення? Посилання перестане працювати, а акаунт залишиться неактивним."
                                                data-anabelka-confirm-title="Відкликати запрошення"
                                                data-anabelka-confirm-confirm-text="Відкликати"
                                                data-anabelka-confirm-danger="1"
                                            >
                                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="admin_id" value="<?= $adminId ?>">
                                                <button class="is-danger" type="submit">
                                                    Відкликати запрошення
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php else: ?>
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
                            <?php endif; ?>
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
                <p>Розробник має незмінний повний доступ. Права інших системних і власних ролей можна налаштовувати.</p>
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
                                ? 'Розробник завжди має всі права.'
                                : 'Це готова системна роль. Її права можна змінювати; базовий доступ до адмін-панелі залишається обов’язковим.' ?>
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
                                                <?= ($isOwnerRole || !$canManage || in_array($permissionKey, ['admin.access', 'dashboard.view'], true)) ? 'disabled' : '' ?>
                                            >
                                            <span><?= htmlspecialchars($permission['name'] ?? '') ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </fieldset>
                            <?php endforeach; ?>
                        </div>

                        <?php if (!$isOwnerRole && $canManage): ?>
                            <button class="admin-role-save" type="submit">Зберегти права</button>
                        <?php endif; ?>
                    </form>
                </details>
            <?php endforeach; ?>
        </div>
    </section>
</main>

<?php if ($inviteFlash && $inviteMessage !== ''): ?>
<script>
(function () {
    const messageNode = document.getElementById('admin-invite-message');
    const shareButton = document.getElementById('admin-invite-share');
    const copyButton = document.getElementById('admin-invite-copy');
    const statusNode = document.getElementById('admin-invite-copy-status');

    if (!messageNode) {
        return;
    }

    const message = messageNode.value;

    async function copyMessage()
    {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(message);
            return;
        }

        messageNode.focus();
        messageNode.select();
        document.execCommand('copy');
        messageNode.setSelectionRange(0, 0);
    }

    if (shareButton) {
        shareButton.addEventListener('click', async function () {
            try {
                if (navigator.share) {
                    await navigator.share({
                        title: 'Запрошення до адмін-панелі Анабельки',
                        text: message
                    });
                    if (statusNode) {
                        statusNode.textContent = 'Вікно надсилання відкрито.';
                    }
                    return;
                }

                await copyMessage();
                if (statusNode) {
                    statusNode.textContent =
                        'Текст скопійовано. Вставте його у месенджер.';
                }
            } catch (error) {
                if (error && error.name === 'AbortError') {
                    return;
                }

                if (statusNode) {
                    statusNode.textContent =
                        'Не вдалося відкрити надсилання. Скопіюйте текст вручну.';
                }
            }
        });
    }

    if (copyButton) {
        copyButton.addEventListener('click', async function () {
            try {
                await copyMessage();
                if (statusNode) {
                    statusNode.textContent = 'Текст скопійовано.';
                }
            } catch (error) {
                if (statusNode) {
                    statusNode.textContent = 'Не вдалося скопіювати текст.';
                }
            }
        });
    }
}());
</script>
<?php endif; ?>

</body>
</html>
