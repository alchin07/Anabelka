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
$headerSearchQuery = '';
$favoriteProductIds = [];
$favoriteLookup = [];
$cartCount = 0;

if (!$isAdminPage) {
    PublicInterfaceTranslator::seed();
    SearchInterfaceTranslator::seed();
    FavoriteInterfaceTranslator::seed();

    $currentLanguage = Translator::currentLanguage();
    $activeLanguages = Translator::activeLanguages();

    $favoriteProductIds = Favorite::currentIds();
    $favoriteLookup = array_fill_keys($favoriteProductIds, true);

    if ($requestPath === '/Anabelka/search') {
        $headerSearchQuery = CatalogSearch::normalizeQuery(
            $_GET['q'] ?? ''
        );
    }

    if (!empty($_SESSION['user_id'])) {
        $dbItems = Cart::getItemsByUserId(
            (int) $_SESSION['user_id']
        );

        foreach ($dbItems as $item) {
            $cartCount += (int) ($item['quantity'] ?? 0);
        }
    } else {
        foreach ($_SESSION['cart'] ?? [] as $item) {
            $cartCount += (int) ($item['quantity'] ?? 0);
        }
    }
}

$isCheckoutPage =
    $requestPath === '/Anabelka/checkout';

$isProductPage =
    strpos(
        $requestPath,
        '/Anabelka/product/'
    ) === 0;

if ($isAdminPage) {
    require __DIR__ . '/../admin/partials/header.php';
    return;
}

$currentLanguageShort = strtoupper((string) (
    $currentLanguage['short_name']
    ?? $currentLanguage['code']
    ?? 'UA'
));
$currentUserName = trim((string) (
    $_SESSION['user_name'] ?? ''
));
$currentUri = $_SERVER['REQUEST_URI'] ?? '/Anabelka/';

?>

<header class="catalog-header public-header">

    <link
        rel="stylesheet"
        href="/Anabelka/css/search.css?v=2"
    >

    <link
        rel="stylesheet"
        href="/Anabelka/css/favorites.css?v=1"
    >

    <link
        rel="stylesheet"
        href="/Anabelka/css/public-header.css?v=1"
    >

    <div class="public-header-shell">
        <div class="public-header-main">
            <a
                href="/Anabelka/"
                class="catalog-logo public-header-logo"
                aria-label="Анабелька"
            >
                Анабелька
            </a>

            <form
                class="site-search-form"
                action="/Anabelka/search"
                method="get"
                role="search"
                data-search-suggest-endpoint="/Anabelka/search/suggest"
                data-search-products-label="<?= htmlspecialchars(
                    Translator::t('search.products', 'Товари')
                ) ?>"
                data-search-categories-label="<?= htmlspecialchars(
                    Translator::t('search.categories', 'Категорії')
                ) ?>"
                data-search-empty-label="<?= htmlspecialchars(
                    Translator::t(
                        'search.empty',
                        'Нічого не знайдено. Спробуйте інший запит.'
                    )
                ) ?>"
                data-search-all-label="<?= htmlspecialchars(
                    Translator::t(
                        'search.suggest_all',
                        'Показати всі результати'
                    )
                ) ?>"
            >
                <input
                    class="site-search-input"
                    type="search"
                    name="q"
                    maxlength="200"
                    autocomplete="off"
                    value="<?= htmlspecialchars($headerSearchQuery) ?>"
                    placeholder="<?= htmlspecialchars(
                        Translator::t(
                            'search.placeholder',
                            'Пошук товарів, категорій, SKU…'
                        )
                    ) ?>"
                    aria-label="<?= htmlspecialchars(
                        Translator::t('search.title', 'Пошук')
                    ) ?>"
                    aria-autocomplete="list"
                    aria-controls="site-search-suggestions"
                    aria-expanded="false"
                >

                <button class="site-search-button" type="submit">
                    <svg
                        class="public-header-search-icon"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    >
                        <circle
                            cx="11"
                            cy="11"
                            r="6.5"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        />
                        <path
                            d="M16 16L21 21"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                        />
                    </svg>
                    <span class="public-header-search-label">
                        <?= htmlspecialchars(
                            Translator::t('search.button', 'Знайти')
                        ) ?>
                    </span>
                </button>

                <div
                    id="site-search-suggestions"
                    class="site-search-suggestions"
                    role="listbox"
                    hidden
                ></div>
            </form>

            <nav
                class="public-header-actions"
                aria-label="Навігація користувача"
            >
                <a
                    href="/Anabelka/favorites"
                    class="public-header-action header-favorites"
                    aria-label="<?= htmlspecialchars(
                        Translator::t('header.favorites', 'Обране')
                    ) ?>"
                >
                    <span class="public-header-action-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24">
                            <path
                                d="M12 20.2S4 15.3 4 8.9C4 6.2 5.9 4.5 8.2 4.5c1.5 0 2.9.8 3.8 2 0 0 1.5-2 3.8-2C18.1 4.5 20 6.2 20 8.9c0 6.4-8 11.3-8 11.3Z"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linejoin="round"
                            />
                        </svg>
                    </span>
                    <span class="public-header-action-label">
                        <?= htmlspecialchars(
                            Translator::t('header.favorites', 'Обране')
                        ) ?>
                    </span>
                    <span
                        class="public-header-count header-favorites-count"
                        id="favorite-count"
                    ><?= count($favoriteProductIds) ?></span>
                </a>

                <details class="public-header-menu public-header-profile">
                    <summary
                        class="public-header-action"
                        aria-label="<?= htmlspecialchars(
                            Translator::t('header.profile', 'Профіль')
                        ) ?>"
                    >
                        <span class="public-header-action-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24">
                                <circle
                                    cx="12"
                                    cy="8"
                                    r="3.5"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                />
                                <path
                                    d="M5.5 20c.5-4 3-6 6.5-6s6 2 6.5 6"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    stroke-linecap="round"
                                />
                            </svg>
                        </span>
                        <span class="public-header-action-label">
                            <?= htmlspecialchars(
                                $currentUserName !== ''
                                    ? $currentUserName
                                    : Translator::t('header.login', 'Увійти')
                            ) ?>
                        </span>
                    </summary>

                    <div class="public-header-popover">
                        <?php if (!empty($_SESSION['user_id'])): ?>
                            <span class="public-header-popover-title">
                                <?= htmlspecialchars(
                                    $currentUserName !== ''
                                        ? $currentUserName
                                        : Translator::t('header.profile', 'Профіль')
                                ) ?>
                            </span>

                            <a href="/Anabelka/orders">
                                <?= htmlspecialchars(
                                    Translator::t(
                                        'header.orders',
                                        'Мої замовлення'
                                    )
                                ) ?>
                            </a>

                            <a href="/Anabelka/logout">
                                <?= htmlspecialchars(
                                    Translator::t('header.logout', 'Вийти')
                                ) ?>
                            </a>
                        <?php else: ?>
                            <a href="/Anabelka/login">
                                <?= htmlspecialchars(
                                    Translator::t('header.login', 'Увійти')
                                ) ?>
                            </a>

                            <a href="/Anabelka/register">
                                <?= htmlspecialchars(
                                    Translator::t(
                                        'header.register',
                                        'Реєстрація'
                                    )
                                ) ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </details>

                <a
                    href="/Anabelka/cart"
                    class="public-header-action header-cart"
                    aria-label="<?= htmlspecialchars(
                        Translator::t('header.cart', 'Кошик')
                    ) ?>"
                >
                    <span class="public-header-action-icon header-cart-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24">
                            <path
                                d="M3 4H5L7.2 14.2A2 2 0 0 0 9.15 16H17.5A2 2 0 0 0 19.45 14.45L21 8H7"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                            <circle cx="10" cy="19" r="1.3" fill="currentColor" />
                            <circle cx="18" cy="19" r="1.3" fill="currentColor" />
                        </svg>
                    </span>
                    <span class="public-header-action-label">
                        <?= htmlspecialchars(
                            Translator::t('header.cart', 'Кошик')
                        ) ?>
                    </span>
                    <span
                        class="public-header-count header-cart-count"
                        id="cart-count"
                    ><?= $cartCount ?></span>
                </a>

                <?php if (!empty($activeLanguages)): ?>
                    <details class="public-header-menu public-header-language">
                        <summary
                            class="public-header-action public-header-language-code"
                            aria-label="<?= htmlspecialchars(
                                Translator::t('header.language', 'Мова')
                            ) ?>"
                            title="<?= htmlspecialchars(
                                Translator::t('header.language', 'Мова')
                            ) ?>"
                        >
                            <?= htmlspecialchars($currentLanguageShort) ?>
                        </summary>

                        <div class="public-header-popover">
                            <span class="public-header-popover-title">
                                <?= htmlspecialchars(
                                    Translator::t('header.language', 'Мова')
                                ) ?>
                            </span>

                            <?php foreach ($activeLanguages as $language): ?>
                                <?php
                                $isCurrentLanguage =
                                    ($currentLanguage['code'] ?? '')
                                    === ($language['code'] ?? '');
                                ?>

                                <form
                                    class="public-header-language-form"
                                    action="/Anabelka/language/change"
                                    method="post"
                                >
                                    <input
                                        type="hidden"
                                        name="return_url"
                                        value="<?= htmlspecialchars(
                                            $currentUri,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>"
                                    >

                                    <button
                                        type="submit"
                                        name="language_code"
                                        value="<?= htmlspecialchars(
                                            $language['code'] ?? '',
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>"
                                        class="<?= $isCurrentLanguage ? 'is-current' : '' ?>"
                                        <?= $isCurrentLanguage ? 'disabled' : '' ?>
                                    >
                                        <?= htmlspecialchars(
                                            ($language['short_name'] ?? '')
                                            . ' — '
                                            . ($language['name'] ?? '')
                                        ) ?>
                                    </button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endif; ?>
            </nav>
        </div>

        <?php if (!empty($pageTitle)): ?>
            <h1 class="public-header-page-title">
                <?= htmlspecialchars($pageTitle) ?>
            </h1>
        <?php endif; ?>
    </div>

    <script
        src="/Anabelka/js/search-suggestions.js?v=1"
        defer
    ></script>

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

    <script
        src="/Anabelka/js/product-color-variants.js?v=2"
        defer
    ></script>

<?php endif; ?>

<?php if (
    ($pageContext ?? '') === 'cart'
    || ($pageTitle ?? '') === 'Корзина'
): ?>

    <script
        src="/Anabelka/js/cart-quick-order.js?v=2"
    ></script>

    <script
        src="/Anabelka/js/cart-legacy-color.js?v=2"
        defer
    ></script>

    <?php require __DIR__ . '/cart-server-i18n.php'; ?>

<?php endif; ?>

    <script
        id="favorites-script"
        src="/Anabelka/js/favorites.js?v=1"
        data-endpoint="/Anabelka/favorites/toggle"
        data-state-endpoint="/Anabelka/favorites/state"
        data-add-label="<?= htmlspecialchars(
            Translator::t('favorite.add', 'Додати до обраного'),
            ENT_QUOTES,
            'UTF-8'
        ) ?>"
        data-remove-label="<?= htmlspecialchars(
            Translator::t('favorite.remove', 'Видалити з обраного'),
            ENT_QUOTES,
            'UTF-8'
        ) ?>"
        defer
    ></script>

</header>