<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Быстрые заказы — Админ-панель</title>

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
$pageTitle = 'Админ-панель — Быстрые заказы';
require __DIR__ . '/../../partials/header.php';
?>

<main class="catalog">

    <section
        class="product-card"
        style="
            max-width: 900px;
            margin: 0 auto;
            padding: 20px;
        "
    >
        <div
            style="
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 12px;
                flex-wrap: wrap;
                margin-bottom: 20px;
            "
        >
            <div>
                <h2 style="margin-bottom: 4px;">
                    Быстрые заказы
                </h2>

                <p style="margin: 0; opacity: 0.7;">
                    Имя, телефон, комментарий
                    и товары из корзины
                </p>
            </div>

            <a
                href="/Anabelka/admin/delivery"
                style="
                    color: var(--primary-color);
                    font-weight: bold;
                    text-decoration: none;
                "
            >
                Доставка
            </a>
        </div>

        <?php if (empty($orders)): ?>

            <p>Быстрых заказов пока нет.</p>

        <?php else: ?>

            <?php foreach ($orders as $order): ?>

                <article
                    style="
                        margin-bottom: 16px;
                        padding: 16px;
                        border: 1px solid var(--border-color);
                        border-radius: 14px;
                        background: var(--surface-color);
                    "
                >
                    <div
                        style="
                            display: flex;
                            justify-content: space-between;
                            gap: 12px;
                            flex-wrap: wrap;
                            margin-bottom: 12px;
                        "
                    >
                        <strong>
                            Быстрый заказ #<?= (int) $order['id'] ?>
                        </strong>

                        <span style="opacity: 0.7;">
                            <?= htmlspecialchars(
                                $order['created_at'] ?? ''
                            ) ?>
                        </span>
                    </div>

                    <p style="margin: 6px 0;">
                        <strong>Имя:</strong>
                        <?= htmlspecialchars(
                            $order['customer_name'] ?? ''
                        ) ?>
                    </p>

                    <p style="margin: 6px 0;">
                        <strong>Телефон:</strong>
                        <a
                            href="tel:<?= htmlspecialchars(
                                $order['customer_phone'] ?? ''
                            ) ?>"
                            style="color: var(--primary-color);"
                        >
                            <?= htmlspecialchars(
                                $order['customer_phone'] ?? ''
                            ) ?>
                        </a>
                    </p>

                    <p style="margin: 6px 0 12px;">
                        <strong>Комментарий:</strong>
                        <?= htmlspecialchars(
                            $order['comment'] ?? '—'
                        ) ?>
                    </p>

                    <?php if (!empty($order['items'])): ?>

                        <div
                            style="
                                padding: 12px;
                                border-radius: 10px;
                                background: var(--primary-light-color);
                            "
                        >
                            <?php foreach ($order['items'] as $item): ?>

                                <div style="margin-bottom: 8px;">
                                    <strong>
                                        <?= htmlspecialchars(
                                            $item['product_name'] ?? ''
                                        ) ?>
                                    </strong>

                                    <?php if (!empty($item['size_name'])): ?>
                                        — размер
                                        <?= htmlspecialchars(
                                            $item['size_name']
                                        ) ?>
                                    <?php endif; ?>

                                    — <?= (int) ($item['quantity'] ?? 0) ?> шт.
                                </div>

                            <?php endforeach; ?>
                        </div>

                    <?php endif; ?>

                    <p
                        style="
                            margin: 12px 0 0;
                            font-size: 18px;
                            font-weight: bold;
                        "
                    >
                        Итого:
                        <?= number_format(
                            (float) ($order['total'] ?? 0),
                            2,
                            ',',
                            ' '
                        ) ?> €
                    </p>
                </article>

            <?php endforeach; ?>

        <?php endif; ?>

    </section>

</main>

</body>
</html>
