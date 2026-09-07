<?php
PublicInterfaceTranslator::seed();
SearchInterfaceTranslator::seed();
$currentLanguage = $currentLanguage
    ?? Translator::currentLanguage();
$query = CatalogSearch::normalizeQuery($query ?? '');
$products = is_array($products ?? null) ? $products : [];
$categories = is_array($categories ?? null) ? $categories : [];
$pageTitle = Translator::t('search.title', 'Пошук');

$escape = function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$assetUrl = function ($path) {
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    if (strpos($path, '/Anabelka/') === 0) {
        return $path;
    }
    return '/Anabelka/' . ltrim($path, '/');
};
?>
<!DOCTYPE html>
<html lang="<?= $escape($currentLanguage['code'] ?? 'uk') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $escape($pageTitle) ?> — Анабелька</title>
    <link rel="stylesheet" href="/Anabelka/css/style.css?v=9">
    <link rel="stylesheet" href="/Anabelka/css/catalog.css?v=4">
    <link rel="stylesheet" href="/Anabelka/css/search.css?v=1">
</head>
<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="search-page">
    <div class="search-shell">
        <section class="search-heading">
            <h2><?= $escape(Translator::t('search.title', 'Пошук')) ?></h2>

            <?php if ($query !== ''): ?>
                <p>
                    <?= $escape(Translator::t('search.results_for', 'Результати пошуку для')) ?>
                    <strong>«<?= $escape($query) ?>»</strong>
                </p>
                <span class="search-total">
                    <?= $escape(Translator::t('search.found', 'Знайдено')) ?>:
                    <?= count($products) + count($categories) ?>
                </span>
            <?php else: ?>
                <p><?= $escape(Translator::t('search.start', 'Введіть назву товару, категорію або SKU.')) ?></p>
            <?php endif; ?>
        </section>

        <?php if ($query !== '' && empty($products) && empty($categories)): ?>
            <div class="search-empty">
                <?= $escape(Translator::t('search.empty', 'Нічого не знайдено. Спробуйте інший запит.')) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($categories)): ?>
            <section class="search-section">
                <h2><?= $escape(Translator::t('search.categories', 'Категорії')) ?></h2>
                <div class="search-category-grid">
                    <?php foreach ($categories as $category): ?>
                        <a
                            class="search-category-card"
                            href="/Anabelka/catalog/<?= rawurlencode((string) ($category['slug'] ?? '')) ?>"
                        >
                            <strong><?= $escape($category['name'] ?? '') ?></strong>
                            <?php if (!empty($category['description'])): ?>
                                <span><?= $escape($category['description']) ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!empty($products)): ?>
            <section class="search-section">
                <h2><?= $escape(Translator::t('search.products', 'Товари')) ?></h2>
                <div class="search-product-grid">
                    <?php foreach ($products as $product): ?>
                        <?php
                        $productImage = $assetUrl($product['main_image'] ?? '');
                        $currentPrice = Product::getCurrentPrice($product);
                        $oldPrice = (float) ($product['old_price'] ?? 0);
                        $variants = is_array($product['color_variants'] ?? null)
                            ? $product['color_variants']
                            : [];
                        ?>
                        <a
                            class="search-product-card"
                            href="/Anabelka/product/<?= rawurlencode((string) ($product['slug'] ?? '')) ?>"
                        >
                            <div class="search-product-image">
                                <?php if ($productImage !== ''): ?>
                                    <img
                                        src="<?= $escape($productImage) ?>"
                                        alt="<?= $escape($product['name'] ?? '') ?>"
                                        loading="lazy"
                                    >
                                <?php else: ?>
                                    <span>Анабелька</span>
                                <?php endif; ?>
                            </div>

                            <div class="search-product-body">
                                <h3><?= $escape($product['name'] ?? '') ?></h3>

                                <?php if (!empty($product['sku'])): ?>
                                    <small>SKU: <?= $escape($product['sku']) ?></small>
                                <?php endif; ?>

                                <?php if (!empty($variants)): ?>
                                    <div class="search-product-colors" aria-hidden="true">
                                        <?php foreach (array_slice($variants, 0, 6) as $variant): ?>
                                            <?php
                                            $hex = strtolower(trim((string) ($variant['hex'] ?? ($variant['color_hex'] ?? ''))));
                                            if (!preg_match('/^#[0-9a-f]{6}$/', $hex)) {
                                                $hex = '#b8b0bd';
                                            }
                                            ?>
                                            <span style="--search-color:<?= $escape($hex) ?>"></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <div class="search-product-price">
                                    <strong><?= number_format((float) $currentPrice, 2, ',', ' ') ?> €</strong>
                                    <?php if ($oldPrice > (float) $currentPrice): ?>
                                        <del><?= number_format($oldPrice, 2, ',', ' ') ?> €</del>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>
</main>

</body>
</html>
