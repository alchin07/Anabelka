<?php

$requestPath = parse_url(
    $_SERVER['REQUEST_URI'] ?? '',
    PHP_URL_PATH
);

$requestPath = rtrim(
    (string) $requestPath,
    '/'
);

$isAdminPage =
    strpos(
        $requestPath,
        '/Anabelka/admin'
    ) === 0;

$currentLanguage = null;
$activeLanguages = [];

if (!$isAdminPage) {
    $currentLanguage =
        Translator::currentLanguage();

    $activeLanguages =
        Translator::activeLanguages();
}

$isCheckoutPage =
    $requestPath === '/Anabelka/checkout';

$isProductPage =
    strpos(
        $requestPath,
        '/Anabelka/product/'
    ) === 0;

?>

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
        aria-label="<?= htmlspecialchars(
            Translator::t(
                'header.cart',
                'Кошик'
            )
        ) ?>"
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

    $dbItems = Cart::getItemsByUserId(
        $_SESSION['user_id']
    );

    foreach ($dbItems as $item) {
        $cartCount += (int) $item['quantity'];
    }

} else {

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
                    $_SESSION['user_name'] ?? 'Користувач'
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
                <?= htmlspecialchars(
                    Translator::t(
                        'header.logout',
                        'Вийти'
                    )
                ) ?>
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
                <?= htmlspecialchars(
                    Translator::t(
                        'header.login',
                        'Увійти'
                    )
                ) ?>
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
                <?= htmlspecialchars(
                    Translator::t(
                        'header.register',
                        'Реєстрація'
                    )
                ) ?>
            </a>

        <?php endif; ?>

    </div>

    <?php if (!$isAdminPage && !empty($activeLanguages)): ?>

        <link
            rel="stylesheet"
            href="/Anabelka/css/language-switcher.css?v=1"
        >

        <div
            class="language-switcher"
            aria-label="<?= htmlspecialchars(
                Translator::t(
                    'language.switcher_label',
                    'Мова сайту'
                )
            ) ?>"
        >
            <?php foreach ($activeLanguages as $language): ?>

                <?php
                $isCurrentLanguage =
                    ($currentLanguage['code'] ?? '')
                    === ($language['code'] ?? '');
                ?>

                <form
                    class="language-switcher-form"
                    action="/Anabelka/language/change"
                    method="post"
                >
                    <input
                        type="hidden"
                        name="return_url"
                        value="<?= htmlspecialchars(
                            $_SERVER['REQUEST_URI']
                            ?? '/Anabelka/'
                        ) ?>"
                    >

                    <button
                        class="language-switcher-button<?= $isCurrentLanguage ? ' is-current' : '' ?>"
                        type="submit"
                        name="language_code"
                        value="<?= htmlspecialchars($language['code']) ?>"
                        <?= $isCurrentLanguage ? 'disabled' : '' ?>
                        title="<?= htmlspecialchars($language['name']) ?>"
                    >
                        <?= htmlspecialchars($language['short_name']) ?>
                    </button>
                </form>

            <?php endforeach; ?>
        </div>

    <?php endif; ?>

<?php if ($isCheckoutPage): ?>

    <link
        rel="stylesheet"
        href="/Anabelka/css/checkout-delivery.css?v=2"
    >

    <script
        src="/Anabelka/js/checkout-delivery-input.js?v=4"
    ></script>

<?php endif; ?>

<?php if ($isProductPage): ?>

    <?php require __DIR__ . '/product-i18n.php'; ?>

<?php endif; ?>

<?php if (
    ($pageContext ?? '') === 'cart'
    || ($pageTitle ?? '') === 'Корзина'
): ?>

    <script
        src="/Anabelka/js/cart-quick-order.js?v=2"
    ></script>

    <?php require __DIR__ . '/cart-server-i18n.php'; ?>

<?php endif; ?>

<?php if ($isAdminPage): ?>

    <script
        src="/Anabelka/js/admin-nav.js?v=10"
    ></script>

<?php endif; ?>

</header>
