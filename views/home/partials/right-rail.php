<?php
$rightRailBlocks = is_array($homeBlocks['right_rail'] ?? null)
    ? $homeBlocks['right_rail']
    : [];

if (empty($rightRailBlocks)) {
    return;
}

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

    return str_repeat('★', $rating)
        . str_repeat('☆', 5 - $rating);
};
?>
<aside
    class="home-right-rail"
    aria-label="<?= $railEscape(
        Translator::t('home.useful_title', 'Корисне')
    ) ?>"
>
    <?php foreach ($rightRailBlocks as $homeBlock): ?>
        <?php
        $homeBlockType = (string) ($homeBlock['block_type'] ?? '');
        $homeBlockKey = (string) ($homeBlock['system_key'] ?? '');
        $homeBlockPayload = is_array($homeBlockData[$homeBlockKey] ?? null)
            ? $homeBlockData[$homeBlockKey]
            : [];
        ?>

        <?php if (!empty($builderPreview)): ?>
            <div
                class="anabelka-builder-preview-block"
                data-anabelka-builder-block-id="<?= (int) ($homeBlock['id'] ?? 0) ?>"
                data-anabelka-builder-zone="right_rail"
            >
        <?php endif; ?>

        <?php if ($homeBlockType === 'news'): ?>
            <?php require __DIR__ . '/../blocks/news.php'; ?>
        <?php elseif ($homeBlockType === 'reviews'): ?>
            <?php require __DIR__ . '/../blocks/reviews.php'; ?>
        <?php elseif ($homeBlockType === 'gift_certificate'): ?>
            <?php require __DIR__ . '/../blocks/gift-certificate.php'; ?>
        <?php endif; ?>

        <?php if (!empty($builderPreview)): ?>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
</aside>
