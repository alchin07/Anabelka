<?php
PublicInterfaceTranslator::seed();
$currentLanguage = Translator::currentLanguage();
$pageTitle = Translator::t('public.auth.login_title', 'Вхід');
$error = trim((string) ($error ?? ''));
$email = trim((string) ($email ?? ''));
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLanguage['code'] ?? 'uk') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> — Анабелька</title>
    <link rel="stylesheet" href="/Anabelka/css/style.css?v=8">
    <link rel="stylesheet" href="/Anabelka/css/catalog.css?v=4">
    <style>
        .auth-page {
            min-height: 100dvh;
            padding-bottom: 45vh;
            scroll-padding-bottom: 45vh;
        }

        .auth-error {
            margin: 0 0 16px;
            padding: 12px 14px;
            border: 1px solid #e7b9c1;
            border-radius: 12px;
            background: #fff0f2;
            color: #8e3748;
            font-weight: 700;
            line-height: 1.4;
        }

        @media (min-width: 901px) {
            .auth-page {
                padding-bottom: 40px;
                scroll-padding-bottom: 40px;
            }
        }
    </style>
</head>
<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="catalog auth-page">
    <section
        style="
            max-width: 500px;
            margin: 0 auto;
            padding: 25px;
            background: #fff;
            border: 1px solid var(--border-color);
            border-radius: 16px;
        "
    >
        <h2 style="margin-bottom: 20px;">
            <?= htmlspecialchars(
                Translator::t('public.auth.login_heading', 'Увійти до акаунта')
            ) ?>
        </h2>

        <?php if ($error !== ''): ?>
            <div class="auth-error" role="alert">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form action="/Anabelka/login" method="POST">
            <div style="margin-bottom: 15px;">
                <label>Email</label>
                <input
                    type="email"
                    name="email"
                    value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"
                    required
                    style="
                        width: 100%;
                        padding: 12px;
                        margin-top: 6px;
                        border: 1px solid var(--border-color);
                        border-radius: 10px;
                    "
                >
            </div>

            <div style="margin-bottom: 20px;">
                <label><?= htmlspecialchars(
                    Translator::t('public.auth.password', 'Пароль')
                ) ?></label>
                <input
                    type="password"
                    name="password"
                    required
                    style="
                        width: 100%;
                        padding: 12px;
                        margin-top: 6px;
                        border: 1px solid var(--border-color);
                        border-radius: 10px;
                    "
                >
            </div>

            <button
                type="submit"
                style="
                    width: 100%;
                    padding: 14px;
                    border: 0;
                    border-radius: 12px;
                    background: var(--primary-color);
                    color: #fff;
                    font-size: 16px;
                    font-weight: bold;
                    cursor: pointer;
                "
            >
                <?= htmlspecialchars(
                    Translator::t('public.auth.login_button', 'Увійти')
                ) ?>
            </button>
        </form>

        <p style="margin-top: 20px; text-align: center;">
            <?= htmlspecialchars(
                Translator::t('public.auth.no_account', 'Немає акаунта?')
            ) ?>

            <a
                href="/Anabelka/register"
                style="color: var(--primary-color); font-weight: bold;"
            >
                <?= htmlspecialchars(
                    Translator::t('public.auth.register_link', 'Зареєструватися')
                ) ?>
            </a>
        </p>
    </section>
</main>

<script src="/Anabelka/js/public-ui-focus-policy.js?v=1"></script>

</body>
</html>
