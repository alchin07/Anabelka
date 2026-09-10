<?php
PasswordResetInterfaceTranslator::seed();
PasswordPolicyInterfaceTranslator::seed();
$currentLanguage = Translator::currentLanguage();
$pageTitle = Translator::t('public.password_reset.reset_title', 'Новий пароль');
$error = trim((string) ($error ?? ''));
$token = trim((string) ($token ?? ''));
$valid = !empty($valid);
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
    <link rel="stylesheet" href="/Anabelka/css/auth-password-reset.css?v=1">
</head>
<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="catalog password-reset-page">
    <section class="password-reset-card">
        <h2><?= htmlspecialchars(
            Translator::t('public.password_reset.reset_heading', 'Створіть новий пароль')
        ) ?></h2>
        <p><?= htmlspecialchars(
            Translator::t(
                'public.password_reset.reset_hint',
                'Посилання діє одну годину та може бути використане лише один раз.'
            )
        ) ?></p>

        <?php if ($error !== ''): ?>
            <div class="password-reset-message is-error" role="alert">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($valid): ?>
            <form
                class="password-reset-form"
                method="post"
                action="/Anabelka/reset-password"
                data-password-reset-form
                data-passwords-match="<?= htmlspecialchars(Translator::t('public.registration.passwords_match', 'Паролі збігаються.'), ENT_QUOTES, 'UTF-8') ?>"
                data-passwords-mismatch="<?= htmlspecialchars(Translator::t('public.password_reset.password_mismatch', 'Паролі не збігаються.'), ENT_QUOTES, 'UTF-8') ?>"
            >
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">

                <label class="password-reset-field">
                    <span><?= htmlspecialchars(
                        Translator::t('public.password_reset.new_password', 'Новий пароль')
                    ) ?></span>
                    <span class="password-reset-password-wrap">
                        <input
                            type="password"
                            name="password"
                            minlength="<?= $passwordMinLength ?>"
                            autocomplete="new-password"
                            required
                        >
                        <button
                            class="password-reset-toggle"
                            type="button"
                            data-password-toggle="password"
                            aria-label="Показати пароль"
                            title="Показати пароль"
                        >
                            <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/>
                                <circle cx="12" cy="12" r="2.5"/>
                            </svg>
                        </button>
                    </span>
                    <small><?= htmlspecialchars(
                        Translator::t(
                            'public.password_policy.hint',
                            'Щонайменше 10 символів. Не використовуйте прості паролі або послідовності.'
                        )
                    ) ?></small>
                </label>

                <label class="password-reset-field">
                    <span><?= htmlspecialchars(
                        Translator::t('public.password_reset.confirm_password', 'Повторіть новий пароль')
                    ) ?></span>
                    <span class="password-reset-password-wrap">
                        <input
                            type="password"
                            name="password_confirmation"
                            minlength="<?= $passwordMinLength ?>"
                            autocomplete="new-password"
                            required
                        >
                        <button
                            class="password-reset-toggle"
                            type="button"
                            data-password-toggle="password_confirmation"
                            aria-label="Показати пароль"
                            title="Показати пароль"
                        >
                            <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/>
                                <circle cx="12" cy="12" r="2.5"/>
                            </svg>
                        </button>
                    </span>
                </label>

                <div class="password-reset-match" data-password-match aria-live="polite"></div>

                <button class="password-reset-submit" type="submit">
                    <?= htmlspecialchars(
                        Translator::t('public.password_reset.reset_button', 'Змінити пароль')
                    ) ?>
                </button>
            </form>
        <?php endif; ?>

        <a class="password-reset-back" href="/Anabelka/login">
            ← <?= htmlspecialchars(
                Translator::t('public.password_reset.back_login', 'Повернутися до входу')
            ) ?>
        </a>
    </section>
</main>

<script src="/Anabelka/js/public-ui-focus-policy.js?v=2"></script>
<script src="/Anabelka/js/password-reset-form.js?v=1"></script>
</body>
</html>
