<?php
$collectionItem = is_array($collectionItem ?? null)
    ? $collectionItem
    : [];
$escapeCard = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$assetUrlCard = static function ($path) {
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

$productName = trim((string) ($collectionItem['name'] ?? ''));
$productSlug = trim((string) ($collectionItem['slug'] ?? ''));
$productHref = '/Anabelka/product/' . rawurlencode($productSlug);
$productImage = $assetUrlCard($collectionItem['main_image'] ?? '');
$currentPrice = (float) ($collectionItem['current_price'] ?? 0);
$oldPrice = (float) ($collectionItem['old_price'] ?? 0);
$discountPercent = $collectionItem['display_discount_percent'] ?? null;
$colorVariants = is_array($collectionItem['color_variants'] ?? null)
    ? $collectionItem['color_variants']
    : [];
?>
<article class="storefront-product-card">
    <a
        class="storefront-product-link"
        href="<?= $escapeCard($productHref) ?>"
        aria-label="<?= $escapeCard($productName) ?>"
    >
        <div class="storefront-product-image">
            <?php if ($productImage !== ''): ?>
                <img
                    src="<?= $escapeCard($productImage) ?>"
                    alt="<?= $escapeCard($productName) ?>"
                    loading="lazy"
                >
            <?php else: ?>
                <span>Анабелька</span>
            <?php endif; ?>

            <?php if ($discountPercent !== null && (float) $discountPercent > 0): ?>
                <?php
                $discountLabel = rtrim(
                    rtrim(
                        number_format((float) $discountPercent, 2, '.', ''),
                        '0'
                    ),
                    '.'
                );
                ?>
                <span class="storefront-product-discount">
                    −<?= $escapeCard($discountLabel) ?>%
                </span>
            <?php endif; ?>
        </div>

        <div class="storefront-product-body">
            <h2><?= $escapeCard($productName) ?></h2>

            <?php if (!empty($colorVariants)): ?>
                <div class="storefront-product-colors" aria-hidden="true">
                    <?php foreach (array_slice($colorVariants, 0, 8) as $variant): ?>
                        <?php
                        $hex = strtolower(trim((string) ($variant['hex'] ?? '')));
                        if (!preg_match('/^#[0-9a-f]{6}$/i', $hex)) {
                            $hex = '#b8b0bd';
                        }
                        ?>
                        <span
                            class="storefront-product-color"
                            style="background:<?= $escapeCard($hex) ?>"
                        ></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="storefront-product-price">
                <strong>
                    <?= number_format($currentPrice, 2, ',', ' ') ?> €
                </strong>

                <?php if ($oldPrice > $currentPrice): ?>
                    <del><?= number_format($oldPrice, 2, ',', ' ') ?> €</del>
                <?php endif; ?>
            </div>
        </div>
    </a>
</article>
