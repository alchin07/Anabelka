<?php
$currentLanguage = $currentLanguage ?? Translator::currentLanguage();
$newsItems = is_array($newsItems ?? null) ? $newsItems : [];
$pageTitle = Translator::t('news.title', 'Новини');
$escape = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$assetUrl = static function ($path) {
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path) || strpos($path, '/Anabelka/') === 0) {
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
    <link rel="stylesheet" href="/Anabelka/css/news.css?v=1">
</head>
<body>
<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="news-page">
    <div class="news-shell">
        <header class="news-heading">
            <h2><?= $escape($pageTitle) ?></h2>
            <p><?= $escape(Translator::t('news.intro', 'Останні новини, оновлення та події Анабельки.')) ?></p>
        </header>

        <?php if (empty($newsItems)): ?>
            <div class="news-empty">
                <?= $escape(Translator::t('news.empty', 'Опублікованих новин поки немає.')) ?>
            </div>
        <?php else: ?>
            <div class="news-list">
                <?php foreach ($newsItems as $news): ?>
                    <?php
                    $image = $assetUrl($news['image_path'] ?? '');
                    $date = !empty($news['published_at'])
                        ? date('d.m.Y', strtotime((string) $news['published_at']))
                        : '';
                    ?>
                    <article class="news-card">
                        <?php if ($image !== ''): ?>
                            <a class="news-card-media" href="/Anabelka/news/<?= rawurlencode((string) ($news['slug'] ?? '')) ?>">
                                <img src="<?= $escape($image) ?>" alt="<?= $escape($news['title'] ?? '') ?>" loading="lazy">
                            </a>
                        <?php endif; ?>
                        <div class="news-card-body">
                            <?php if ($date !== ''): ?><time><?= $escape($date) ?></time><?php endif; ?>
                            <h3>
                                <a href="/Anabelka/news/<?= rawurlencode((string) ($news['slug'] ?? '')) ?>">
                                    <?= $escape($news['title'] ?? '') ?>
                                </a>
                            </h3>
                            <?php if (!empty($news['summary'])): ?>
                                <p><?= $escape($news['summary']) ?></p>
                            <?php endif; ?>
                            <a class="news-read-more" href="/Anabelka/news/<?= rawurlencode((string) ($news['slug'] ?? '')) ?>">
                                <?= $escape(Translator::t('news.read_more', 'Читати далі')) ?> →
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
