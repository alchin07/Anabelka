<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Быстрый заказ — Анабелька</title>

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
            padding: 20px;
        "
    >

        <h2>Быстрый заказ</h2>

        <p
            style="
                margin: 0 0 20px;
                opacity: 0.75;
            "
        >
            Оставьте имя и номер телефона.
            Мы свяжемся с вами для уточнения доставки.
        </p>

        <form
            action="/Anabelka/quick-order"
            method="POST"
        >

            <div style="margin-bottom: 15px;">
                <label>
                    Имя
                    <span
                        style="
                            color: var(--primary-color);
                            font-weight: bold;
                        "
                    >*</span>
                </label>

                <input
                    type="text"
                    name="customer_name"
                    required
                    value="<?= htmlspecialchars(
                        $_SESSION['user_name'] ?? ''
                    ) ?>"
                    style="
                        width: 100%;
                        box-sizing: border-box;
                        padding: 12px;
                        margin-top: 6px;
                        border: 1px solid var(--border-color);
                        border-radius: 10px;
                    "
                >
            </div>

            <div style="margin-bottom: 15px;">
                <label>
                    Номер телефона
                    <span
                        style="
                            color: var(--primary-color);
                            font-weight: bold;
                        "
                    >*</span>
                </label>

                <input
                    type="tel"
                    name="customer_phone"
                    required
                    autocomplete="tel"
                    style="
                        width: 100%;
                        box-sizing: border-box;
                        padding: 12px;
                        margin-top: 6px;
                        border: 1px solid var(--border-color);
                        border-radius: 10px;
                    "
                >
            </div>

            <div style="margin-bottom: 20px;">
                <label>
                    Комментарий
                </label>

                <textarea
                    name="comment"
                    rows="4"
                    style="
                        width: 100%;
                        box-sizing: border-box;
                        padding: 12px;
                        margin-top: 6px;
                        border: 1px solid var(--border-color);
                        border-radius: 10px;
                        resize: vertical;
                    "
                ></textarea>
            </div>

            <div
                style="
                    margin-bottom: 18px;
                    padding: 14px;
                    border-radius: 12px;
                    background: var(--primary-light-color);
                "
            >
                <strong>
                    Сумма заказа:
                    <?= number_format(
                        (float) $total,
                        2,
                        ',',
                        ' '
                    ) ?> €
                </strong>
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
                Отправить быстрый заказ
            </button>

        </form>

    </section>

</main>

</body>
</html>
