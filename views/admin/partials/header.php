<?php

$adminPageTitle = trim((string) ($pageTitle ?? ''));
$adminPageLabel = preg_replace(
    '/^(Адмін-панель|Админ-панель|Адмінпанель|Админпанель)\s*(?:[—–·:-])?\s*/u',
    '',
    $adminPageTitle
);

if ($adminPageLabel === '' || $adminPageLabel === null) {
    $adminPageLabel = 'Головна';
}

$adminPageLabelMap = [
    'Быстрые заказы' => 'Замовлення',
    'Заказы' => 'Замовлення',
    'Языки' => 'Мови',
    'Переводы' => 'Переклади',
    'ИИ-перевод' => 'ШІ-переклад',
    'Категории' => 'Категорії',
    'Товары' => 'Товари',
    'Поиск' => 'Пошук',
    'Пользователи' => 'Користувачі',
    'Администраторы' => 'Адміністратори',
    'Журнал действий' => 'Журнал дій'
];

$adminPageLabel = $adminPageLabelMap[$adminPageLabel]
    ?? $adminPageLabel;

$adminPageLabel = 'Адмін-панель · ' . $adminPageLabel;

$adminNavBadges = is_array($navBadges ?? null)
    ? $navBadges
    : [];

if (!array_key_exists('orders', $adminNavBadges)) {
    if (
        array_key_exists('regular_orders', $adminNavBadges)
        || array_key_exists('quick_orders', $adminNavBadges)
    ) {
        $adminNavBadges['orders'] = (int) (
            ($adminNavBadges['regular_orders'] ?? 0)
            + ($adminNavBadges['quick_orders'] ?? 0)
        );
    } elseif (class_exists('AdminOrder')) {
        try {
            $orderSummary = AdminOrder::summary();
            $adminNavBadges['orders'] = (int) (
                $orderSummary['new'] ?? 0
            );
        } catch (Throwable $e) {
            $adminNavBadges['orders'] = 0;
        }
    }
}

$orderBadge = (int) ($adminNavBadges['orders'] ?? 0);
$translationBadge = (int) (
    $adminNavBadges['translations'] ?? 0
);

$currentAdmin = class_exists('AdminAccess')
    ? AdminAccess::current()
    : null;
$adminCsrfToken = class_exists('AdminAccess')
    ? AdminAccess::csrfToken()
    : '';

$adminCan = static function ($permission) {
    return !class_exists('AdminAccess')
        || AdminAccess::can((string) $permission);
};

$canDashboard = $adminCan('dashboard.view');
$canOrders = $adminCan('orders.view');
$canSearch = $adminCan('search.view');
$canUsers = $adminCan('users.view');
$canRanks = $adminCan('ranks.view');
$canProducts = $adminCan('products.view');
$canCategories = $adminCan('categories.view');
$canDelivery = $adminCan('delivery.view');
$canLanguages = $adminCan('languages.view');
$canTranslations = $adminCan('translations.view');
$canAiTranslation = $adminCan('ai_translation.view');
$canAdministrators = $adminCan('administrators.view');
$canAudit = $adminCan('audit.view');

?>

<link
    rel="stylesheet"
    href="/Anabelka/css/admin-layout.css?v=3"
>
<link
    rel="stylesheet"
    href="/Anabelka/css/admin-access.css?v=1"
>
<link
    rel="stylesheet"
    href="/Anabelka/css/admin-product-editor-fixes.css?v=1"
>

<header class="admin-site-header">
    <div class="admin-topbar">
        <button
            id="admin-menu-toggle"
            class="admin-menu-toggle"
            type="button"
            aria-label="Відкрити меню адмін-панелі"
            aria-controls="admin-drawer"
            aria-expanded="false"
        >
            <span></span>
            <span></span>
            <span></span>
        </button>

        <div class="admin-brand">
            <a
                class="admin-brand-name"
                href="/Anabelka/admin"
            >
                Анабелька
            </a>

            <h1 class="admin-page-name">
                <?= htmlspecialchars($adminPageLabel) ?>
            </h1>
        </div>

        <a
            class="admin-store-link"
            href="/Anabelka/"
            aria-label="Відкрити сайт Анабелька"
        >
            <span class="admin-store-link-full">Відкрити сайт</span>
            <span class="admin-store-link-short" aria-hidden="true">Сайт</span>
        </a>
    </div>
</header>

<div
    id="admin-menu-backdrop"
    class="admin-menu-backdrop"
    aria-hidden="true"
></div>

<aside
    id="admin-drawer"
    class="admin-drawer"
    aria-label="Розділи адмін-панелі"
    aria-hidden="true"
>
    <div class="admin-drawer-head">
        <div>
            <strong>Анабелька</strong>
            <span>Керування магазином</span>
        </div>

        <button
            id="admin-menu-close"
            class="admin-menu-close"
            type="button"
            aria-label="Закрити меню"
        >
            ×
        </button>
    </div>

    <nav id="admin-section-nav" class="admin-section-nav">
        <?php if ($canDashboard || $canOrders || $canSearch || $canUsers || $canRanks): ?>
            <span class="admin-nav-group-title">Огляд</span>

            <?php if ($canDashboard): ?>
                <a
                    href="/Anabelka/admin"
                    data-admin-route="/Anabelka/admin"
                    data-admin-exact="true"
                >
                    <span>Головна</span>
                </a>
            <?php endif; ?>

            <?php if ($canOrders): ?>
                <a
                    href="/Anabelka/admin/orders"
                    data-admin-route="/Anabelka/admin/orders"
                >
                    <span>Замовлення</span>
                    <?php if ($orderBadge > 0): ?>
                        <span class="admin-nav-badge">
                            <?= $orderBadge ?>
                        </span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>

            <?php if ($canSearch): ?>
                <a
                    href="/Anabelka/admin/search"
                    data-admin-route="/Anabelka/admin/search"
                >
                    <span>Пошук</span>
                </a>
            <?php endif; ?>

            <?php if ($canUsers): ?>
                <a
                    href="/Anabelka/admin/users"
                    data-admin-route="/Anabelka/admin/users"
                >
                    <span>Користувачі</span>
                </a>
            <?php endif; ?>

            <?php if ($canRanks): ?>
                <a
                    href="/Anabelka/admin/ranks"
                    data-admin-route="/Anabelka/admin/ranks"
                >
                    <span>Ранги</span>
                </a>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($canProducts || $canCategories || $canDelivery): ?>
            <span class="admin-nav-group-title">Каталог</span>

            <?php if ($canProducts): ?>
                <a
                    href="/Anabelka/admin/products"
                    data-admin-route="/Anabelka/admin/products"
                >
                    <span>Товари</span>
                </a>
            <?php endif; ?>

            <?php if ($canCategories): ?>
                <a
                    href="/Anabelka/admin/categories"
                    data-admin-route="/Anabelka/admin/categories"
                >
                    <span>Категорії</span>
                </a>
            <?php endif; ?>

            <?php if ($canDelivery): ?>
                <a
                    href="/Anabelka/admin/delivery"
                    data-admin-route="/Anabelka/admin/delivery"
                >
                    <span>Доставка</span>
                </a>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($canLanguages || $canTranslations || $canAiTranslation): ?>
            <span class="admin-nav-group-title">Мови та ШІ</span>

            <?php if ($canLanguages): ?>
                <a
                    href="/Anabelka/admin/languages"
                    data-admin-route="/Anabelka/admin/languages"
                >
                    <span>Мови</span>
                </a>
            <?php endif; ?>

            <?php if ($canTranslations): ?>
                <a
                    href="/Anabelka/admin/translations"
                    data-admin-route="/Anabelka/admin/translations"
                >
                    <span>Переклади</span>
                    <?php if ($translationBadge > 0): ?>
                        <span class="admin-nav-badge is-attention">
                            <?= $translationBadge ?>
                        </span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>

            <?php if ($canAiTranslation): ?>
                <a
                    href="/Anabelka/admin/ai-translation"
                    data-admin-route="/Anabelka/admin/ai-translation"
                >
                    <span>Налаштування ШІ</span>
                </a>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($canAdministrators || $canAudit): ?>
            <span class="admin-nav-group-title">Безпека</span>

            <?php if ($canAdministrators): ?>
                <a
                    href="/Anabelka/admin/administrators"
                    data-admin-route="/Anabelka/admin/administrators"
                >
                    <span>Адміністратори</span>
                </a>
            <?php endif; ?>

            <?php if ($canAudit): ?>
                <a
                    href="/Anabelka/admin/audit"
                    data-admin-route="/Anabelka/admin/audit"
                >
                    <span>Журнал дій</span>
                </a>
            <?php endif; ?>
        <?php endif; ?>
    </nav>

    <?php if ($canAiTranslation): ?>
        <div class="admin-drawer-ai">
            <span class="admin-drawer-ai-title">
                ШІ для поточного перекладу
            </span>
            <div id="admin-ai-slot"></div>
        </div>
    <?php endif; ?>

    <?php if ($currentAdmin): ?>
        <div class="admin-drawer-account">
            <div class="admin-drawer-account-copy">
                <strong><?= htmlspecialchars((string) ($currentAdmin['name'] ?? 'Адміністратор')) ?></strong>
                <span><?= htmlspecialchars((string) ($currentAdmin['role_name'] ?? '')) ?></span>
            </div>
            <form method="post" action="/Anabelka/admin/logout">
                <input
                    type="hidden"
                    name="_csrf"
                    value="<?= htmlspecialchars($adminCsrfToken, ENT_QUOTES, 'UTF-8') ?>"
                >
                <button type="submit">Вийти</button>
            </form>
        </div>
    <?php endif; ?>

    <a class="admin-drawer-store-link" href="/Anabelka/">
        Перейти до магазину
    </a>
</aside>

<script src="/Anabelka/js/admin-ui-focus-policy.js?v=2"></script>
<script src="/Anabelka/js/admin-product-preview.js?v=1"></script>
<script defer src="/Anabelka/js/admin-product-variant-stock.js?v=2"></script>
<script defer src="/Anabelka/js/admin-product-editor-fixes.js?v=1"></script>
<script defer src="/Anabelka/js/admin-order-variants.js?v=1"></script>
<script src="/Anabelka/js/admin-nav.js?v=16"></script>
