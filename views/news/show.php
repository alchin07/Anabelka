<?php
$currentLanguage = $currentLanguage ?? Translator::currentLanguage();
$news = is_array($news ?? null) ? $news : [];
$pageTitle = (string) ($news['title'] ?? Translator::t('news.title', 'Новини'));
$escape = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$assetPath = trim((string) ($news['image_path'] ?? ''));
if ($assetPath !== '' && !preg_match('#^https?://#i', $assetPath) && strpos($assetPath, '/Anabelka/') !== 0) {
    $assetPath = '/Anabelka/' . ltrim($assetPath, '/');
}
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
    <article class="news-article">
        <a class="news-back" href="/Anabelka/news">← <?= $escape(Translator::t('news.back', 'Усі новини')) ?></a>
        <?php if (!empty($news['published_at'])): ?>
            <time><?= $escape(date('d.m.Y', strtotime((string) $news['published_at']))) ?></time>
        <?php endif; ?>
        <h2><?= $escape($news['title'] ?? '') ?></h2>
        <?php if (!empty($news['summary'])): ?>
            <p class="news-article-summary"><?= $escape($news['summary']) ?></p>
        <?php endif; ?>
        <?php if ($assetPath !== ''): ?>
            <img class="news-article-image" src="<?= $escape($assetPath) ?>" alt="<?= $escape($news['title'] ?? '') ?>">
        <?php endif; ?>
        <div class="news-article-body"><?= nl2br($escape($news['body'] ?? '')) ?></div>
    </article>
</main>
</body>
</html>
