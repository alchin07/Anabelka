<?php
PublicInterfaceTranslator::seed();
$currentLanguage = Translator::currentLanguage();
$pageTitle = Translator::t('public.order.order', 'Замовлення');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLanguage['code'] ?? 'uk') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(
        Translator::t('public.order.error_title', 'Помилка замовлення')
    ) ?> — Анабелька</title>
    <link rel="stylesheet" href="/Anabelka/css/style.css?v=8">
    <link rel="stylesheet" href="/Anabelka/css/catalog.css?v=4">
</head>
<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="catalog">
    <section
        class="product-card"
        style="
            max-width: 600px;
            margin: 0 auto;
            padding: 30px 25px;
            text-align: center;
        "
    >
        <div
            style="
                width: 58px;
                height: 58px;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 18px;
                border-radius: 50%;
                background: var(--primary-light-color);
                color: var(--primary-color);
                font-size: 30px;
                font-weight: bold;
            "
        >!</div>

        <h2 style="margin-bottom: 12px;">
            <?= htmlspecialchars(
                Translator::t('public.order.not_found', 'Замовлення не знайдено')
            ) ?>
        </h2>

        <p style="margin-bottom: 25px; line-height: 1.5;">
            <?= htmlspecialchars(
                Translator::t(
                    'public.order.not_found_text',
                    'Можливо, посилання застаріло або було змінено.'
                )
            ) ?>
        </p>

        <a
            href="/Anabelka/catalog"
            style="
                display: inline-block;
                padding: 12px 22px;
                border-radius: 12px;
                background: var(--primary-color);
                color: #fff;
                text-decoration: none;
                font-weight: bold;
            "
        >
            <?= htmlspecialchars(
                Translator::t('public.order.back_catalog', 'Повернутися до каталогу')
            ) ?>
        </a>
    </section>
</main>

</body>
</html>
