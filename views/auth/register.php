<?php
PublicInterfaceTranslator::seed();
CustomerAccountInterfaceTranslator::seed();
RegistrationInterfaceTranslator::seed();
PasswordPolicyInterfaceTranslator::seed();
$currentLanguage = Translator::currentLanguage();
$pageTitle = Translator::t('public.auth.register_title', 'Реєстрація');
$error = trim((string) ($error ?? ''));
$name = trim((string) ($name ?? ''));
$email = trim((string) ($email ?? ''));
$termsAccepted = !empty($termsAccepted);
$marketingConsent = !empty($marketingConsent);
$csrfToken = (string) ($csrfToken ?? '');
$passwordMinLength = PasswordPolicy::minimumLength();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLanguage['code'] ?? 'uk') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> — Анабелька</title>
    <link rel="stylesheet" href="/Anabelka/css/style.css?v=8">
    <link rel="stylesheet" href="/Anabelka/css/catalog.css?v=4">
    <link rel="stylesheet" href="/Anabelka/css/auth-register.css?v=2">
    <style>
        .auth-page{min-height:100dvh;padding-bottom:45vh;scroll-padding-bottom:45vh}
        .auth-card{max-width:500px;margin:0 auto;padding:25px;background:#fff;border:1px solid var(--border-color);border-radius:16px}
        .auth-card h2{margin-bottom:20px}.auth-field{display:grid;gap:6px;margin-bottom:15px}.auth-field>span{font-weight:700}.auth-field>input{width:100%;box-sizing:border-box;padding:12px;border:1px solid var(--border-color);border-radius:10px;font:inherit}.auth-field small{color:#7a7180;font-size:12px}.auth-error{margin:0 0 16px;padding:12px 14px;border:1px solid #e7b9c1;border-radius:12px;background:#fff0f2;color:#8e3748;font-weight:700;line-height:1.4}.auth-submit{width:100%;padding:14px;border:0;border-radius:12px;background:var(--primary-color);color:#fff;font-size:16px;font-weight:bold;cursor:pointer}.auth-footer{margin-top:20px;text-align:center}.auth-footer a{color:var(--primary-color);font-weight:bold}@media(min-width:901px){.auth-page{padding-bottom:40px;scroll-padding-bottom:40px}}
    </style>
</head>
<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="catalog auth-page">
    <section class="auth-card">
        <h2><?= htmlspecialchars(
            Translator::t('public.auth.register_heading', 'Створити акаунт')
        ) ?></h2>

        <?php if ($error !== ''): ?>
            <div class="auth-error" role="alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form
            action="/Anabelka/register"
            method="POST"
            autocomplete="on"
            data-register-form
            data-show-password="<?= htmlspecialchars(Translator::t('public.registration.show_password', 'Показати пароль'), ENT_QUOTES, 'UTF-8') ?>"
            data-hide-password="<?= htmlspecialchars(Translator::t('public.registration.hide_password', 'Сховати пароль'), ENT_QUOTES, 'UTF-8') ?>"
            data-passwords-match="<?= htmlspecialchars(Translator::t('public.registration.passwords_match', 'Паролі збігаються.'), ENT_QUOTES, 'UTF-8') ?>"
            data-passwords-mismatch="<?= htmlspecialchars(Translator::t('public.registration.passwords_mismatch', 'Паролі не збігаються.'), ENT_QUOTES, 'UTF-8') ?>"
        >
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

            <label class="auth-field">
                <span><?= htmlspecialchars(Translator::t('public.auth.name', 'Ім’я')) ?></span>
                <input type="text" name="name" maxlength="120" autocomplete="name" value="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>" required>
            </label>

            <label class="auth-field">
                <span>Email</span>
                <input type="email" name="email" maxlength="190" autocomplete="email" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" required>
            </label>

            <label class="auth-field">
                <span><?= htmlspecialchars(Translator::t('public.auth.password', 'Пароль')) ?></span>
                <span class="auth-password-wrap">
                    <input type="password" name="password" minlength="<?= $passwordMinLength ?>" autocomplete="new-password" required>
                    <button
                        type="button"
                        class="auth-password-toggle"
                        data-password-toggle="password"
                        aria-label="<?= htmlspecialchars(Translator::t('public.registration.show_password', 'Показати пароль'), ENT_QUOTES, 'UTF-8') ?>"
                        title="<?= htmlspecialchars(Translator::t('public.registration.show_password', 'Показати пароль'), ENT_QUOTES, 'UTF-8') ?>"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/>
                            <circle cx="12" cy="12" r="2.5"/>
                        </svg>
                    </button>
                </span>
                <small><?= htmlspecialchars(Translator::t('public.password_policy.hint', 'Щонайменше 10 символів. Не використовуйте прості паролі або послідовності.')) ?></small>
            </label>

            <label class="auth-field">
                <span><?= htmlspecialchars(Translator::t('public.auth.confirm_password', 'Повторіть пароль')) ?></span>
                <span class="auth-password-wrap">
                    <input type="password" name="password_confirmation" minlength="<?= $passwordMinLength ?>" autocomplete="new-password" required>
                    <button
                        type="button"
                        class="auth-password-toggle"
                        data-password-toggle="password_confirmation"
                        aria-label="<?= htmlspecialchars(Translator::t('public.registration.show_password', 'Показати пароль'), ENT_QUOTES, 'UTF-8') ?>"
                        title="<?= htmlspecialchars(Translator::t('public.registration.show_password', 'Показати пароль'), ENT_QUOTES, 'UTF-8') ?>"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/>
                            <circle cx="12" cy="12" r="2.5"/>
                        </svg>
                    </button>
                </span>
            </label>

            <div class="auth-password-match" data-password-match aria-live="polite"></div>

            <div class="auth-consents">
                <label class="auth-check">
                    <input
                        type="checkbox"
                        name="accept_terms"
                        value="1"
                        <?= $termsAccepted ? 'checked' : '' ?>
                        required
                    >
                    <span class="auth-check-copy">
                        <?= htmlspecialchars(Translator::t('public.registration.agree_prefix', 'Я погоджуюся з')) ?>
                        <a href="/Anabelka/terms" target="_blank" rel="noopener">
                            <?= htmlspecialchars(Translator::t('public.registration.terms', 'Умовами користування')) ?>
                        </a>
                        <?= htmlspecialchars(Translator::t('public.registration.and', 'та')) ?>
                        <a href="/Anabelka/privacy" target="_blank" rel="noopener">
                            <?= htmlspecialchars(Translator::t('public.registration.privacy', 'Політикою конфіденційності')) ?>
                        </a>.
                    </span>
                </label>

                <label class="auth-check">
                    <input
                        type="checkbox"
                        name="marketing_consent"
                        value="1"
                        <?= $marketingConsent ? 'checked' : '' ?>
                    >
                    <span class="auth-check-copy">
                        <?= htmlspecialchars(Translator::t('public.registration.marketing', 'Хочу отримувати новини та персональні пропозиції від Анабельки.')) ?>
                        <small><?= htmlspecialchars(Translator::t('public.registration.marketing_optional', 'Необов’язково. Цю згоду можна буде відкликати.')) ?></small>
                    </span>
                </label>
            </div>

            <button class="auth-submit" type="submit">
                <?= htmlspecialchars(Translator::t('public.auth.register_button', 'Зареєструватися')) ?>
            </button>
        </form>

        <p class="auth-footer">
            <?= htmlspecialchars(Translator::t('public.auth.have_account', 'Вже є акаунт?')) ?>
            <a href="/Anabelka/login"><?= htmlspecialchars(Translator::t('public.auth.login_button', 'Увійти')) ?></a>
        </p>
    </section>
</main>

<script src="/Anabelka/js/public-ui-focus-policy.js?v=1"></script>
<script src="/Anabelka/js/register-form.js?v=1"></script>
</body>
</html>
