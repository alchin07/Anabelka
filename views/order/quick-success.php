<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Быстрый заказ принят — Анабелька</title>

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

<?php
$pageTitle = 'Быстрый заказ';
require __DIR__ . '/../partials/header.php';
?>

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
        <h2>Заказ принят</h2>

        <p style="margin: 0 0 12px;">
            Спасибо, <?= htmlspecialchars(
                $order['customer_name'] ?? ''
            ) ?>.
        </p>

        <p style="margin: 0 0 20px;">
            Мы свяжемся с вами по номеру
            <strong><?= htmlspecialchars(
                $order['customer_phone'] ?? ''
            ) ?></strong>
            для уточнения деталей заказа и доставки.
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
            Продолжить покупки
        </a>
    </section>

</main>

</body>
</html>
