<?php
PublicInterfaceTranslator::seed();
HomeInterfaceTranslator::seed();
$currentLanguage = $currentLanguage
    ?? Translator::currentLanguage();
$pageTitle = '';
$directions = is_array($directions ?? null) ? $directions : [];
$navigationTree = is_array($navigationTree ?? null) ? $navigationTree : [];
$latestProducts = is_array($latestProducts ?? null) ? $latestProducts : [];
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

$renderSidebarNodes = null;
$renderSidebarNodes = function (array $nodes, $level = 1) use (&$renderSidebarNodes, $escape) {
    foreach ($nodes as $node) {
        $id = (int) ($node['id'] ?? 0);
        $children = is_array($node['children'] ?? null)
            ? $node['children']
            : [];
        $hasChildren = !empty($children);
        $isAdultRoot = !empty($node['is_adult'])
            && (int) ($node['parent_id'] ?? 0) === 0;
        $slug = (string) ($node['slug'] ?? '');
        $href = $isAdultRoot
            ? '/Anabelka/18-plus/' . rawurlencode($slug)
            : '/Anabelka/catalog/' . rawurlencode($slug);
        $childrenId = 'home-sidebar-children-' . $id;
        ?>
        <div
            class="home-sidebar-node<?= $isAdultRoot ? ' is-adult-root' : '' ?>"
            data-home-sidebar-node
            data-level="<?= (int) $level ?>"
        >
            <div class="home-sidebar-row" data-level="<?= (int) $level ?>">
                <?php if ($hasChildren): ?>
                    <button
                        type="button"
                        class="home-sidebar-toggle"
                        data-home-sidebar-toggle="<?= $id ?>"
                        aria-expanded="true"
                        aria-controls="<?= $escape($childrenId) ?>"
                        aria-label="<?= $escape($node['name'] ?? '') ?>"
                    >
                        <span class="home-sidebar-chevron" aria-hidden="true">▾</span>
                    </button>
                <?php else: ?>
                    <span class="home-sidebar-toggle-placeholder" aria-hidden="true"></span>
                <?php endif; ?>

                <a class="home-sidebar-link" href="<?= $escape($href) ?>">
                    <?php if ($isAdultRoot): ?>
                        <span class="home-sidebar-adult-badge">18+</span>
                    <?php endif; ?>
                    <span class="home-sidebar-name">
                        <?= $escape($node['name'] ?? '') ?>
                    </span>
                </a>
            </div>

            <?php if ($hasChildren): ?>
                <div
                    class="home-sidebar-children"
                    id="<?= $escape($childrenId) ?>"
                    data-home-sidebar-children="<?= $id ?>"
                >
                    <?php $renderSidebarNodes($children, $level + 1); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
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
    <link rel="stylesheet" href="/Anabelka/css/home.css?v=2">
    <link rel="stylesheet" href="/Anabelka/css/home-desktop-sidebar.css?v=1">
</head>
<body>

<?php require __DIR__ . '/partials/header.php'; ?>

<main class="home-page">
    <div class="home-shell">
        <aside
            class="home-desktop-sidebar"
            aria-label="<?= $escape(
                Translator::t('home.nav_catalog', 'Каталог')
            ) ?>"
        >
            <div class="home-sidebar-panel">
                <a class="home-sidebar-title" href="/Anabelka/catalog">
                    <?= $escape(
                        Translator::t('home.nav_catalog', 'Каталог')
                    ) ?>
                </a>

                <?php if (!empty($navigationTree)): ?>
                    <div class="home-sidebar-tree">
                        <?php $renderSidebarNodes($navigationTree); ?>
                    </div>
                <?php endif; ?>
            </div>
        </aside>

        <nav class="home-department-nav" aria-label="Напрямки магазину">
            <a class="home-department-nav-main" href="/Anabelka/catalog">
                <?= $escape(
                    Translator::t('home.nav_catalog', 'Каталог')
                ) ?>
            </a>

            <?php foreach ($standardDirections as $direction): ?>
                <a
                    href="/Anabelka/catalog/<?= $escape($direction['slug'] ?? '') ?>"
                >
                    <?= $escape($direction['name'] ?? '') ?>
                </a>
            <?php endforeach; ?>

            <?php foreach ($adultDirections as $direction): ?>
                <a
                    class="home-department-nav-adult"
                    href="/Anabelka/18-plus/<?= $escape($direction['slug'] ?? '') ?>"
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
                            href="/Anabelka/catalog/<?= $escape($direction['slug'] ?? '') ?>"
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
                            href="/Anabelka/18-plus/<?= $escape($direction['slug'] ?? '') ?>"
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

        <section class="home-section">
            <div class="home-section-head">
                <div class="home-section-title">
                    <h2>
                        <?= $escape(
                            Translator::t('home.latest_title', 'Новинки')
                        ) ?>
                    </h2>
                    <p>
                        <?= $escape(
                            Translator::t(
                                'home.latest_text',
                                'Останні товари, додані до каталогу.'
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

            <?php if (empty($latestProducts)): ?>
                <div class="home-empty">
                    <?= $escape(
                        Translator::t(
                            'home.empty_products',
                            'Нових товарів поки немає.'
                        )
                    ) ?>
                </div>
            <?php else: ?>
                <div class="home-product-grid">
                    <?php foreach ($latestProducts as $product): ?>
                        <?php
                        $productImage = $assetUrl($product['main_image'] ?? '');
                        $currentPrice = Product::getCurrentPrice($product);
                        $oldPrice = (float) ($product['old_price'] ?? 0);
                        $variants = is_array($product['color_variants'] ?? null)
                            ? $product['color_variants']
                            : [];
                        ?>

                        <a
                            class="home-product-card"
                            href="/Anabelka/product/<?= $escape($product['slug'] ?? '') ?>"
                            aria-label="<?= $escape(
                                Translator::t(
                                    'home.product_open',
                                    'Переглянути товар'
                                )
                                . ': '
                                . ($product['name'] ?? '')
                            ) ?>"
                        >
                            <div class="home-product-image">
                                <?php if ($productImage !== ''): ?>
                                    <img
                                        src="<?= $escape($productImage) ?>"
                                        alt="<?= $escape($product['name'] ?? '') ?>"
                                        loading="lazy"
                                    >
                                <?php else: ?>
                                    <span>Анабелька</span>
                                <?php endif; ?>
                            </div>

                            <div class="home-product-body">
                                <h3><?= $escape($product['name'] ?? '') ?></h3>

                                <?php if (!empty($variants)): ?>
                                    <div class="home-product-colors" aria-hidden="true">
                                        <?php foreach (array_slice($variants, 0, 6) as $variant): ?>
                                            <?php
                                            $hex = strtolower(trim((string) ($variant['color_hex'] ?? '')));
                                            if (!preg_match('/^#[0-9a-f]{6}$/', $hex)) {
                                                $hex = '#b8b0bd';
                                            }
                                            ?>
                                            <span
                                                class="home-product-color"
                                                style="background:<?= $escape($hex) ?>"
                                            ></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <div class="home-product-price">
                                    <strong>
                                        <?= number_format(
                                            (float) $currentPrice,
                                            2,
                                            ',',
                                            ' '
                                        ) ?> €
                                    </strong>

                                    <?php if ($oldPrice > (float) $currentPrice): ?>
                                        <del>
                                            <?= number_format(
                                                $oldPrice,
                                                2,
                                                ',',
                                                ' '
                                            ) ?> €
                                        </del>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

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
</main>

<script src="/Anabelka/js/home-desktop-sidebar.js?v=1" defer></script>
</body>
</html>
