<?php
PublicInterfaceTranslator::seed();
RegistrationInterfaceTranslator::seed();
SocialAuthInterfaceTranslator::seed();
$currentLanguage = Translator::currentLanguage();
$pageTitle = Translator::t(
    'public.social_auth.complete_title',
    'Завершення реєстрації'
);
$pending = is_array($pending ?? null) ? $pending : [];
$providerCode = strtolower(trim((string) ($pending['provider'] ?? 'google')));
$providerLabel = class_exists('SocialAuthProvider')
    ? SocialAuthProvider::label($providerCode)
    : ucfirst($providerCode);
$completeHeading = sprintf(
    Translator::t(
        'public.social_auth.complete_heading_provider',
        'Завершіть реєстрацію через %s'
    ),
    $providerLabel
);
$completeHint = sprintf(
    Translator::t(
        'public.social_auth.complete_hint_provider',
        '%s підтвердив ваш email. Для створення нового акаунта Анабельки потрібно прийняти умови.'
    ),
    $providerLabel
);
$error = trim((string) ($error ?? ''));
$termsAccepted = !empty($termsAccepted);
$marketingConsent = !empty($marketingConsent);
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
    <link rel="stylesheet" href="/Anabelka/css/social-auth.css?v=2">
</head>
<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="catalog auth-page">
    <section class="social-complete-card">
        <h2><?= htmlspecialchars($completeHeading) ?></h2>
        <p><?= htmlspecialchars($completeHint) ?></p>

        <div class="social-complete-identity">
            <strong><?= htmlspecialchars((string) ($pending['name'] ?? '')) ?></strong>
            <span><?= htmlspecialchars((string) ($pending['email'] ?? '')) ?></span>
        </div>

        <?php if ($error !== ''): ?>
            <div class="social-complete-error" role="alert">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form
            class="social-complete-form"
            method="post"
            action="/Anabelka/auth/social/complete"
        >
            <input
                type="hidden"
                name="_csrf"
                value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"
            >

            <label class="social-complete-check">
                <input
                    type="checkbox"
                    name="accept_terms"
                    value="1"
                    <?= $termsAccepted ? 'checked' : '' ?>
                    required
                >
                <span>
                    <?= htmlspecialchars(
                        Translator::t('public.registration.agree_prefix', 'Я погоджуюся з')
                    ) ?>
                    <a href="/Anabelka/terms" target="_blank" rel="noopener">
                        <?= htmlspecialchars(
                            Translator::t('public.registration.terms', 'Умовами користування')
                        ) ?>
                    </a>
                    <?= htmlspecialchars(
                        Translator::t('public.registration.and', 'та')
                    ) ?>
                    <a href="/Anabelka/privacy" target="_blank" rel="noopener">
                        <?= htmlspecialchars(
                            Translator::t('public.registration.privacy', 'Політикою конфіденційності')
                        ) ?>
                    </a>.
                </span>
            </label>

            <label class="social-complete-check">
                <input
                    type="checkbox"
                    name="marketing_consent"
                    value="1"
                    <?= $marketingConsent ? 'checked' : '' ?>
                >
                <span>
                    <?= htmlspecialchars(
                        Translator::t(
                            'public.registration.marketing',
                            'Хочу отримувати новини та персональні пропозиції від Анабельки.'
                        )
                    ) ?>
                    <small><?= htmlspecialchars(
                        Translator::t(
                            'public.registration.marketing_optional',
                            'Необов’язково. Цю згоду можна буде відкликати.'
                        )
                    ) ?></small>
                </span>
            </label>

            <button class="social-complete-submit" type="submit">
                <?= htmlspecialchars(
                    Translator::t(
                        'public.social_auth.continue',
                        'Створити акаунт і продовжити'
                    )
                ) ?>
            </button>

            <a class="social-complete-cancel" href="/Anabelka/login">
                <?= htmlspecialchars(
                    Translator::t('public.auth.login_button', 'Увійти')
                ) ?>
            </a>
        </form>
    </section>
</main>

<script src="/Anabelka/js/public-ui-focus-policy.js?v=2"></script>
</body>
</html>
