<?php
$invite = is_array($invite ?? null) ? $invite : null;
$error = trim((string) ($error ?? ''));
$token = trim((string) ($token ?? ''));
$csrfToken = (string) ($csrfToken ?? '');
$passwordMinLength = class_exists('PasswordPolicy')
    ? PasswordPolicy::minimumLength()
    : 10;
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Запрошення адміністратора — Анабелька</title>
    <link rel="stylesheet" href="/Anabelka/css/admin-auth.css?v=1">
</head>
<body class="admin-auth-page">
    <main class="admin-auth-card">
        <p class="admin-auth-brand">Анабелька</p>
        <h1>Запрошення адміністратора</h1>

        <?php if ($invite): ?>
            <p class="admin-auth-intro">
                Для <strong><?= htmlspecialchars((string) ($invite['name'] ?? '')) ?></strong>
                створено службовий акаунт.
                Встановіть власний пароль для першого входу.
            </p>

            <p class="admin-auth-note" style="margin-top:0;padding-top:0;border-top:0;">
                Логін:
                <strong><?= htmlspecialchars((string) ($invite['email'] ?? '')) ?></strong><br>
                Роль:
                <strong><?= htmlspecialchars((string) ($invite['role_name'] ?? '')) ?></strong><br>
                Посилання діє до:
                <strong><?= htmlspecialchars((string) ($invite['expires_at'] ?? '')) ?></strong>
            </p>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="admin-auth-error">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($invite): ?>
            <form
                class="admin-auth-form"
                method="post"
                action="/Anabelka/admin-invite"
                autocomplete="on"
            >
                <input
                    type="hidden"
                    name="_csrf"
                    value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"
                >
                <input
                    type="hidden"
                    name="token"
                    value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>"
                >

                <label class="admin-auth-field">
                    <span>Новий пароль</span>
                    <input
                        type="password"
                        name="password"
                        minlength="<?= (int) $passwordMinLength ?>"
                        autocomplete="new-password"
                        required
                    >
                </label>

                <label class="admin-auth-field">
                    <span>Повторіть пароль</span>
                    <input
                        type="password"
                        name="password_confirm"
                        minlength="<?= (int) $passwordMinLength ?>"
                        autocomplete="new-password"
                        required
                    >
                </label>

                <p class="admin-auth-hint">
                    Пароль не передається тому, хто створив запрошення.
                    Після активації це посилання перестане працювати.
                </p>

                <button class="admin-auth-submit" type="submit">
                    Активувати та увійти
                </button>
            </form>
        <?php else: ?>
            <p class="admin-auth-note">
                Попросіть власника або розробника створити нове запрошення.
            </p>
        <?php endif; ?>
    </main>
</body>
</html>
