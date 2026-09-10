<?php
PasswordResetInterfaceTranslator::seed();
$currentLanguage = Translator::currentLanguage();
$pageTitle = Translator::t('public.password_reset.request_title', 'Відновлення пароля');
$message = trim((string) ($message ?? ''));
$error = trim((string) ($error ?? ''));
$email = trim((string) ($email ?? ''));
$previewUrl = trim((string) ($previewUrl ?? ''));
$csrfToken = (string) ($csrfToken ?? '');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLanguage['code'] ?? 'uk') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> — Анабелька</title>
    <link rel="stylesheet" href="/Anabelka/css/style.css?v=8">
    <link rel="stylesheet" href="/Anabelka/css/catalog.css?v=4">
    <link rel="stylesheet" href="/Anabelka/css/auth-password-reset.css?v=1">
</head>
<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="catalog password-reset-page">
    <section class="password-reset-card">
        <h2><?= htmlspecialchars(
            Translator::t('public.password_reset.request_heading', 'Забули пароль?')
        ) ?></h2>
        <p><?= htmlspecialchars(
            Translator::t(
                'public.password_reset.request_hint',
                'Вкажіть email вашого акаунта. Ми надішлемо одноразове посилання для створення нового пароля.'
            )
        ) ?></p>

        <?php if ($message !== ''): ?>
            <div class="password-reset-message is-success" role="status">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="password-reset-message is-error" role="alert">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($previewUrl !== ''): ?>
            <a
                class="password-reset-preview"
                href="<?= htmlspecialchars($previewUrl, ENT_QUOTES, 'UTF-8') ?>"
            >
                <?= htmlspecialchars(
                    Translator::t(
                        'public.password_reset.local_preview',
                        'Локальний тест: відкрити посилання відновлення'
                    )
                ) ?>
            </a>
        <?php endif; ?>

        <form class="password-reset-form" method="post" action="/Anabelka/forgot-password">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

            <label class="password-reset-field">
                <span>Email</span>
                <input
                    type="email"
                    name="email"
                    maxlength="190"
                    autocomplete="email"
                    value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"
                    required
                >
            </label>

            <button class="password-reset-submit" type="submit">
                <?= htmlspecialchars(
                    Translator::t(
                        'public.password_reset.request_button',
                        'Надіслати посилання'
                    )
                ) ?>
            </button>
        </form>

        <a class="password-reset-back" href="/Anabelka/login">
            ← <?= htmlspecialchars(
                Translator::t('public.password_reset.back_login', 'Повернутися до входу')
            ) ?>
        </a>
    </section>
</main>

<script src="/Anabelka/js/public-ui-focus-policy.js?v=2"></script>
</body>
</html>
