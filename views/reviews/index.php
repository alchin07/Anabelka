<?php
PublicInterfaceTranslator::seed();
$currentLanguage = $currentLanguage ?? Translator::currentLanguage();
$reviews = is_array($reviews ?? null) ? $reviews : [];
$pageTitle = Translator::t('reviews.title', 'Відгуки покупців');
$escape = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$stars = static function ($rating) {
    $rating = max(1, min(5, (int) $rating));
    return str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);
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
    <link rel="stylesheet" href="/Anabelka/css/reviews.css?v=1">
</head>
<body>
<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="reviews-page">
    <div class="reviews-shell">
        <header class="reviews-heading">
            <h2><?= $escape($pageTitle) ?></h2>
            <p><?= $escape(Translator::t('reviews.intro', 'Свіжі схвалені відгуки покупців про товари Анабельки.')) ?></p>
        </header>

        <?php if (empty($reviews)): ?>
            <div class="reviews-empty">
                <?= $escape(Translator::t('reviews.empty', 'Схвалених відгуків поки немає.')) ?>
            </div>
        <?php else: ?>
            <div class="reviews-list">
                <?php foreach ($reviews as $review): ?>
                    <article class="review-card">
                        <div class="review-card-head">
                            <div>
                                <div class="review-card-author"><?= $escape($review['customer_name'] ?? '') ?></div>
                                <a class="review-card-product" href="/Anabelka/product/<?= rawurlencode((string) ($review['product_slug'] ?? '')) ?>#product-reviews">
                                    <?= $escape($review['product_name'] ?? '') ?>
                                </a>
                            </div>
                            <span class="review-stars" aria-label="<?= $escape('Оцінка: ' . (int) ($review['rating'] ?? 0) . ' з 5') ?>">
                                <?= $escape($stars($review['rating'] ?? 0)) ?>
                            </span>
                        </div>
                        <p class="review-card-body"><?= $escape($review['body'] ?? '') ?></p>
                        <?php if (!empty($review['created_at'])): ?>
                            <time><?= $escape(date('d.m.Y', strtotime((string) $review['created_at']))) ?></time>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
