<?php
PublicInterfaceTranslator::seed();
$currentLanguage = Translator::currentLanguage();
$pageTitle = Translator::t('public.catalog.title', 'Каталог');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLanguage['code'] ?? 'uk') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> — Анабелька</title>
    <link rel="stylesheet" href="/Anabelka/css/style.css?=v8">
    <link rel="stylesheet" href="/Anabelka/css/catalog.css?v=3">
</head>
<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="catalog">
    <section class="catalog-categories">
        <h2><?= htmlspecialchars(
            Translator::t('public.catalog.categories', 'Категорії')
        ) ?></h2>

        <div class="category-list">
            <?php foreach ($categories as $category): ?>
                <a
                    href="/Anabelka/catalog/<?= htmlspecialchars($category['slug']) ?>"
                    class="category-item"
                >
                    <?= htmlspecialchars($category['name']) ?>
                </a>
            <?php endforeach; ?>
        </div>
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
