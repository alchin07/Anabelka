<?php

$currentLanguage = Translator::currentLanguage();
$pageTitle = Translator::t('quick.title', 'Швидке замовлення');

?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLanguage['code'] ?? 'uk') ?>">

<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title><?= htmlspecialchars(
        Translator::t(
            'quick.success_page_title',
            'Швидке замовлення прийнято'
        )
    ) ?> — Анабелька</title>

    <link
        rel="stylesheet"
        href="/Anabelka/css/style.css?v=8"
    >

    <link
        rel="stylesheet"
        href="/Anabelka/css/catalog.css?v=4"
    >
</head>

<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="catalog">

    <section
        class="product-card"
        style="
            max-width: 600px;
            margin: 0 auto;
            padding: 22px;
            text-align: center;
        "
    >
        <h2>
            <?= htmlspecialchars(
                Translator::t(
                    'quick.accepted',
                    'Замовлення прийнято'
                )
            ) ?>
        </h2>

        <p style="margin: 0 0 12px;">
            <?= htmlspecialchars(
                Translator::t(
                    'quick.thanks',
                    'Дякуємо'
                )
            ) ?>,
            <?= htmlspecialchars(
                $order['customer_name'] ?? ''
            ) ?>.
        </p>

        <p style="margin: 0 0 20px;">
            <?= htmlspecialchars(
                Translator::t(
                    'quick.contact_before',
                    'Ми зв’яжемося з вами за номером'
                )
            ) ?>
            <strong><?= htmlspecialchars(
                $order['customer_phone'] ?? ''
            ) ?></strong>
            <?= htmlspecialchars(
                Translator::t(
                    'quick.contact_after',
                    'для уточнення деталей замовлення та доставки.'
                )
            ) ?>
        </p>

        <a
            href="/Anabelka/catalog"
            style="
                display: block;
                width: 100%;
                box-sizing: border-box;
                padding: 14px;
                border-radius: 12px;
                background: var(--primary-color);
                color: #fff;
                text-align: center;
                text-decoration: none;
                font-size: 16px;
                font-weight: bold;
            "
        >
            <?= htmlspecialchars(
                Translator::t(
                    'quick.continue_shopping',
                    'Продовжити покупки'
                )
            ) ?>
        </a>
    </section>

</main>

</body>
</html>
