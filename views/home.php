<?php
PublicInterfaceTranslator::seed();
HomeInterfaceTranslator::seed();
$currentLanguage = $currentLanguage
    ?? Translator::currentLanguage();
$pageTitle = '';
$directions = is_array($directions ?? null) ? $directions : [];
$navigationTree = is_array($navigationTree ?? null) ? $navigationTree : [];
$homeBlocks = is_array($homeBlocks ?? null) ? $homeBlocks : [];
$homeBlockData = is_array($homeBlockData ?? null) ? $homeBlockData : [];
$builderPreview = !empty($builderPreview);
$standardDirections = [];
$adultDirections = [];

foreach ($directions as $direction) {
    if (!empty($direction['is_adult'])) {
        $adultDirections[] = $direction;
    } else {
        $standardDirections[] = $direction;
    }
}

$escape = function ($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
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
    <title>Анабелька</title>
    <link rel="stylesheet" href="/Anabelka/css/style.css?v=9">
    <link rel="stylesheet" href="/Anabelka/css/catalog.css?v=4">
    <link rel="stylesheet" href="/Anabelka/css/home.css?v=5">
    <link rel="stylesheet" href="/Anabelka/css/home-right-rail.css?v=1">
    <?php if ($builderPreview): ?>
        <link rel="stylesheet" href="/Anabelka/css/anabelka-builder-preview.css?v=1">
    <?php endif; ?>
</head>
<body<?= $builderPreview
    ? ' class="anabelka-builder-preview" data-anabelka-builder-preview="home"'
    : '' ?>>

<?php require __DIR__ . '/partials/header.php'; ?>

<main class="home-page">
    <div class="home-shell">
        <div class="home-primary-content">
            <nav class="home-department-nav" aria-label="Напрямки магазину">
                <a class="home-department-nav-main" href="/Anabelka/catalog">
                    <?= $escape(
                        Translator::t('home.nav_catalog', 'Каталог')
                    ) ?>
                </a>

                <?php foreach ($standardDirections as $direction): ?>
                    <a
                        href="<?= $escape(Category::catalogUrl($direction)) ?>"
                    >
                        <?= $escape($direction['name'] ?? '') ?>
                    </a>
                <?php endforeach; ?>

                <?php foreach ($adultDirections as $direction): ?>
                    <a
                        class="home-department-nav-adult"
                        href="<?= $escape(AdultAccess::gateUrl($direction)) ?>"
                    >
                        <span>18+</span>
                        <?= $escape($direction['name'] ?? '') ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php foreach (($homeBlocks['main'] ?? []) as $homeBlock): ?>
                <?php
                $homeBlockType = (string) ($homeBlock['block_type'] ?? '');
                $homeBlockKey = (string) ($homeBlock['system_key'] ?? '');
                $homeBlockPayload = is_array($homeBlockData[$homeBlockKey] ?? null)
                    ? $homeBlockData[$homeBlockKey]
                    : [];
                ?>

                <?php if ($builderPreview): ?>
                    <div
                        class="anabelka-builder-preview-block"
                        data-anabelka-builder-block-id="<?= (int) ($homeBlock['id'] ?? 0) ?>"
                        data-anabelka-builder-zone="main"
                    >
                <?php endif; ?>

                <?php if ($homeBlockType === 'hero'): ?>
                    <?php require __DIR__ . '/home/blocks/hero.php'; ?>
                <?php elseif ($homeBlockType === 'directions'): ?>
                    <?php require __DIR__ . '/home/blocks/directions.php'; ?>
                <?php elseif ($homeBlockType === 'adult_entry'): ?>
                    <?php require __DIR__ . '/home/blocks/adult-entry.php'; ?>
                <?php elseif ($homeBlockType === 'product_collection'): ?>
                    <?php require __DIR__ . '/home/blocks/product-collection.php'; ?>
                <?php elseif ($homeBlockType === 'useful'): ?>
                    <?php require __DIR__ . '/home/blocks/useful.php'; ?>
                <?php elseif ($homeBlockType === 'info_row'): ?>
                    <?php require __DIR__ . '/home/blocks/info-row.php'; ?>
                <?php endif; ?>

                <?php if ($builderPreview): ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>

        </div>

        <?php require __DIR__ . '/home/partials/right-rail.php'; ?>
    </div>
</main>
<?php if ($builderPreview): ?>
    <script src="/Anabelka/js/anabelka-builder-preview.js?v=1"></script>
<?php endif; ?>
</body>
</html>
