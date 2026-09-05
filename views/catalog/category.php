<?php
PublicInterfaceTranslator::seed();
$currentLanguage = Translator::currentLanguage();
$pageTitle = $category['name'];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLanguage['code'] ?? 'uk') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($category['name']) ?> — Анабелька</title>
    <link rel="stylesheet" href="/Anabelka/css/style.css?=v8">
    <link rel="stylesheet" href="/Anabelka/css/catalog.css?v=6">
</head>
<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="catalog">
    <p style="margin-bottom: 25px;">
        <a href="/Anabelka/catalog" style="color: var(--primary-color);">
            ← <?= htmlspecialchars(
                Translator::t('public.catalog.title', 'Каталог')
            ) ?>
        </a>
    </p>

    <?php if (!empty($children)): ?>
        <section class="catalog-categories">
            <h2><?= htmlspecialchars(
                Translator::t('public.catalog.subcategories', 'Підкатегорії')
            ) ?></h2>

            <div class="category-list">
                <?php foreach ($children as $child): ?>
                    <a
                        href="/Anabelka/catalog/<?= htmlspecialchars($child['slug']) ?>"
                        class="category-item"
                    >
                        <?= htmlspecialchars($child['name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>

    <?php elseif (!empty($products)): ?>
        <section class="catalog-products">
            <h2><?= htmlspecialchars(
                Translator::t('public.catalog.products', 'Товари')
            ) ?></h2>

            <div class="product-grid">
                <?php foreach ($products as $product): ?>
                    <?php
                    $productUrl = '/Anabelka/product/'
                        . rawurlencode((string) $product['slug']);
                    $colorVariants = is_array(
                        $product['color_variants'] ?? null
                    ) ? $product['color_variants'] : [];
                    ?>
                    <article
                        class="product-card"
                        data-product-color-card
                    >
                        <a href="<?= htmlspecialchars($productUrl) ?>" class="product-card-main">
                            <div class="product-image">
                                <?php if (!empty($product['main_image'])): ?>
                                    <img
                                        src="<?= htmlspecialchars($product['main_image']) ?>"
                                        alt="<?= htmlspecialchars($product['name']) ?>"
                                        data-product-card-image
                                    >
                                <?php else: ?>
                                    <?= htmlspecialchars(
                                        Translator::t('public.catalog.product_photo', 'Фото товару')
                                    ) ?>
                                <?php endif; ?>
                            </div>

                            <h3><?= htmlspecialchars($product['name']) ?></h3>
                        </a>

                        <?php if (!empty($colorVariants)): ?>
                            <div
                                class="product-color-swatches"
                                aria-label="<?= htmlspecialchars(
                                    Translator::t('public.catalog.color', 'Колір')
                                ) ?>"
                            >
                                <?php foreach ($colorVariants as $variant): ?>
                                    <?php
                                    $isSelected = (string) ($variant['path'] ?? '')
                                        === (string) ($product['main_image'] ?? '');
                                    $colorLabel = Translator::t(
                                        'public.catalog.color',
                                        'Колір'
                                    ) . ': ' . (string) ($variant['name'] ?? '');
                                    ?>
                                    <button
                                        type="button"
                                        class="product-color-swatch<?= $isSelected ? ' is-active' : '' ?>"
                                        style="--product-color: <?= htmlspecialchars($variant['hex'] ?? '#b8b0bd', ENT_QUOTES, 'UTF-8') ?>"
                                        data-product-color-swatch
                                        data-image-src="<?= htmlspecialchars($variant['path'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                        aria-label="<?= htmlspecialchars($colorLabel, ENT_QUOTES, 'UTF-8') ?>"
                                        aria-pressed="<?= $isSelected ? 'true' : 'false' ?>"
                                        title="<?= htmlspecialchars($variant['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                    ></button>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <a href="<?= htmlspecialchars($productUrl) ?>" class="product-card-price-link">
                            <p class="product-price">
                                <?= number_format(
                                    Product::getCurrentPrice($product),
                                    2,
                                    ',',
                                    ' '
                                ) ?> €
                            </p>
                        </a>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

    <?php else: ?>
        <p><?= htmlspecialchars(
            Translator::t(
                'public.catalog.empty',
                'У цій категорії поки немає товарів.'
            )
        ) ?></p>
    <?php endif; ?>
</main>

<script src="/Anabelka/js/catalog-product-colors.js?v=2"></script>
</body>
</html>
