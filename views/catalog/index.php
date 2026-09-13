<?php
PublicInterfaceTranslator::seed();
$currentLanguage = Translator::currentLanguage();
$pageTitle = Translator::t('public.catalog.title', 'Каталог');
$categories = is_array($categories ?? null) ? $categories : [];
$standardCategories = [];
$adultCategories = [];

foreach ($categories as $category) {
    if (($category['parent_id'] ?? null) !== null) {
        continue;
    }

    if (!empty($category['is_adult'])) {
        $adultCategories[] = $category;
    } else {
        $standardCategories[] = $category;
    }
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLanguage['code'] ?? 'uk') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> — Анабелька</title>
    <link rel="stylesheet" href="/Anabelka/css/style.css?=v8">
    <link rel="stylesheet" href="/Anabelka/css/catalog.css?v=9">
</head>
<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="catalog">
    <section class="catalog-categories">
        <h2><?= htmlspecialchars(
            Translator::t('public.catalog.categories', 'Категорії')
        ) ?></h2>

        <div class="category-list">
            <?php foreach ($standardCategories as $category): ?>
                <a
                    href="<?= htmlspecialchars(
                        Category::catalogUrl($category),
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                    class="category-item"
                >
                    <?= htmlspecialchars($category['name']) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($adultCategories)): ?>
            <div class="catalog-adult-list" aria-label="18+">
                <?php foreach ($adultCategories as $category): ?>
                    <a
                        href="<?= htmlspecialchars(
                            AdultAccess::gateUrl($category),
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                        class="catalog-adult-entry"
                    >
                        <span class="catalog-adult-brand">
                            <span class="catalog-adult-brand-name">Анабелька</span>

                            <span
                                class="catalog-adult-strawberry"
                                aria-hidden="true"
                            >
                                <svg viewBox="0 0 72 72" focusable="false">
                                    <path
                                        d="M21 24c-7 4-9 13-5 24 4 11 14 19 20 21 6-2 16-10 20-21 4-11 2-20-5-24-8-5-22-5-30 0Z"
                                        fill="#f4eaff"
                                    />
                                    <path
                                        d="M36 24c-4-8-10-11-17-10 2 7 8 11 17 10Zm0 0c4-8 10-11 17-10-2 7-8 11-17 10Zm0 0c-1-8 2-14 7-18 3 7 1 13-7 18Z"
                                        fill="#ffffff"
                                    />
                                    <g fill="#8A2BE2">
                                        <circle cx="27" cy="37" r="2" />
                                        <circle cx="44" cy="37" r="2" />
                                        <circle cx="35.5" cy="46" r="2" />
                                        <circle cx="27.5" cy="52" r="2" />
                                        <circle cx="44" cy="52" r="2" />
                                        <circle cx="36" cy="59" r="2" />
                                    </g>
                                </svg>
                            </span>
                        </span>

                        <span class="catalog-adult-child">
                            <span class="catalog-adult-category-line">
                                <span class="catalog-adult-badge">18+</span>

                                <span class="catalog-adult-category-name">
                                    <?= htmlspecialchars($category['name']) ?>
                                </span>
                            </span>

                            <span class="catalog-adult-action">
                                <?= htmlspecialchars(
                                    Translator::t(
                                        'public.catalog.adult_enter',
                                        'Увійти до розділу'
                                    )
                                ) ?>
                                <span aria-hidden="true">→</span>
                            </span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="catalog-products">
        <h2><?= htmlspecialchars(
            Translator::t('public.catalog.products', 'Товари')
        ) ?></h2>

        <div class="product-grid">
            <div class="product-card">
                <div class="product-image">
                    <?= htmlspecialchars(
                        Translator::t('public.catalog.product_photo', 'Фото товару')
                    ) ?>
                </div>

                <h3><?= htmlspecialchars(
                    Translator::t('public.catalog.product_name', 'Назва товару')
                ) ?></h3>

                <p class="product-price">0 €</p>
            </div>
        </div>
    </section>
</main>

<footer class="catalog-footer">
    <a href="/Anabelka/">
        <?= htmlspecialchars(
            Translator::t('public.catalog.home', 'На головну')
        ) ?>
    </a>
</footer>

</body>
</html>
