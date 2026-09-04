<!DOCTYPE html>
<html lang="ru">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        <?= htmlspecialchars($product['name']) ?> — Анабелька
    </title>

    <link
        rel="stylesheet"
        href="/Anabelka/css/style.css?v=8"
    >

    <link
        rel="stylesheet"
        href="/Anabelka/css/catalog.css?v=4"
    >

    <link
        rel="stylesheet"
        href="/Anabelka/css/product-gallery.css?v=1"
    >

</head>

<body>


    <?php

    $pageTitle = $product['name'];

    require __DIR__ . '/../partials/header.php';

    ?>


    <main class="catalog">

        <p style="margin-bottom: 25px;">

            <a
                href="/Anabelka/catalog"
                style="color: var(--primary-color);"
            >
                ← Каталог
            </a>

        </p>


        <section class="product-card">


            <?php
            $galleryImages = is_array($images ?? null) ? $images : [];

            if (empty($galleryImages) && !empty($product['main_image'])) {
                $galleryImages[] = [
                    'id' => 0,
                    'path' => $product['main_image'],
                    'is_main' => 1
                ];
            }

            $mainGalleryImage = $galleryImages[0]['path']
                ?? ($product['main_image'] ?? '');
            ?>

            <div class="product-gallery" data-product-gallery>
                <div class="product-image product-gallery-stage">
                    <?php if ($mainGalleryImage !== ''): ?>
                        <img
                            src="<?= htmlspecialchars($mainGalleryImage) ?>"
                            alt="<?= htmlspecialchars($product['name']) ?>"
                            data-product-gallery-main
                        >
                    <?php else: ?>
                        Фото товара
                    <?php endif; ?>
                </div>

                <?php if (count($galleryImages) > 1): ?>
                    <div class="product-gallery-thumbs" aria-label="Фотографії товару">
                        <?php foreach ($galleryImages as $index => $image): ?>
                            <button
                                type="button"
                                class="product-gallery-thumb<?= $index === 0 ? ' is-active' : '' ?>"
                                data-product-gallery-thumb
                                data-image-src="<?= htmlspecialchars($image['path'], ENT_QUOTES, 'UTF-8') ?>"
                                aria-label="Фотографія <?= $index + 1 ?>"
                                aria-pressed="<?= $index === 0 ? 'true' : 'false' ?>"
                            >
                                <img
                                    src="<?= htmlspecialchars($image['path']) ?>"
                                    alt=""
                                    loading="lazy"
                                >
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php

$isAvailable =
    Product::isAvailable(
        $product
    );

?>

<div
    class="product-badges"
    style="
        display: flex;
        flex-wrap: wrap;
        gap: 7px;
        padding: 12px 15px 0;
    "
>
    <?php foreach ($badges ?? [] as $badge): ?>

    <?php

    $badgeType = $badge['type'] ?? 'default';

    if ($badgeType === 'new') {
        $badgeBackground = '#E8F3EE';
        $badgeColor = '#47705D';
    } elseif ($badgeType === 'sale') {
        $badgeBackground = '#F8E9EC';
        $badgeColor = '#A04455';
    } else {
        $badgeBackground = 'var(--primary-light-color)';
        $badgeColor = 'var(--primary-color)';
    }

    ?>

    <span
        class="product-badge product-badge-<?= htmlspecialchars($badgeType) ?>"
        style="
            display: inline-block;
            padding: 5px 10px;
            border-radius: 8px;
            background: <?= $badgeBackground ?>;
            color: <?= $badgeColor ?>;
            font-size: 13px;
            font-weight: 700;
        "
    >
        <?= htmlspecialchars($badge['label']) ?>
    </span>

<?php endforeach; ?>

    <?php if (!$isAvailable): ?>

        <span
            class="product-badge product-badge-out-of-stock"
            style="
                display: inline-block;
                padding: 5px 10px;
                border-radius: 8px;
                background: #F5E7EA;
                color: #9A4553;
                font-size: 13px;
                font-weight: 700;
            "
        >
            Нет в наличии
        </span>

    <?php endif; ?>

</div>


            <h2 style="padding: 15px 15px 5px;">
                <?= htmlspecialchars($product['name']) ?>
            </h2>


            <?php if (!empty($product['sku'])): ?>
                <p style="padding: 0 15px 10px;">
                    Артикул:
                    <?= htmlspecialchars($product['sku']) ?>
                </p>
            <?php endif; ?>


            <?php if ($currentRankSlug === 'guest'): ?>

                <?php

$guestBasePrice = (float) $product['price'];
$guestCurrentPrice = Product::getCurrentPrice($product);
$guestDiscount = Product::getActiveDiscountPercent($product['id']);

?>

<div
    class="product-price"
    style="
        padding: 0 15px 10px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        font-size: 20px;
    "
>
    <span>Цена</span>

    <span
        style="
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-end;
        "
    >
        <?php if ($guestDiscount > 0): ?>
            <s style="color:#888;font-size:17px;">
                <?= number_format($guestBasePrice, 2, ',', ' ') ?> €
            </s>
        <?php endif; ?>

        <strong style="color:var(--primary-color);font-size:20px;">
            <?= number_format($guestCurrentPrice, 2, ',', ' ') ?> €
        </strong>
    </span>
</div>

            <?php else: ?>

                <div style="padding: 0 15px 10px;">
                    <strong>Цены:</strong>

                    <div
                        style="
                            display: flex;
                            flex-direction: column;
                            gap: 8px;
                            margin-top: 10px;
                        "
                    >

                        <?php
                        $discountPercent = Product::getActiveDiscountPercent($product['id']);
                        ?>

                        <?php foreach ($prices as $priceItem): ?>

                            <?php
                            $isCurrent = $priceItem['rank_slug'] === $currentRankSlug;
                            $basePrice = (float) $priceItem['price'];
                            $discountPrice = $basePrice;

                            if ($discountPercent > 0) {
                                $discountPrice = round(
                                    $basePrice * (1 - $discountPercent / 100),
                                    2
                                );
                            }
                            ?>

                            <div
                                style="
                                    display:flex;
                                    justify-content:space-between;
                                    align-items:center;
                                    gap:20px;
                                    padding:8px 10px;
                                    border-radius:10px;
                                    <?= $isCurrent
                                        ? 'background:var(--primary-light-color);color:var(--primary-color);font-weight:700;'
                                        : '' ?>
                                "
                            >
                                <span>
                                    <?php if ($priceItem['rank_slug'] === 'guest'): ?>
                                        Цена
                                    <?php elseif ($priceItem['rank_slug'] === 'member'): ?>
                                        Персональная цена
                                    <?php else: ?>
                                        <?= htmlspecialchars($priceItem['rank_name']) ?>
                                    <?php endif; ?>
                                </span>

                                <span style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end;">
                                    <?php if ($discountPercent > 0): ?>
                                        <s style="color:#888;font-weight:normal;">
                                            <?= number_format($basePrice, 2, ',', ' ') ?> €
                                        </s>
                                        <strong style="color:var(--primary-color);font-size:17px;">
                                            <?= number_format($discountPrice, 2, ',', ' ') ?> €
                                        </strong>
                                    <?php else: ?>
                                        <?= number_format($basePrice, 2, ',', ' ') ?> €
                                    <?php endif; ?>
                                </span>
                            </div>

                        <?php endforeach; ?>

                    </div>
                </div>

            <?php endif; ?>


            <?php if (!empty($product['old_price'])): ?>
                <p style="padding: 0 15px 10px;">
                    Старая цена:
                    <s><?= number_format((float) $product['old_price'], 2, ',', ' ') ?> €</s>
                </p>
            <?php endif; ?>


            <?php if (!empty($product['brand'])): ?>
                <p style="padding: 0 15px 10px;">
                    Бренд: <?= htmlspecialchars($product['brand']) ?>
                </p>
            <?php endif; ?>


            <?php if (!empty($product['country'])): ?>
                <p style="padding: 0 15px 10px;">
                    Страна: <?= htmlspecialchars($product['country']) ?>
                </p>
            <?php endif; ?>


            <?php if (!empty($product['description'])): ?>
                <div style="padding: 15px;">
                    <strong>Описание:</strong>
                    <p style="margin-top: 8px;">
                        <?= nl2br(htmlspecialchars($product['description'])) ?>
                    </p>
                </div>
            <?php endif; ?>


            <form
                action="/Anabelka/cart/add"
                method="POST"
                id="cart-form"
            >

                <input
                    type="hidden"
                    name="product_id"
                    value="<?= (int) $product['id'] ?>"
                >

                <?php if (!empty($attributes)): ?>
                    <div style="padding: 15px;">
                        <strong>Выберите размер:</strong>

                        <div
                            class="size-options"
                            style="
                                display:flex;
                                flex-wrap:wrap;
                                gap:10px;
                                margin-top:12px;
                            "
                        >

                            <?php foreach ($attributes as $attribute): ?>
                                <?php if ($attribute['attribute_slug'] === 'size'): ?>

                                    <?php
                                    $sizeStock = (int) ($attribute['stock'] ?? 0);
                                    $isAvailable = $sizeStock > 0;
                                    ?>

                                    <label
                                        style="
                                            cursor: <?= $isAvailable ? 'pointer' : 'not-allowed' ?>;
                                            opacity: <?= $isAvailable ? '1' : '0.45' ?>;
                                        "
                                    >
                                        <input
                                            type="checkbox"
                                            name="sizes[]"
                                            value="<?= (int) $attribute['value_id'] ?>"
                                            class="size-checkbox"
                                            <?= $isAvailable ? '' : 'disabled' ?>
                                            style="display:none;"
                                        >

                                        <span
                                            class="size-button"
                                            data-stock="<?= $sizeStock ?>"
                                            style="
                                                display:inline-block;
                                                padding:9px 15px;
                                                border:1px solid <?= $isAvailable ? 'var(--primary-color)' : '#aaaaaa' ?>;
                                                border-radius:22px;
                                                background:<?= $isAvailable ? '#fff' : '#f3f3f3' ?>;
                                                color:<?= $isAvailable ? 'var(--primary-color)' : '#888888' ?>;
                                                transition:0.2s;
                                                user-select:none;
                                            "
                                        >
                                            <?= htmlspecialchars($attribute['value']) ?>

                                            <?php if (!empty($product['show_stock_quantity'])): ?>
                                                <small
                                                    class="size-stock"
                                                    data-show-quantity="1"
                                                    style="margin-left:5px;font-size:11px;font-weight:normal;"
                                                >
                                                    <?= $sizeStock > 0
                                                        ? $sizeStock . ' шт.'
                                                        : 'Нет в наличии' ?>
                                                </small>
                                            <?php elseif ($sizeStock <= 0): ?>
                                                <small
                                                    class="size-stock"
                                                    style="margin-left:5px;font-size:11px;font-weight:normal;"
                                                >
                                                    Нет в наличии
                                                </small>
                                            <?php endif; ?>
                                        </span>
                                    </label>

                                <?php endif; ?>
                            <?php endforeach; ?>

                        </div>
                    </div>
                <?php endif; ?>


                <p style="padding: 0 15px 15px;">
                    В наличии:
                    <?= (int) $product['stock'] ?> шт.
                </p>


                <div style="padding: 0 15px 20px;">
                    <button
                        type="submit"
                        style="
                            width:100%;
                            padding:14px;
                            border:0;
                            border-radius:12px;
                            background:var(--primary-color);
                            color:#fff;
                            font-size:16px;
                            font-weight:bold;
                            cursor:pointer;
                        "
                    >
                        Добавить выбранное в корзину
                    </button>
                </div>

            </form>

        </section>

    </main>


    <div
        id="site-message"
        class="site-message"
    ></div>


    <script>

    window.addEventListener(
        'pageshow',
        function (event) {
            const navigation =
                performance.getEntriesByType('navigation')[0];

            const returnedFromHistory =
                event.persisted
                || (
                    navigation
                    && navigation.type === 'back_forward'
                );

            if (returnedFromHistory) {
                window.location.reload();
            }
        }
    );


    const sizeCheckboxes =
        document.querySelectorAll(
            '.size-checkbox'
        );

    const cartForm =
        document.getElementById(
            'cart-form'
        );


    sizeCheckboxes.forEach((checkbox) => {
        checkbox.addEventListener(
            'change',
            function () {
                const button = this.nextElementSibling;

                if (this.checked) {
                    button.style.background = 'var(--primary-color)';
                    button.style.color = '#fff';
                } else {
                    button.style.background = '#fff';
                    button.style.color = 'var(--primary-color)';
                }
            }
        );
    });


    cartForm.addEventListener(
        'submit',
        async function (event) {
            event.preventDefault();

            const selectedSizes =
                document.querySelectorAll(
                    '.size-checkbox:checked'
                );

            if (selectedSizes.length === 0) {
                showMessage('Выберите хотя бы один размер.');
                return;
            }

            const formData = new FormData(cartForm);

            try {
                const response = await fetch(
                    cartForm.action,
                    {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    }
                );

                const responseText = await response.text();
                let data;

                try {
                    data = JSON.parse(responseText);
                } catch (error) {
                    showMessage('Ошибка PHP. Смотри текст ниже.');

                    document.body.insertAdjacentHTML(
                        'beforeend',
                        '<pre style="padding:15px;background:#fff;color:#b00020;white-space:pre-wrap;position:relative;z-index:9999;">'
                        + responseText
                            .replace(/&/g, '&amp;')
                            .replace(/</g, '&lt;')
                            .replace(/>/g, '&gt;')
                        + '</pre>'
                    );
                    return;
                }

                if (!data.success) {
                    showMessage(
                        data.message
                        || 'Не удалось добавить товар в корзину.'
                    );
                    return;
                }

                showMessage('✓ Товар добавлен в корзину');

                selectedSizes.forEach((checkbox) => {
                    const button = checkbox.nextElementSibling;
                    const stockElement = button.querySelector('.size-stock');

                    let stock = Number(button.dataset.stock);

                    if (stock > 0) {
                        stock--;
                    }

                    button.dataset.stock = stock;

                    if (stockElement) {
                        const showQuantity =
                            stockElement.dataset.showQuantity === '1';

                        if (stock <= 0) {
                            stockElement.textContent = 'Нет в наличии';
                            stockElement.style.display = 'inline';
                        } else if (showQuantity) {
                            stockElement.textContent = stock + ' шт.';
                        }
                    }

                    if (stock <= 0) {
                        checkbox.disabled = true;
                        button.style.background = '#f3f3f3';
                        button.style.color = '#888888';
                        button.style.borderColor = '#aaaaaa';
                        checkbox.parentElement.style.cursor = 'not-allowed';
                        checkbox.parentElement.style.opacity = '0.45';
                    }
                });

                sizeCheckboxes.forEach(
                    (checkbox) => {
                        checkbox.checked = false;
                        const button = checkbox.nextElementSibling;
                        button.style.background = '#fff';
                        button.style.color = 'var(--primary-color)';
                    }
                );

                const cartCount =
                    document.getElementById('cart-count');

                if (cartCount) {
                    cartCount.textContent = data.cart_count;
                }

            } catch (error) {
                showMessage('Не удалось добавить товар.');
            }
        }
    );


    function showMessage(text)
    {
        const message =
            document.getElementById('site-message');

        message.textContent = text;
        message.classList.add('show');

        clearTimeout(window.siteMessageTimer);

        window.siteMessageTimer =
            setTimeout(
                () => {
                    message.classList.remove('show');
                },
                2200
            );
    }

    </script>

    <script src="/Anabelka/js/product-gallery.js?v=1"></script>

</body>
</html>
