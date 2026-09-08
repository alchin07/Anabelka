<?php
PublicInterfaceTranslator::seed();
CustomerAccountInterfaceTranslator::seed();
$currentLanguage = Translator::currentLanguage();
$pageTitle = Translator::t('public.account.title', 'Мій акаунт');
$user = is_array($user ?? null) ? $user : [];
$csrfToken = (string) ($csrfToken ?? '');
$message = trim((string) ($message ?? ''));
$error = trim((string) ($error ?? ''));
$rankName = UserRankTranslator::localizeName(
    (int) ($user['rank_id'] ?? 0),
    (string) ($user['rank_name'] ?? ''),
    (string) ($currentLanguage['code'] ?? Language::SOURCE_CODE)
);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLanguage['code'] ?? 'uk') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> — Анабелька</title>
    <link rel="stylesheet" href="/Anabelka/css/style.css?v=8">
    <link rel="stylesheet" href="/Anabelka/css/catalog.css?v=4">
    <link rel="stylesheet" href="/Anabelka/css/account.css?v=2">
</head>
<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="account-page">
    <section class="account-hero">
        <div>
            <span class="account-kicker">Анабелька</span>
            <h2><?= htmlspecialchars(
                Translator::t('public.account.heading', 'Мій акаунт')
            ) ?></h2>
            <p><?= htmlspecialchars((string) ($user['name'] ?? '')) ?></p>
        </div>

        <div class="account-hero-actions">
            <span class="account-rank">
                <?= htmlspecialchars(
                    Translator::t('public.account.rank', 'Мій ранг')
                ) ?>: <strong><?= htmlspecialchars($rankName) ?></strong>
            </span>

            <form method="post" action="/Anabelka/logout">
                <input
                    type="hidden"
                    name="_csrf"
                    value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"
                >
                <button class="account-logout" type="submit">
                    <?= htmlspecialchars(
                        Translator::t('header.logout', 'Вийти')
                    ) ?>
                </button>
            </form>
        </div>
    </section>

    <?php if ($message !== ''): ?>
        <div class="account-message is-success" role="status">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="account-message is-error" role="alert">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <section class="account-links" aria-label="Account shortcuts">
        <a href="/Anabelka/orders">
            <strong><?= htmlspecialchars(
                Translator::t('public.account.orders', 'Мої замовлення')
            ) ?></strong>
            <span>→</span>
        </a>
        <a href="/Anabelka/favorites">
            <strong><?= htmlspecialchars(
                Translator::t('public.account.favorites', 'Обране')
            ) ?></strong>
            <span>→</span>
        </a>
    </section>

    <section class="account-grid">
        <article class="account-card">
            <div class="account-card-head">
                <h3><?= htmlspecialchars(
                    Translator::t('public.account.profile', 'Профіль')
                ) ?></h3>
                <p><?= htmlspecialchars(
                    Translator::t(
                        'public.account.edit_profile',
                        'Змінити ім’я або email'
                    )
                ) ?></p>
            </div>

            <form method="post" action="/Anabelka/account/profile" autocomplete="off">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                <label>
                    <span><?= htmlspecialchars(
                        Translator::t('public.auth.name', 'Ім’я')
                    ) ?></span>
                    <input
                        type="text"
                        name="name"
                        maxlength="120"
                        value="<?= htmlspecialchars((string) ($user['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        required
                    >
                </label>

                <label>
                    <span>Email</span>
                    <input
                        type="email"
                        name="email"
                        maxlength="190"
                        value="<?= htmlspecialchars((string) ($user['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        required
                    >
                </label>

                <label>
                    <span><?= htmlspecialchars(
                        Translator::t(
                            'public.account.current_password',
                            'Поточний пароль'
                        )
                    ) ?></span>
                    <input
                        type="password"
                        name="current_password"
                        autocomplete="current-password"
                        required
                    >
                </label>

                <button type="submit">
                    <?= htmlspecialchars(
                        Translator::t(
                            'public.account.save_profile',
                            'Зберегти дані'
                        )
                    ) ?>
                </button>
            </form>
        </article>

        <article class="account-card">
            <div class="account-card-head">
                <h3><?= htmlspecialchars(
                    Translator::t(
                        'public.account.change_password',
                        'Змінити пароль'
                    )
                ) ?></h3>
                <p><?= htmlspecialchars(
                    Translator::t(
                        'public.account.password_hint',
                        'Щонайменше 8 символів'
                    )
                ) ?></p>
            </div>

            <form method="post" action="/Anabelka/account/password" autocomplete="off">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                <label>
                    <span><?= htmlspecialchars(
                        Translator::t(
                            'public.account.current_password',
                            'Поточний пароль'
                        )
                    ) ?></span>
                    <input
                        type="password"
                        name="current_password"
                        autocomplete="current-password"
                        required
                    >
                </label>

                <label>
                    <span><?= htmlspecialchars(
                        Translator::t(
                            'public.account.new_password',
                            'Новий пароль'
                        )
                    ) ?></span>
                    <input
                        type="password"
                        name="new_password"
                        minlength="8"
                        autocomplete="new-password"
                        required
                    >
                </label>

                <label>
                    <span><?= htmlspecialchars(
                        Translator::t(
                            'public.account.confirm_password',
                            'Повторіть новий пароль'
                        )
                    ) ?></span>
                    <input
                        type="password"
                        name="new_password_confirmation"
                        minlength="8"
                        autocomplete="new-password"
                        required
                    >
                </label>

                <button type="submit">
                    <?= htmlspecialchars(
                        Translator::t(
                            'public.account.change_password',
                            'Змінити пароль'
                        )
                    ) ?>
                </button>
            </form>
        </article>
    </section>
</main>

<script src="/Anabelka/js/public-ui-focus-policy.js?v=1"></script>

</body>
</html>
