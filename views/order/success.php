<?php
PublicInterfaceTranslator::seed();
$currentLanguage = Translator::currentLanguage();
$pageTitle = Translator::t('public.order.success_title', 'Замовлення оформлено');
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
        class="product-card"
        style="
            max-width: 600px;
            margin: 0 auto;
            padding: 25px;
            text-align: center;
        "
    >
        <div
            style="
                font-size: 42px;
                margin-bottom: 15px;
                color: var(--primary-color);
            "
        >✓</div>

        <h2><?= htmlspecialchars(
            Translator::t('public.order.thanks', 'Дякуємо за замовлення!')
        ) ?></h2>

        <p style="margin-top: 15px; font-size: 16px;">
            <?= htmlspecialchars(
                Translator::t(
                    'public.order.success_text',
                    'Ваше замовлення успішно оформлено.'
                )
            ) ?>
        </p>

        <?php if (!empty($order)): ?>
            <p style="margin-top: 10px; font-weight: bold;">
                <?= htmlspecialchars(
                    Translator::t('public.order.number', 'Номер замовлення')
                ) ?>:
                №<?= (int) $order['id'] ?>
            </p>

            <p
                style="
                    margin-top: 8px;
                    font-size: 18px;
                    color: var(--primary-color);
                    font-weight: 700;
                "
            >
                <?= htmlspecialchars(
                    Translator::t('public.order.sum', 'Сума')
                ) ?>:
                <?= number_format(
                    (float) $order['total'],
                    2,
                    ',',
                    ' '
                ) ?> €
            </p>
        <?php endif; ?>

        <a
            href="/Anabelka/catalog"
            style="
                display: inline-block;
                margin-top: 25px;
                padding: 12px 20px;
                border-radius: 12px;
                background: var(--primary-color);
                color: #fff;
                text-decoration: none;
                font-weight: bold;
            "
        >
            <?= htmlspecialchars(
                Translator::t('public.order.continue', 'Продовжити покупки')
            ) ?>
        </a>
    </section>
</main>

</body>
</html>
