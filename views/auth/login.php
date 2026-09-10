<?php
PublicInterfaceTranslator::seed();
CustomerAccountInterfaceTranslator::seed();
CustomerEmailVerificationInterfaceTranslator::seed();
PasswordResetInterfaceTranslator::seed();
SocialAuthInterfaceTranslator::seed();
$currentLanguage = Translator::currentLanguage();
$pageTitle = Translator::t('public.auth.login_title', 'Вхід');
$message = trim((string) ($message ?? ''));
$error = trim((string) ($error ?? ''));
$email = trim((string) ($email ?? ''));
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
    <link rel="stylesheet" href="/Anabelka/css/social-auth.css?v=1">
    <style>
        .auth-page{min-height:100dvh;padding-bottom:45vh;scroll-padding-bottom:45vh}.auth-card{max-width:500px;margin:0 auto;padding:25px;background:#fff;border:1px solid var(--border-color);border-radius:16px}.auth-card h2{margin-bottom:20px}.auth-field{display:grid;gap:6px;margin-bottom:15px}.auth-field span{font-weight:700}.auth-field input{width:100%;box-sizing:border-box;padding:12px;border:1px solid var(--border-color);border-radius:10px;font:inherit}.auth-error,.auth-success{margin:0 0 16px;padding:12px 14px;border-radius:12px;font-weight:700;line-height:1.4}.auth-error{border:1px solid #e7b9c1;background:#fff0f2;color:#8e3748}.auth-success{border:1px solid #b9ddc4;background:#f2fbf5;color:#27623a}.auth-submit{width:100%;padding:14px;border:0;border-radius:12px;background:var(--primary-color);color:#fff;font-size:16px;font-weight:bold;cursor:pointer}.auth-forgot{display:block;width:max-content;max-width:100%;margin:12px 0 0 auto;color:var(--primary-color);font-size:14px;font-weight:800;text-decoration:none}.auth-forgot:hover,.auth-forgot:focus-visible{text-decoration:underline}.auth-footer{margin-top:20px;text-align:center}.auth-footer a{color:var(--primary-color);font-weight:bold}@media(min-width:901px){.auth-page{padding-bottom:40px;scroll-padding-bottom:40px}}
    </style>
</head>
<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="catalog auth-page">
    <section class="auth-card">
        <h2><?= htmlspecialchars(Translator::t('public.auth.login_heading', 'Увійти до акаунта')) ?></h2>

        <?php if ($message !== ''): ?>
            <div class="auth-success" role="status"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="auth-error" role="alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form action="/Anabelka/login" method="POST">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

            <label class="auth-field">
                <span>Email</span>
                <input type="email" name="email" maxlength="190" autocomplete="email" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" required>
            </label>

            <label class="auth-field" style="margin-bottom:20px">
                <span><?= htmlspecialchars(Translator::t('public.auth.password', 'Пароль')) ?></span>
                <input type="password" name="password" autocomplete="current-password" required>
            </label>

            <button class="auth-submit" type="submit">
                <?= htmlspecialchars(Translator::t('public.auth.login_button', 'Увійти')) ?>
            </button>

            <a class="auth-forgot" href="/Anabelka/forgot-password">
                <?= htmlspecialchars(
                    Translator::t('public.password_reset.forgot_link', 'Забули пароль?')
                ) ?>
            </a>
        </form>

        <?php require __DIR__ . '/partials/social-auth.php'; ?>

        <p class="auth-footer">
            <?= htmlspecialchars(Translator::t('public.auth.no_account', 'Немає акаунта?')) ?>
            <a href="/Anabelka/register"><?= htmlspecialchars(Translator::t('public.auth.register_link', 'Зареєструватися')) ?></a>
        </p>
    </section>
</main>

<script src="/Anabelka/js/public-ui-focus-policy.js?v=2"></script>
</body>
</html>
