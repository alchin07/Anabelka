<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вхід до адмін-панелі — Анабелька</title>
    <link rel="stylesheet" href="/Anabelka/css/admin-auth.css?v=1">
</head>
<body class="admin-auth-page">
    <main class="admin-auth-card">
        <p class="admin-auth-brand">Анабелька</p>
        <h1>Вхід адміністратора</h1>
        <p class="admin-auth-intro">
            Увійдіть окремим адміністративним обліковим записом.
        </p>

        <?php if (!empty($error)): ?>
            <div class="admin-auth-error">
                <?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form class="admin-auth-form" method="post" action="/Anabelka/admin/login" autocomplete="on">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">

            <label class="admin-auth-field">
                <span>Email</span>
                <input
                    type="email"
                    name="email"
                    maxlength="190"
                    value="<?= htmlspecialchars((string) ($email ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                    autocomplete="username"
                    inputmode="email"
                    required
                >
            </label>

            <label class="admin-auth-field">
                <span>Пароль</span>
                <input
                    type="password"
                    name="password"
                    autocomplete="current-password"
                    required
                >
            </label>

            <button class="admin-auth-submit" type="submit">
                Увійти до адмін-панелі
            </button>
        </form>

        <p class="admin-auth-note">
            Обліковий запис покупця для входу в адмін-панель не використовується.
        </p>
    </main>
</body>
</html>
