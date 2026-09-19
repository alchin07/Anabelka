<?php
$homeNews = is_array($homeNews ?? null) ? $homeNews : [];
$homeReviews = is_array($homeReviews ?? null) ? $homeReviews : [];
$railEscape = isset($escape) && is_callable($escape)
    ? $escape
    : static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$railAssetUrl = isset($assetUrl) && is_callable($assetUrl)
    ? $assetUrl
    : static function ($path) {
        $path = trim((string) $path);
        if ($path === '' || preg_match('#^https?://#i', $path)) {
            return $path;
        }
        return strpos($path, '/Anabelka/') === 0
            ? $path
            : '/Anabelka/' . ltrim($path, '/');
    };
$railStars = static function ($rating) {
    $rating = max(1, min(5, (int) $rating));
    return str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);
};
?>
<aside class="home-right-rail" aria-label="<?= $railEscape(Translator::t('home.useful_title', 'Корисне')) ?>">
    <section class="home-rail-card home-rail-news">
        <div class="home-rail-card-head">
            <h2><?= $railEscape(Translator::t('home.news_title', 'Новини Анабельки')) ?></h2>
            <a href="/Anabelka/news"><?= $railEscape(Translator::t('home.all_news', 'Усі новини')) ?> →</a>
        </div>

        <?php if (empty($homeNews)): ?>
            <p class="home-rail-empty"><?= $railEscape(Translator::t('home.news_empty', 'Новин поки немає.')) ?></p>
        <?php else: ?>
            <div class="home-rail-list">
                <?php foreach ($homeNews as $news): ?>
                    <?php $newsImage = $railAssetUrl($news['image_path'] ?? ''); ?>
                    <a class="home-rail-news-item" href="/Anabelka/news/<?= rawurlencode((string) ($news['slug'] ?? '')) ?>">
                        <?php if ($newsImage !== ''): ?>
                            <img src="<?= $railEscape($newsImage) ?>" alt="" loading="lazy">
                        <?php endif; ?>
                        <span>
                            <?php if (!empty($news['published_at'])): ?>
                                <time><?= $railEscape(date('d.m.Y', strtotime((string) $news['published_at']))) ?></time>
                            <?php endif; ?>
                            <strong><?= $railEscape($news['title'] ?? '') ?></strong>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="home-rail-card home-rail-reviews">
        <div class="home-rail-card-head">
            <h2><?= $railEscape(Translator::t('home.reviews_title', 'Свіжі відгуки')) ?></h2>
            <a href="/Anabelka/reviews"><?= $railEscape(Translator::t('home.all_reviews', 'Усі відгуки')) ?> →</a>
        </div>

        <?php if (empty($homeReviews)): ?>
            <p class="home-rail-empty"><?= $railEscape(Translator::t('home.reviews_empty', 'Схвалених відгуків поки немає.')) ?></p>
        <?php else: ?>
            <div class="home-rail-list">
                <?php foreach ($homeReviews as $review): ?>
                    <a class="home-rail-review-item" href="/Anabelka/product/<?= rawurlencode((string) ($review['product_slug'] ?? '')) ?>#product-reviews">
                        <span class="home-rail-review-author"><?= $railEscape($review['customer_name'] ?? '') ?></span>
                        <span class="home-rail-review-stars"><?= $railEscape($railStars($review['rating'] ?? 0)) ?></span>
                        <strong><?= $railEscape($review['product_name'] ?? '') ?></strong>
                        <span class="home-rail-review-text"><?= $railEscape($review['body'] ?? '') ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="home-rail-card home-rail-gift">
        <div class="home-rail-gift-mark" aria-hidden="true">A</div>
        <h2><?= $railEscape(Translator::t('home.gift_title', 'Подарунковий сертифікат')) ?></h2>
        <p><?= $railEscape(Translator::t('home.gift_text', 'Готуємо електронний сертифікат Анабельки для подарунка іншій людині.')) ?></p>
        <a href="/Anabelka/gift-certificates">
            <?= $railEscape(Translator::t('home.gift_more', 'Дізнатися більше')) ?> →
        </a>
    </section>
</aside>
