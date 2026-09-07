<?php
FavoriteInterfaceTranslator::seed();
$currentLanguage = $currentLanguage
    ?? Translator::currentLanguage();
$products = is_array($products ?? null) ? $products : [];
$pageTitle = $pageTitle
    ?? Translator::t('favorite.title', 'Обране');

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
    <link rel="stylesheet" href="/Anabelka/css/catalog.css?v=6">
</head>
<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="favorite-page">
    <div class="favorite-shell">
        <div class="favorite-page-head">
            <div>
                <h2><?= $escape(
                    Translator::t('favorite.title', 'Обране')
                ) ?></h2>
                <p>
                    <?= count($products) ?>
                    <?= $escape(
                        Translator::t('public.catalog.products', 'Товари')
                    ) ?>
                </p>
            </div>
        </div>

        <div
            class="favorite-empty"
            data-favorite-empty
            <?= empty($products) ? '' : 'hidden' ?>
        >
            <h3><?= $escape(
                Translator::t(
                    'favorite.empty',
                    'В обраному поки немає товарів.'
                )
            ) ?></h3>
            <p><?= $escape(
                Translator::t(
                    'favorite.empty_text',
                    'Додавайте товари сердечком, щоб повернутися до них пізніше.'
                )
            ) ?></p>
            <a href="/Anabelka/catalog">
                <?= $escape(
                    Translator::t(
                        'favorite.go_catalog',
                        'Перейти до каталогу'
                    )
                ) ?>
            </a>
        </div>

        <div
            class="favorite-page-grid"
            data-favorite-page-grid
            <?= empty($products) ? 'hidden' : '' ?>
        >
            <?php foreach ($products as $product): ?>
                <?php
                $productId = (int) ($product['id'] ?? 0);
                $productImage = $assetUrl($product['main_image'] ?? '');
                $currentPrice = Product::getCurrentPrice($product);
                $oldPrice = (float) ($product['old_price'] ?? 0);
                $removeLabel = Translator::t(
                    'favorite.remove',
                    'Видалити з обраного'
                );
                ?>
                <article
                    class="favorite-page-card"
                    data-favorite-page-card
                >
                    <button
                        type="button"
                        class="favorite-toggle is-active"
                        data-favorite-toggle
                        data-product-id="<?= $productId ?>"
                        aria-pressed="true"
                        aria-label="<?= $escape($removeLabel) ?>"
                        title="<?= $escape($removeLabel) ?>"
                    ></button>

                    <a
                        class="favorite-page-link"
                        href="/Anabelka/product/<?= rawurlencode((string) ($product['slug'] ?? '')) ?>"
                    >
                        <div class="favorite-page-image">
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

                        <div class="favorite-page-body">
                            <h3><?= $escape($product['name'] ?? '') ?></h3>

                            <?php if (!empty($product['sku'])): ?>
                                <small>SKU: <?= $escape($product['sku']) ?></small>
                            <?php endif; ?>

                            <div class="favorite-page-price">
                                <strong>
                                    <?= number_format(
                                        (float) $currentPrice,
                                        2,
                                        ',',
                                        ' '
                                    ) ?> €
                                </strong>

                                <?php if ($oldPrice > (float) $currentPrice): ?>
                                    <del>
                                        <?= number_format(
                                            $oldPrice,
                                            2,
                                            ',',
                                            ' '
                                        ) ?> €
                                    </del>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</main>

</body>
</html>
