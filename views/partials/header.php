<header class="catalog-header">

    <a
        href="/Anabelka/"
        class="catalog-logo"
    >
        Анабелька
    </a>

    <?php if (!empty($pageTitle)): ?>

        <h1>
            <?= htmlspecialchars($pageTitle) ?>
        </h1>

    <?php endif; ?>


    <a
        href="/Anabelka/cart"
        class="header-cart"
        aria-label="Корзина"
    >
        <span class="header-cart-icon">

            <svg
                viewBox="0 0 24 24"
                aria-hidden="true"
            >
                <path
                    d="
                        M3 4
                        H5
                        L7.2 14.2
                        A2 2 0 0 0 9.15 16
                        H17.5
                        A2 2 0 0 0 19.45 14.45
                        L21 8
                        H7
                    "
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />

                <circle
                    cx="10"
                    cy="19"
                    r="1.3"
                    fill="currentColor"
                />

                <circle
                    cx="18"
                    cy="19"
                    r="1.3"
                    fill="currentColor"
                />

            </svg>

        </span>

        <span
            class="header-cart-count"
            id="cart-count"
        >
            <?php

$cartCount = 0;

if (!empty($_SESSION['user_id'])) {

    // Авторизованный пользователь:
    // считаем товары из MySQL.
    $dbItems = Cart::getItemsByUserId(
        $_SESSION['user_id']
    );

    foreach ($dbItems as $item) {
        $cartCount += (int) $item['quantity'];
    }

} else {

    // Гость:
    // считаем товары из сессии.
    foreach ($_SESSION['cart'] ?? [] as $item) {
        $cartCount += (int) ($item['quantity'] ?? 0);
    }
}

echo $cartCount;

?>
        </span>

    </a>
    
    <div
    class="header-user"
    style="
        margin-top: 15px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        flex-wrap: wrap;
    "
>

    <?php if (!empty($_SESSION['user_id'])): ?>

        <span>
            <?= htmlspecialchars(
                $_SESSION['user_name'] ?? 'Пользователь'
            ) ?>
        </span>

        <a
            href="/Anabelka/logout"
            style="
                color: var(--primary-color);
                font-weight: bold;
                text-decoration: none;
            "
        >
            Выйти
        </a>

    <?php else: ?>

        <a
            href="/Anabelka/login"
            style="
                color: var(--primary-color);
                font-weight: bold;
                text-decoration: none;
            "
        >
            Войти
        </a>

        <span>·</span>

        <a
            href="/Anabelka/register"
            style="
                color: var(--primary-color);
                font-weight: bold;
                text-decoration: none;
            "
        >
            Регистрация
        </a>

    <?php endif; ?>

</div>

<?php if (($pageTitle ?? '') === 'Оформление заказа'): ?>

    <script
        src="/Anabelka/js/checkout-delivery-input.js?v=2"
    ></script>

<?php endif; ?>

</header>