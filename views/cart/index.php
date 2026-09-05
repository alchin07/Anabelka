<?php

$currentLanguage = Translator::currentLanguage();
$pageContext = 'cart';
$pageTitle = Translator::t('cart.title', 'Кошик');

?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLanguage['code'] ?? 'uk') ?>">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title><?= htmlspecialchars($pageTitle) ?> — Анабелька</title>

    <link
        rel="stylesheet"
        href="/Anabelka/css/style.css"
    >

    <link
        rel="stylesheet"
        href="/Anabelka/css/catalog.css?v=4"
    >

</head>

<body>

    <?php require __DIR__ . '/../partials/header.php'; ?>


    <main class="catalog" id="cart-page">

        <p style="margin-bottom: 25px;">

            <a
                href="/Anabelka/catalog"
                style="color: var(--primary-color);"
            >
                ← <?= htmlspecialchars(
                    Translator::t(
                        'cart.continue_shopping',
                        'Продовжити покупки'
                    )
                ) ?>
            </a>

        </p>


        <?php if (empty($items)): ?>

            <p id="empty-cart-message">
                <?= htmlspecialchars(
                    Translator::t(
                        'cart.empty',
                        'Кошик поки порожній.'
                    )
                ) ?>
            </p>

        <?php else: ?>

            <div id="cart-items">

                <?php foreach ($items as $item): ?>

                    <?php
                    $localizedProduct =
                        ProductTranslator::localize(
                            $item['product'],
                            $currentLanguage['code']
                            ?? Language::SOURCE_CODE
                        );
                    $colorName = trim((string) ($item['color_name'] ?? ''));
                    $colorHex = trim((string) ($item['color_hex'] ?? ''));
                    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $colorHex)) {
                        $colorHex = '#b8b0bd';
                    }
                    ?>

                    <section
                        class="product-card cart-item"
                        data-cart-key="<?= htmlspecialchars($item['cart_key']) ?>"
                        data-price="<?= Product::getCurrentPrice($item['product']) ?>"
                        style="
                            margin-bottom: 20px;
                            padding: 15px;
                        "
                    >

                        <h2>
                            <?= htmlspecialchars(
                                $localizedProduct['name']
                                ?? $item['product']['name']
                            ) ?>
                        </h2>


                        <p>
                            <?= htmlspecialchars(
                                Translator::t('cart.price', 'Ціна')
                            ) ?>:
                            <?= number_format(
                                Product::getCurrentPrice($item['product']),
                                2,
                                ',',
                                ' '
                            ) ?> €
                        </p>


                        <p>
                            <?= htmlspecialchars(
                                Translator::t('cart.size', 'Розмір')
                            ) ?>:
                            <?= htmlspecialchars(
                                $item['size']['value'] ?? '—'
                            ) ?>
                        </p>

                        <?php if ($colorName !== ''): ?>
                            <p
                                style="
                                    display:flex;
                                    align-items:center;
                                    gap:7px;
                                "
                            >
                                <span>Колір:</span>
                                <span
                                    aria-hidden="true"
                                    style="
                                        width:18px;
                                        height:18px;
                                        flex:0 0 18px;
                                        border:1px solid rgba(50,43,55,.28);
                                        border-radius:50%;
                                        background:<?= htmlspecialchars($colorHex, ENT_QUOTES, 'UTF-8') ?>;
                                    "
                                ></span>
                                <strong><?= htmlspecialchars($colorName) ?></strong>
                            </p>
                        <?php endif; ?>


                        <div
                            style="
                                display: flex;
                                align-items: center;
                                gap: 10px;
                                margin: 12px 0;
                            "
                        >

                            <button
                                type="button"
                                class="cart-decrease"
                                style="
                                    width: 38px;
                                    height: 38px;
                                    border: 1px solid var(--primary-color);
                                    border-radius: 10px;
                                    background: #fff;
                                    color: var(--primary-color);
                                    font-size: 20px;
                                    cursor: pointer;
                                "
                            >
                                −
                            </button>


                            <strong class="cart-quantity">
                                <?= (int) $item['quantity'] ?>
                            </strong>


                            <button
                                type="button"
                                class="cart-increase"
                                style="
                                    width: 38px;
                                    height: 38px;
                                    border: 1px solid var(--primary-color);
                                    border-radius: 10px;
                                    background: #fff;
                                    color: var(--primary-color);
                                    font-size: 20px;
                                    cursor: pointer;
                                "
                            >
                                +
                            </button>

                        </div>


                        <p>
                            <?= htmlspecialchars(
                                Translator::t('cart.sum', 'Сума')
                            ) ?>:
                            <span class="cart-item-sum">
                                <?= number_format(
                                    (float) $item['sum'],
                                    2,
                                    ',',
                                    ' '
                                ) ?>
                            </span>
                            €
                        </p>


                        <button
                            type="button"
                            class="cart-remove"
                            style="
                                margin-top: 15px;
                                border: 0;
                                background: transparent;
                                color: #b00020;
                                cursor: pointer;
                                font-size: 15px;
                                padding: 0;
                            "
                        >
                            <?= htmlspecialchars(
                                Translator::t('cart.remove', 'Видалити')
                            ) ?>
                        </button>

                    </section>

                <?php endforeach; ?>

            </div>


            <h2>
                <?= htmlspecialchars(
                    Translator::t('cart.total', 'Разом')
                ) ?>:
                <span id="cart-total">
                    <?= number_format(
                        (float) $total,
                        2,
                        ',',
                        ' '
                    ) ?>
                </span>
                €
            </h2>

            <a
                href="/Anabelka/checkout"
                data-quick-order-label="<?= htmlspecialchars(
                    Translator::t(
                        'cart.quick_order',
                        'Швидке замовлення'
                    )
                ) ?>"
                style="
                    display: block;
                    width: 100%;
                    box-sizing: border-box;
                    margin-top: 20px;
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
                        'cart.checkout',
                        'Оформити замовлення'
                    )
                ) ?>
            </a>

        <?php endif; ?>

    </main>

    <div
        id="site-message"
        class="site-message"
    ></div>


    <script>

        const cartMessages = {
            change: <?= json_encode(
                Translator::t(
                    'cart.error_change',
                    'Не вдалося змінити кошик.'
                ),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ) ?>,
            quantity: <?= json_encode(
                Translator::t(
                    'cart.error_quantity',
                    'Не вдалося змінити кількість.'
                ),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ) ?>,
            remove: <?= json_encode(
                Translator::t(
                    'cart.error_remove',
                    'Не вдалося видалити товар.'
                ),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ) ?>
        };


        function formatMoney(value)
        {
            return Number(value)
                .toFixed(2)
                .replace('.', ',');
        }


        function updateHeaderCartCount()
        {
            const cartCount =
                document.getElementById(
                    'cart-count'
                );

            if (!cartCount) {
                return;
            }

            let totalQuantity = 0;

            document
                .querySelectorAll('.cart-item')
                .forEach((item) => {

                    const quantityElement =
                        item.querySelector(
                            '.cart-quantity'
                        );

                    if (quantityElement) {
                        totalQuantity +=
                            parseInt(
                                quantityElement.textContent,
                                10
                            ) || 0;
                    }
                });

            cartCount.textContent =
                totalQuantity;
        }


        function recalculateCart()
        {
            let total = 0;

            const items =
                document.querySelectorAll('.cart-item');

            items.forEach((item) => {

                const price =
                    Number(item.dataset.price);

                const quantity =
                    Number(
                        item.querySelector(
                            '.cart-quantity'
                        ).textContent
                    );

                const sum =
                    price * quantity;

                item.querySelector(
                    '.cart-item-sum'
                ).textContent =
                    formatMoney(sum);

                total += sum;
            });

            const totalElement =
                document.getElementById('cart-total');

            if (totalElement) {
                totalElement.textContent =
                    formatMoney(total);
            }
        }


        async function cartRequest(url, cartKey)
        {
            const formData =
                new FormData();

            formData.append(
                'cart_key',
                cartKey
            );

            const response =
                await fetch(
                    url,
                    {
                        method: 'POST',
                        body: formData
                    }
                );

            const responseText =
                await response.text();

            if (!response.ok) {
                throw new Error(
                    responseText || cartMessages.change
                );
            }

            return responseText;
        }


        document
            .querySelectorAll('.cart-increase')
            .forEach((button) => {

                button.addEventListener(
                    'click',
                    async function () {

                        const item =
                            this.closest('.cart-item');

                        const cartKey =
                            item.dataset.cartKey;

                        try {
                            await cartRequest(
                                '/Anabelka/cart/increase',
                                cartKey
                            );

                            const quantityElement =
                                item.querySelector(
                                    '.cart-quantity'
                                );

                            quantityElement.textContent =
                                Number(
                                    quantityElement.textContent
                                ) + 1;

                            recalculateCart();
                            updateHeaderCartCount();

                        } catch (error) {
                            showMessage(
                                error.message
                                || cartMessages.quantity
                            );
                        }
                    }
                );
            });


        document
            .querySelectorAll('.cart-decrease')
            .forEach((button) => {

                button.addEventListener(
                    'click',
                    async function () {

                        const item =
                            this.closest('.cart-item');

                        const cartKey =
                            item.dataset.cartKey;

                        const quantityElement =
                            item.querySelector(
                                '.cart-quantity'
                            );

                        const currentQuantity =
                            Number(
                                quantityElement.textContent
                            );

                        try {
                            await cartRequest(
                                '/Anabelka/cart/decrease',
                                cartKey
                            );

                            if (currentQuantity <= 1) {
                                item.remove();
                            } else {
                                quantityElement.textContent =
                                    currentQuantity - 1;
                            }

                            recalculateCart();
                            updateHeaderCartCount();

                        } catch (error) {
                            showMessage(
                                error.message
                                || cartMessages.remove
                            );
                        }
                    }
                );
            });


        document
            .querySelectorAll('.cart-remove')
            .forEach((button) => {

                button.addEventListener(
                    'click',
                    async function () {

                        const item =
                            this.closest('.cart-item');

                        const cartKey =
                            item.dataset.cartKey;

                        try {
                            await cartRequest(
                                '/Anabelka/cart/remove',
                                cartKey
                            );

                            item.remove();

                            recalculateCart();
                            updateHeaderCartCount();

                        } catch (error) {
                            showMessage(
                                error.message
                                || cartMessages.remove
                            );
                        }
                    }
                );
            });


        function showMessage(text)
        {
            const message =
                document.getElementById(
                    'site-message'
                );

            if (!message) {
                return;
            }

            message.textContent = text;
            message.classList.add('show');

            clearTimeout(
                window.siteMessageTimer
            );

            window.siteMessageTimer =
                setTimeout(() => {
                    message.classList.remove('show');
                }, 2200);
        }

    </script>

</body>

</html>
