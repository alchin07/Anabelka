<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Початкове налаштування адміністратора — Анабелька</title>
    <link rel="stylesheet" href="/Anabelka/css/admin-auth.css?v=1">
</head>
<body class="admin-auth-page">
    <main class="admin-auth-card">
        <p class="admin-auth-brand">Анабелька</p>
        <h1>Створення власника</h1>
        <p class="admin-auth-intro">
            Це виконується один раз. Власник матиме повний доступ до адмін-панелі
            та надалі зможе створювати інших адміністраторів і призначати їм ролі.
        </p>

        <?php if (!empty($error)): ?>
            <div class="admin-auth-error">
                <?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form class="admin-auth-form" method="post" action="/Anabelka/admin/setup" autocomplete="off">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">

            <label class="admin-auth-field">
                <span>Ім’я власника</span>
                <input
                    type="text"
                    name="name"
                    maxlength="120"
                    value="<?= htmlspecialchars((string) ($name ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                    autocomplete="name"
                    required
                >
            </label>

            <label class="admin-auth-field">
                <span>Email для входу</span>
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
                <span>Пароль адміністратора</span>
                <input
                    type="password"
                    name="password"
                    minlength="10"
                    autocomplete="new-password"
                    required
                >
            </label>
            <p class="admin-auth-hint">Щонайменше 10 символів. Пароль зберігається лише як захищений хеш.</p>

            <button class="admin-auth-submit" type="submit">
                Створити власника та увійти
            </button>
        </form>

        <p class="admin-auth-note">
            <strong>Важливо:</strong> це окремий обліковий запис адміністратора.
            Він не є рангом покупця і не впливає на ціни магазину.
        </p>
    </main>
</body>
</html>
