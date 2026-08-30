<?php
PublicInterfaceTranslator::seed();
$currentLanguage = Translator::currentLanguage();
$pageTitle = Translator::t('public.auth.register_title', 'Реєстрація');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLanguage['code'] ?? 'uk') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> — Анабелька</title>
    <link rel="stylesheet" href="/Anabelka/css/style.css?v=8">
    <link rel="stylesheet" href="/Anabelka/css/catalog.css?v=4">
</head>
<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="catalog">
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
                Translator::t('public.auth.register_heading', 'Створити акаунт')
            ) ?>
        </h2>

        <form action="/Anabelka/register" method="POST">
            <div style="margin-bottom: 15px;">
                <label><?= htmlspecialchars(
                    Translator::t('public.auth.name', 'Ім’я')
                ) ?></label>
                <input
                    type="text"
                    name="name"
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

            <div style="margin-bottom: 15px;">
                <label>Email</label>
                <input
                    type="email"
                    name="email"
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
                    minlength="6"
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
                    Translator::t('public.auth.register_button', 'Зареєструватися')
                ) ?>
            </button>
        </form>

        <p style="margin-top: 20px; text-align: center;">
            <?= htmlspecialchars(
                Translator::t('public.auth.have_account', 'Вже є акаунт?')
            ) ?>

            <a
                href="/Anabelka/login"
                style="color: var(--primary-color); font-weight: bold;"
            >
                <?= htmlspecialchars(
                    Translator::t('public.auth.login_button', 'Увійти')
                ) ?>
            </a>
        </p>
    </section>
</main>

</body>
</html>
