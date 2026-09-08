<?php
$admin = is_array($admin ?? null) ? $admin : [];
$csrfToken = (string) ($csrfToken ?? '');
$message = trim((string) ($message ?? ''));
$error = trim((string) ($error ?? ''));
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Адмін-панель · Профіль') ?></title>
    <link rel="stylesheet" href="/Anabelka/css/admin-profile.css?v=1">
</head>
<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="admin-profile-page">
    <section class="admin-profile-hero">
        <div>
            <span>Безпека акаунта</span>
            <h2>Мій профіль</h2>
            <p>Особисті дані та пароль поточного адміністратора.</p>
        </div>
        <div class="admin-profile-role">
            <small>Роль</small>
            <strong><?= htmlspecialchars((string) ($admin['role_name'] ?? '')) ?></strong>
        </div>
    </section>

    <?php if ($message !== ''): ?>
        <div class="admin-profile-message is-success">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="admin-profile-message is-error">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <section class="admin-profile-card">
        <div class="admin-profile-card-head">
            <h3>Дані профілю</h3>
            <p>Для зміни імені або email підтвердьте дію поточним паролем.</p>
        </div>

        <form method="post" action="/Anabelka/admin/profile" autocomplete="off">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

            <label>
                <span>Ім’я</span>
                <input
                    type="text"
                    name="name"
                    maxlength="120"
                    value="<?= htmlspecialchars((string) ($admin['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                    autocomplete="name"
                    required
                >
            </label>

            <label>
                <span>Email для входу</span>
                <input
                    type="email"
                    name="email"
                    maxlength="190"
                    value="<?= htmlspecialchars((string) ($admin['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                    autocomplete="username"
                    inputmode="email"
                    required
                >
            </label>

            <label>
                <span>Поточний пароль</span>
                <input
                    type="password"
                    name="current_password"
                    autocomplete="current-password"
                    required
                >
            </label>

            <button type="submit">Зберегти профіль</button>
        </form>
    </section>

    <section class="admin-profile-card">
        <div class="admin-profile-card-head">
            <h3>Змінити пароль</h3>
            <p>Новий пароль має містити щонайменше 10 символів.</p>
        </div>

        <form method="post" action="/Anabelka/admin/profile/password" autocomplete="off">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

            <label>
                <span>Поточний пароль</span>
                <input
                    type="password"
                    name="current_password"
                    autocomplete="current-password"
                    required
                >
            </label>

            <label>
                <span>Новий пароль</span>
                <input
                    type="password"
                    name="new_password"
                    minlength="10"
                    autocomplete="new-password"
                    required
                >
            </label>

            <label>
                <span>Повторіть новий пароль</span>
                <input
                    type="password"
                    name="new_password_confirmation"
                    minlength="10"
                    autocomplete="new-password"
                    required
                >
            </label>

            <button type="submit">Змінити пароль</button>
        </form>
    </section>

    <section class="admin-profile-meta">
        <div>
            <span>Останній вхід</span>
            <strong><?= htmlspecialchars((string) ($admin['last_login_at'] ?? '—')) ?></strong>
        </div>
        <div>
            <span>Акаунт створено</span>
            <strong><?= htmlspecialchars((string) ($admin['created_at'] ?? '—')) ?></strong>
        </div>
    </section>
</main>

</body>
</html>
