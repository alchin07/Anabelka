<?php
ContentInterfaceTranslator::seed();
$reviews = is_array($reviews ?? null) ? $reviews : [];
$canReview = !empty($canReview);
$reviewCsrfToken = (string) ($reviewCsrfToken ?? '');
$reviewFlash = is_array($reviewFlash ?? null) ? $reviewFlash : null;
$reviewEscape = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$reviewStars = static function ($rating) {
    $rating = max(1, min(5, (int) $rating));
    return str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);
};
?>
<section id="product-reviews" class="product-reviews-section" aria-labelledby="product-reviews-title">
    <div class="product-reviews-inner">
        <header class="product-reviews-heading">
            <h2 id="product-reviews-title">
                <?= $reviewEscape(Translator::t('reviews.product_title', 'Відгуки про товар')) ?>
            </h2>
            <p>
                <?= $reviewEscape(Translator::t('reviews.product_intro', 'Відгуки публікуються після модерації.')) ?>
            </p>
        </header>

        <?php if ($reviewFlash): ?>
            <div class="product-review-flash <?= ($reviewFlash['type'] ?? '') === 'success' ? 'is-success' : 'is-error' ?>" role="status">
                <?= $reviewEscape($reviewFlash['message'] ?? '') ?>
            </div>
        <?php endif; ?>

        <?php if (empty($reviews)): ?>
            <div class="reviews-empty">
                <?= $reviewEscape(Translator::t('reviews.product_empty', 'Схвалених відгуків про цей товар поки немає.')) ?>
            </div>
        <?php else: ?>
            <div class="product-reviews-list">
                <?php foreach ($reviews as $review): ?>
                    <article class="review-card">
                        <div class="review-card-head">
                            <div>
                                <div class="review-card-author">
                                    <?= $reviewEscape($review['customer_name'] ?? '') ?>
                                </div>
                                <?php if (!empty($review['created_at'])): ?>
                                    <time><?= $reviewEscape(date('d.m.Y', strtotime((string) $review['created_at']))) ?></time>
                                <?php endif; ?>
                            </div>
                            <span class="review-stars" aria-label="<?= $reviewEscape('Оцінка: ' . (int) ($review['rating'] ?? 0) . ' з 5') ?>">
                                <?= $reviewEscape($reviewStars($review['rating'] ?? 0)) ?>
                            </span>
                        </div>
                        <p class="review-card-body"><?= $reviewEscape($review['body'] ?? '') ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($canReview): ?>
            <form
                class="product-review-form"
                action="/Anabelka/product/<?= rawurlencode((string) ($product['slug'] ?? '')) ?>/reviews"
                method="post"
            >
                <input type="hidden" name="_csrf" value="<?= $reviewEscape($reviewCsrfToken) ?>">

                <label>
                    <span><?= $reviewEscape(Translator::t('reviews.rating', 'Оцінка')) ?></span>
                    <select name="rating" required>
                        <option value="5">5 — ★★★★★</option>
                        <option value="4">4 — ★★★★☆</option>
                        <option value="3">3 — ★★★☆☆</option>
                        <option value="2">2 — ★★☆☆☆</option>
                        <option value="1">1 — ★☆☆☆☆</option>
                    </select>
                </label>

                <label>
                    <span><?= $reviewEscape(Translator::t('reviews.body', 'Ваш відгук')) ?></span>
                    <textarea name="body" minlength="3" maxlength="1500" required></textarea>
                </label>

                <button type="submit">
                    <?= $reviewEscape(Translator::t('reviews.submit', 'Надіслати на модерацію')) ?>
                </button>
            </form>
        <?php elseif (CustomerAccount::currentId() <= 0): ?>
            <p style="margin-top:16px">
                <a class="review-card-product" href="/Anabelka/login">
                    <?= $reviewEscape(Translator::t('reviews.login_to_review', 'Увійдіть, щоб залишити відгук')) ?>
                </a>
            </p>
        <?php endif; ?>
    </div>
</section>
