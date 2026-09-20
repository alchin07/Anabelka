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
</head>
<body>

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

            <section class="home-hero">
                <div class="home-hero-content">
                    <div class="home-eyebrow">
                        <?= $escape(
                            Translator::t('home.hero_eyebrow', 'Анабелька')
                        ) ?>
                    </div>

                    <h1>
                        <?= $escape(
                            Translator::t(
                                'home.hero_title',
                                'Для щоденного комфорту, дому, відпочинку та особливих моментів'
                            )
                        ) ?>
                    </h1>

                    <p class="home-hero-text">
                        <?= $escape(
                            Translator::t(
                                'home.hero_text',
                                'Білизна, панчішно-шкарпеткові вироби, домашній одяг, купальники та інші напрямки в одному магазині.'
                            )
                        ) ?>
                    </p>

                    <div class="home-hero-actions">
                        <a class="home-button is-primary" href="/Anabelka/catalog">
                            <?= $escape(
                                Translator::t(
                                    'home.hero_catalog',
                                    'Перейти до каталогу'
                                )
                            ) ?>
                        </a>
                    </div>
                </div>

                <div class="home-hero-mark" aria-hidden="true">
                    <div class="home-hero-mark-inner">A</div>
                </div>
            </section>

            <section class="home-section">
                <div class="home-section-head">
                    <div class="home-section-title">
                        <h2>
                            <?= $escape(
                                Translator::t(
                                    'home.directions_title',
                                    'Напрямки магазину'
                                )
                            ) ?>
                        </h2>
                        <p>
                            <?= $escape(
                                Translator::t(
                                    'home.directions_text',
                                    'Оберіть потрібний розділ і переходьте до категорій та товарів.'
                                )
                            ) ?>
                        </p>
                    </div>

                    <a class="home-section-link" href="/Anabelka/catalog">
                        <?= $escape(
                            Translator::t('home.all_catalog', 'Увесь каталог')
                        ) ?> →
                    </a>
                </div>

                <?php if (empty($standardDirections)): ?>
                    <div class="home-empty">
                        <?= $escape(
                            Translator::t(
                                'home.empty_directions',
                                'Напрямки магазину ще не налаштовані.'
                            )
                        ) ?>
                    </div>
                <?php else: ?>
                    <div class="home-direction-grid">
                        <?php foreach ($standardDirections as $direction): ?>
                            <?php
                            $directionImage = $assetUrl($direction['image'] ?? '');
                            $directionName = trim((string) ($direction['name'] ?? ''));
                            $directionLetter = function_exists('mb_substr')
                                ? mb_substr($directionName, 0, 1, 'UTF-8')
                                : substr($directionName, 0, 1);
                            ?>

                            <a
                                class="home-direction-card<?= $directionImage === '' ? ' no-image' : '' ?>"
                                href="<?= $escape(Category::catalogUrl($direction)) ?>"
                            >
                                <div class="home-direction-media">
                                    <?php if ($directionImage !== ''): ?>
                                        <img
                                            src="<?= $escape($directionImage) ?>"
                                            alt="<?= $escape($directionName) ?>"
                                            loading="lazy"
                                        >
                                    <?php else: ?>
                                        <div class="home-direction-placeholder">
                                            <?= $escape($directionLetter) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="home-direction-overlay">
                                    <strong><?= $escape($directionName) ?></strong>
                                    <span>
                                        <?= $escape(
                                            Translator::t(
                                                'home.direction_open',
                                                'Відкрити розділ'
                                            )
                                        ) ?> →
                                    </span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <?php if (!empty($adultDirections)): ?>
                <section class="home-adult-section" aria-label="18+">
                    <div class="home-adult-copy">
                        <span class="home-adult-badge">18+</span>

                        <div>
                            <h2>
                                <?= $escape(
                                    Translator::t(
                                        'home.adult_entry_title',
                                        'Інтимні товари'
                                    )
                                ) ?>
                            </h2>

                            <p>
                                <?= $escape(
                                    Translator::t(
                                        'home.adult_entry_text',
                                        'Окремий приватний розділ для повнолітніх.'
                                    )
                                ) ?>
                            </p>
                        </div>
                    </div>

                    <div class="home-adult-links">
                        <?php foreach ($adultDirections as $direction): ?>
                            <a
                                href="<?= $escape(AdultAccess::gateUrl($direction)) ?>"
                            >
                                <?= $escape(
                                    Translator::t(
                                        'home.adult_open',
                                        'Перейти до розділу 18+'
                                    )
                                ) ?> →
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php foreach (($homeBlocks['main'] ?? []) as $homeBlock): ?>
                <?php
                $homeBlockType = (string) ($homeBlock['block_type'] ?? '');
                $homeBlockKey = (string) ($homeBlock['system_key'] ?? '');
                $homeBlockPayload = is_array($homeBlockData[$homeBlockKey] ?? null)
                    ? $homeBlockData[$homeBlockKey]
                    : [];
                ?>

                <?php if ($homeBlockType === 'product_collection'): ?>
                    <?php require __DIR__ . '/home/blocks/product-collection.php'; ?>
                <?php endif; ?>
            <?php endforeach; ?>

            <details class="home-useful-menu">
                <summary>
                    <?= $escape(
                        Translator::t('home.useful_title', 'Корисне')
                    ) ?>
                </summary>
                <nav aria-label="<?= $escape(Translator::t('home.useful_title', 'Корисне')) ?>">
                    <a href="/Anabelka/news">
                        <?= $escape(Translator::t('home.utility_news', 'Новини')) ?>
                    </a>
                    <a href="/Anabelka/reviews">
                        <?= $escape(Translator::t('home.utility_reviews', 'Відгуки покупців')) ?>
                    </a>
                    <a href="/Anabelka/gift-certificates">
                        <?= $escape(Translator::t('home.utility_gifts', 'Подарункові сертифікати')) ?>
                    </a>
                </nav>
            </details>

            <section class="home-info-row" aria-label="Інформація магазину">
                <div class="home-info-item">
                    <?= $escape(
                        Translator::t('home.footer_delivery', 'Доставка')
                    ) ?>
                </div>
                <div class="home-info-item">
                    <?= $escape(
                        Translator::t('home.footer_payment', 'Оплата')
                    ) ?>
                </div>
                <div class="home-info-item">
                    <?= $escape(
                        Translator::t('home.footer_returns', 'Повернення')
                    ) ?>
                </div>
                <div class="home-info-item">
                    <?= $escape(
                        Translator::t('home.footer_contacts', 'Контакти')
                    ) ?>
                </div>
            </section>
        </div>

        <?php require __DIR__ . '/home/partials/right-rail.php'; ?>
    </div>
</main>
</body>
</html>
