<?php
$currentLanguage = $currentLanguage ?? Translator::currentLanguage();
$collection = is_array($collection ?? null) ? $collection : [];
$collectionPath = (string) ($collectionPath ?? '/Anabelka/new');
$documentTitle = trim((string) ($pageTitle ?? ''));
$isAdultCatalogContext = false;
$escape = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$pageTitle = '';
?>
<!DOCTYPE html>
<html lang="<?= $escape($currentLanguage['code'] ?? 'uk') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $escape($documentTitle) ?> — Анабелька</title>
    <link rel="stylesheet" href="/Anabelka/css/style.css?v=9">
    <link rel="stylesheet" href="/Anabelka/css/catalog.css?v=4">
    <link rel="stylesheet" href="/Anabelka/css/storefront-pages.css?v=1">
</head>
<body>
<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="storefront-page">
    <section class="storefront-page-shell" aria-labelledby="storefront-page-title">
        <header class="storefront-page-head">
            <h1 id="storefront-page-title"><?= $escape($documentTitle) ?></h1>
            <p>
                <?= $escape(
                    Translator::t(
                        'storefront.new.intro',
                        'Найновіші товари Анабельки без розділів 18+.'
                    )
                ) ?>
            </p>
        </header>

        <?php if (empty($collection['items'])): ?>
            <div class="storefront-empty">
                <?= $escape(
                    Translator::t(
                        'storefront.new.empty',
                        'Нових товарів поки немає.'
                    )
                ) ?>
            </div>
        <?php else: ?>
            <div class="storefront-product-grid">
                <?php foreach ($collection['items'] as $collectionItem): ?>
                    <?php require __DIR__ . '/partials/product-card.php'; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php require __DIR__ . '/partials/pagination.php'; ?>
    </section>
</main>
</body>
</html>
