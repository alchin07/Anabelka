<?php
$collectionSource = (string) ($homeBlockPayload['source'] ?? 'latest');
$collectionItems = is_array($homeBlockPayload['items'] ?? null)
    ? $homeBlockPayload['items']
    : [];

$collectionMeta = [
    'latest' => [
        'title_key' => 'home.latest_title',
        'title' => 'Новинки',
        'text_key' => 'home.latest_text',
        'text' => 'Останні товари, додані до каталогу.',
        'href' => '/Anabelka/catalog',
        'link_key' => 'home.all_catalog',
        'link' => 'Увесь каталог'
    ],
    'new' => [
        'title_key' => 'home.collection_new_title',
        'title' => 'Нові надходження',
        'text_key' => 'home.collection_new_text',
        'text' => 'Нові товари без активних акцій.',
        'href' => '/Anabelka/new',
        'link_key' => 'home.collection_new_link',
        'link' => 'Усі новинки'
    ],
    'discounts' => [
        'title_key' => 'home.collection_discounts_title',
        'title' => 'Знижки',
        'text_key' => 'home.collection_discounts_text',
        'text' => 'Товари з активними знижками та спеціальними цінами.',
        'href' => '/Anabelka/discounts',
        'link_key' => 'home.collection_discounts_link',
        'link' => 'Усі знижки'
    ]
];

$collectionInfo = $collectionMeta[$collectionSource]
    ?? $collectionMeta['latest'];
?>
<section class="home-section" data-home-block="<?= $escape($homeBlockKey) ?>">
    <div class="home-section-head">
        <div class="home-section-title">
            <h2>
                <?= $escape(
                    Translator::t(
                        $collectionInfo['title_key'],
                        $collectionInfo['title']
                    )
                ) ?>
            </h2>
            <p>
                <?= $escape(
                    Translator::t(
                        $collectionInfo['text_key'],
                        $collectionInfo['text']
                    )
                ) ?>
            </p>
        </div>

        <a
            class="home-section-link"
            href="<?= $escape($collectionInfo['href']) ?>"
        >
            <?= $escape(
                Translator::t(
                    $collectionInfo['link_key'],
                    $collectionInfo['link']
                )
            ) ?> →
        </a>
    </div>

    <?php if (empty($collectionItems)): ?>
        <div class="home-empty">
            <?= $escape(
                Translator::t(
                    'home.empty_products',
                    'Товарів для цієї підбірки поки немає.'
                )
            ) ?>
        </div>
    <?php else: ?>
        <div class="home-product-grid">
            <?php foreach ($collectionItems as $product): ?>
                <?php
                $productImage = $assetUrl($product['main_image'] ?? '');
                $currentPrice = array_key_exists('current_price', $product)
                    ? (float) $product['current_price']
                    : Product::getCurrentPrice($product);
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
                            <div
                                class="home-product-colors"
                                aria-hidden="true"
                            >
                                <?php foreach (array_slice($variants, 0, 6) as $variant): ?>
                                    <?php
                                    $hex = strtolower(trim((string) ($variant['hex'] ?? '')));

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
                                    $currentPrice,
                                    2,
                                    ',',
                                    ' '
                                ) ?> €
                            </strong>

                            <?php if ($oldPrice > $currentPrice): ?>
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
