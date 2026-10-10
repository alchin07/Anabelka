<?php
$homeReviews = is_array($homeBlockPayload['items'] ?? null)
    ? $homeBlockPayload['items']
    : [];
?>
<section
    class="home-rail-card home-rail-reviews"
    data-home-block="<?= $railEscape($homeBlockKey) ?>"
>
    <div class="home-rail-card-head">
        <h2><?= $railEscape(Translator::t('home.reviews_title', 'Свіжі відгуки')) ?></h2>
        <a href="/Anabelka/reviews"><?= $railEscape(Translator::t('home.all_reviews', 'Усі відгуки')) ?> →</a>
    </div>

    <?php if (empty($homeReviews)): ?>
        <p class="home-rail-empty"><?= $railEscape(Translator::t('home.reviews_empty', 'Схвалених відгуків поки немає.')) ?></p>
    <?php else: ?>
        <div class="home-rail-list">
            <?php foreach ($homeReviews as $review): ?>
                <a
                    class="home-rail-review-item"
                    href="/Anabelka/product/<?= rawurlencode((string) ($review['product_slug'] ?? '')) ?>#product-reviews"
                >
                    <span class="home-rail-review-author"><?= $railEscape($review['customer_name'] ?? '') ?></span>
                    <span class="home-rail-review-stars"><?= $railEscape($railStars($review['rating'] ?? 0)) ?></span>
                    <strong><?= $railEscape($review['product_name'] ?? '') ?></strong>
                    <span class="home-rail-review-text"><?= $railEscape($review['body'] ?? '') ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
