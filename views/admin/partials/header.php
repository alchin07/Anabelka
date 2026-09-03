<?php

$adminPageTitle = trim((string) ($pageTitle ?? ''));
$adminPageLabel = preg_replace(
    '/^(Адмін-панель|Админ-панель)\s*[—-]\s*/u',
    '',
    $adminPageTitle
);

if ($adminPageLabel === '' || $adminPageLabel === null) {
    $adminPageLabel = 'Головна';
}

$adminPageLabelMap = [
    'Быстрые заказы' => 'Швидкі замовлення',
    'Языки' => 'Мови',
    'Переводы' => 'Переклади',
    'ИИ-перевод' => 'ШІ-переклад',
    'Категории' => 'Категорії',
    'Товары' => 'Товари'
];

$adminPageLabel = $adminPageLabelMap[$adminPageLabel]
    ?? $adminPageLabel;

$adminNavBadges = is_array($navBadges ?? null)
    ? $navBadges
    : [];

$regularOrderBadge = (int) (
    $adminNavBadges['regular_orders'] ?? 0
);
$quickOrderBadge = (int) (
    $adminNavBadges['quick_orders'] ?? 0
);
$translationBadge = (int) (
    $adminNavBadges['translations'] ?? 0
);

?>

<link
    rel="stylesheet"
    href="/Anabelka/css/admin-layout.css?v=2"
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
        <span class="admin-nav-group-title">Огляд</span>

        <a
            href="/Anabelka/admin"
            data-admin-route="/Anabelka/admin"
            data-admin-exact="true"
        >
            <span>Головна</span>
        </a>

        <a href="/Anabelka/admin#latest-orders">
            <span>Звичайні замовлення</span>
            <?php if ($regularOrderBadge > 0): ?>
                <span class="admin-nav-badge">
                    <?= $regularOrderBadge ?>
                </span>
            <?php endif; ?>
        </a>

        <a
            href="/Anabelka/admin/orders"
            data-admin-route="/Anabelka/admin/orders"
        >
            <span>Швидкі замовлення</span>
            <?php if ($quickOrderBadge > 0): ?>
                <span class="admin-nav-badge">
                    <?= $quickOrderBadge ?>
                </span>
            <?php endif; ?>
        </a>

        <span class="admin-nav-group-title">Каталог</span>

        <a
            href="/Anabelka/admin/products"
            data-admin-route="/Anabelka/admin/products"
        >
            <span>Товари</span>
        </a>

        <a
            href="/Anabelka/admin/categories"
            data-admin-route="/Anabelka/admin/categories"
        >
            <span>Категорії</span>
        </a>

        <a
            href="/Anabelka/admin/delivery"
            data-admin-route="/Anabelka/admin/delivery"
        >
            <span>Доставка</span>
        </a>

        <span class="admin-nav-group-title">Мови та ШІ</span>

        <a
            href="/Anabelka/admin/languages"
            data-admin-route="/Anabelka/admin/languages"
        >
            <span>Мови</span>
        </a>

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

        <a
            href="/Anabelka/admin/ai-translation"
            data-admin-route="/Anabelka/admin/ai-translation"
        >
            <span>Налаштування ШІ</span>
        </a>
    </nav>

    <div class="admin-drawer-ai">
        <span class="admin-drawer-ai-title">
            ШІ для поточного перекладу
        </span>
        <div id="admin-ai-slot"></div>
    </div>

    <a class="admin-drawer-store-link" href="/Anabelka/">
        Перейти до магазину
    </a>
</aside>

<script src="/Anabelka/js/admin-nav.js?v=14"></script>
