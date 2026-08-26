<!DOCTYPE html>
<html lang="ru">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Заказ оформлен — Анабелька
    </title>

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

    $pageTitle =
        'Заказ оформлен';

    require __DIR__
        . '/../partials/header.php';

    ?>


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
            >
                ✓
            </div>


            <h2>
                Спасибо за заказ!
            </h2>


            <p
                style="
                    margin-top: 15px;
                    font-size: 16px;
                "
            >
                Ваш заказ успешно оформлен.
            </p>


            <?php if (!empty($order)): ?>

    <p
        style="
            margin-top: 10px;
            font-weight: bold;
        "
    >
        Номер заказа:
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
        Сумма:
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
                Продолжить покупки
            </a>

        </section>

    </main>

</body>

</html>