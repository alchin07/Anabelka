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
    <link rel="stylesheet" href="/Anabelka/css/catalog.css?v=4">
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
                    <a
                        href="/Anabelka/product/<?= htmlspecialchars($product['slug']) ?>"
                        class="product-card"
                    >
                        <div class="product-image">
                            <?php if (!empty($product['main_image'])): ?>
                                <img
                                    src="<?= htmlspecialchars($product['main_image']) ?>"
                                    alt="<?= htmlspecialchars($product['name']) ?>"
                                >
                            <?php else: ?>
                                <?= htmlspecialchars(
                                    Translator::t('public.catalog.product_photo', 'Фото товару')
                                ) ?>
                            <?php endif; ?>
                        </div>

                        <h3><?= htmlspecialchars($product['name']) ?></h3>

                        <p class="product-price">
                            <?= number_format(
                                Product::getCurrentPrice($product),
                                2,
                                ',',
                                ' '
                            ) ?> €
                        </p>
                    </a>
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

</body>
</html>
