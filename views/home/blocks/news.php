<?php
$homeNews = is_array($homeBlockPayload['items'] ?? null)
    ? $homeBlockPayload['items']
    : [];
?>
<section
    class="home-rail-card home-rail-news"
    data-home-block="<?= $railEscape($homeBlockKey) ?>"
>
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
                <a
                    class="home-rail-news-item"
                    href="/Anabelka/news/<?= rawurlencode((string) ($news['slug'] ?? '')) ?>"
                >
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
