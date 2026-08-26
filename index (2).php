<!DOCTYPE html>
<html lang="ru">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Корзина — Анабелька</title>

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

    <?php

$pageTitle = 'Корзина';

require __DIR__ . '/../partials/header.php';

?>


    <main class="catalog" id="cart-page">

        <p style="margin-bottom: 25px;">

            <a
                href="/Anabelka/catalog"
                style="color: var(--primary-color);"
            >
                ← Продолжить покупки
            </a>

        </p>


        <?php if (empty($items)): ?>

            <p id="empty-cart-message">
                Корзина пока пуста.
            </p>

        <?php else: ?>

            <div id="cart-items">

                <?php foreach ($items as $item): ?>

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
                                $item['product']['name']
                            ) ?>
                        </h2>


                        <p>
                            Цена:
                            <?= number_format(
                                Product::getCurrentPrice($item['product']),
                                2,
                                ',',
                                ' '
                            ) ?> €
                        </p>


                        <p>
                            Размер:
                            <?= htmlspecialchars(
                                $item['size']['value'] ?? '—'
                            ) ?>
                        </p>


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
                            Сумма:
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
                            Удалить
                        </button>

                    </section>

                <?php endforeach; ?>

            </div>


            <h2>
                Итого:
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
    style="
        display: block;
        width: 100%;
        box-sizing: border-box;

        margin-top: 20px;
        padding: 14px;

        border-radius: 12px;

        background:
            var(--primary-color);

        color: #fff;

        text-align: center;
        text-decoration: none;

        font-size: 16px;
        font-weight: bold;
    "
>
    Оформить заказ
</a> 

        <?php endif; ?>

    </main>
  
      <div
      id="site-message"
      class="site-message"
    ></div>


    <script>

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
            responseText ||
            'Не удалось изменить корзину.'
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
                            this.closest(
                                '.cart-item'
                            );

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

                            recalculateCart()
                    updateHeaderCartCount();

                        } catch (error) {

    showMessage(
        error.message ||
        'Не удалось изменить количество.'
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
                            this.closest(
                                '.cart-item'
                            );

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
        error.message ||
        'Не удалось удалить товар.'
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
                            this.closest(
                                '.cart-item'
                            );

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

                            alert(
                                'Не удалось удалить товар.'
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


    message.textContent =
        text;

    message.classList.add(
        'show'
    );


    clearTimeout(
        window.siteMessageTimer
    );


    window.siteMessageTimer =
        setTimeout(() => {

            message.classList.remove(
                'show'
            );

        }, 2200);
}

    </script>

</body>

</html>