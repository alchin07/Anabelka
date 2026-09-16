<?php
PublicInterfaceTranslator::seed();
HomeInterfaceTranslator::seed();
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

$escape = static function ($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
};

$renderAdultTree = null;
$renderAdultTree = function (array $nodes, $level = 1) use (
    &$renderAdultTree,
    $escape
) {
    if (empty($nodes)) {
        return;
    }
    ?>
    <ul
        class="catalog-adult-tree"
        data-level="<?= (int) $level ?>"
        hidden
    >
        <?php foreach ($nodes as $node): ?>
            <?php
            $children = is_array($node['children'] ?? null)
                ? $node['children']
                : [];
            ?>
            <li class="catalog-adult-node">
                <a
                    class="catalog-adult-node-link"
                    href="<?= $escape(AdultAccess::gateUrl($node)) ?>"
                >
                    <span class="catalog-adult-node-name">
                        <?= $escape($node['name'] ?? '') ?>
                    </span>
                    <span class="catalog-adult-node-arrow" aria-hidden="true">→</span>
                </a>

                <?php $renderAdultTree($children, $level + 1); ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php
};
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLanguage['code'] ?? 'uk') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> — Анабелька</title>
    <link rel="stylesheet" href="/Anabelka/css/style.css?=v8">
    <link rel="stylesheet" href="/Anabelka/css/catalog.css?v=11">
    <link rel="stylesheet" href="/Anabelka/css/catalog-utility-links.css?v=1">
</head>
<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="catalog">
    <nav class="catalog-utility-links" aria-label="<?= $escape(Translator::t('home.useful_title', 'Корисне')) ?>">
        <a href="/Anabelka/news">
            <?= $escape(Translator::t('home.utility_news', 'Новини')) ?>
        </a>
        <a href="/Anabelka/reviews">
            <?= $escape(Translator::t('home.utility_reviews', 'Відгуки покупців')) ?>
        </a>
        <a href="/Anabelka/gift-certificates">
            <?= $escape(Translator::t('home.utility_gifts', 'Подарункові сертифікати')) ?>
        </a>
    </nav>

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
                    <?php
                    $rootLabelId = 'catalog-adult-root-'
                        . (int) ($category['id'] ?? 0);
                    ?>
                    <article
                        class="catalog-adult-entry"
                        aria-labelledby="<?= $escape($rootLabelId) ?>"
                    >
                        <a
                            href="<?= $escape(AdultAccess::gateUrl($category)) ?>"
                            class="catalog-adult-root"
                        >
                            <span class="catalog-adult-brand">
                                <span
                                    class="catalog-adult-brand-name"
                                    id="<?= $escape($rootLabelId) ?>"
                                >
                                    <?= $escape($category['name'] ?? '') ?>
                                </span>

                                <span
                                    class="catalog-adult-strawberry"
                                    aria-hidden="true"
                                >
                                    <?php require __DIR__ . '/../partials/anabelka-strawberry-icon.php'; ?>
                                </span>
                            </span>

                            <span class="catalog-adult-root-meta" hidden>
                                <span class="catalog-adult-badge">18+</span>

                                <span class="catalog-adult-action">
                                    <?= $escape(
                                        Translator::t(
                                            'public.catalog.adult_enter',
                                            'Увійти до розділу'
                                        )
                                    ) ?>
                                    <span aria-hidden="true">→</span>
                                </span>
                            </span>
                        </a>

                        <?php $renderAdultTree(
                            is_array($category['children'] ?? null)
                                ? $category['children']
                                : []
                        ); ?>
                    </article>
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