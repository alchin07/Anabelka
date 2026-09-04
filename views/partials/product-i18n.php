<?php

ProductInterfaceTranslator::seed();

$productUi = [
    'back_catalog' => Translator::t('product.back_catalog', 'Каталог'),
    'photo' => Translator::t('product.photo', 'Фото товару'),
    'sku' => Translator::t('product.sku', 'Артикул'),
    'prices' => Translator::t('product.prices', 'Ціни'),
    'price' => Translator::t('product.price', 'Ціна'),
    'personal_price' => Translator::t('product.personal_price', 'Персональна ціна'),
    'old_price' => Translator::t('product.old_price', 'Стара ціна'),
    'brand' => Translator::t('product.brand', 'Бренд'),
    'country' => Translator::t('product.country', 'Країна'),
    'color' => Translator::t('product.color', 'Колір'),
    'material' => Translator::t('product.material', 'Матеріал'),
    'description' => Translator::t('product.description', 'Опис'),
    'choose_size' => Translator::t('product.choose_size', 'Оберіть розмір'),
    'in_stock' => Translator::t('product.in_stock', 'В наявності'),
    'out_of_stock' => Translator::t('product.out_of_stock', 'Немає в наявності'),
    'pcs' => Translator::t('product.pcs', 'шт.'),
    'add_to_cart' => Translator::t('product.add_to_cart', 'Додати вибране до кошика'),
    'select_size' => Translator::t('product.select_size', 'Оберіть хоча б один розмір.'),
    'added' => Translator::t('product.added', '✓ Товар додано до кошика'),
    'add_error' => Translator::t('product.add_error', 'Не вдалося додати товар до кошика.'),
    'php_error' => Translator::t('product.php_error', 'Помилка PHP. Дивіться текст нижче.'),
    'size_sold_out' => Translator::t('product.size_sold_out', 'Розмір {size} закінчився.'),
    'stock_error' => Translator::t('product.stock_error', 'Недостатньо товару на складі.'),
    'badge_new' => Translator::t('product.badge_new', 'Новий товар'),
    'badge_sale' => Translator::t('product.badge_sale', 'Знижка')
];
?>
<script>
window.addEventListener('load', function () {
    const t = <?= json_encode(
        $productUi,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) ?>;

    function textNodes(root) {
        const walker = document.createTreeWalker(
            root,
            NodeFilter.SHOW_TEXT
        );

        const nodes = [];
        while (walker.nextNode()) {
            nodes.push(walker.currentNode);
        }

        return nodes;
    }

    function replaceExactText(root, source, target) {
        textNodes(root).forEach(function (node) {
            if (node.nodeValue.trim() === source) {
                const before = node.nodeValue.match(/^\s*/)?.[0] || '';
                const after = node.nodeValue.match(/\s*$/)?.[0] || '';
                node.nodeValue = before + target + after;
            }
        });
    }

    function replaceLeadingLabel(root, source, target) {
        textNodes(root).forEach(function (node) {
            const original = node.nodeValue;
            const trimmed = original.trimStart();

            if (!trimmed.startsWith(source)) {
                return;
            }

            const prefix = original.slice(
                0,
                original.length - trimmed.length
            );

            node.nodeValue =
                prefix
                + target
                + trimmed.slice(source.length);
        });
    }

    function normalizeStockTexts() {
        document.querySelectorAll('.size-stock').forEach(function (element) {
            const text = element.textContent.replace(/\s+/g, ' ').trim();

            const quantityMatch = text.match(
                /^(\d+)\s*(?:шт\.|pcs\.)$/i
            );

            if (quantityMatch) {
                const normalized = quantityMatch[1] + ' ' + t.pcs;

                if (element.textContent.trim() !== normalized) {
                    element.textContent = normalized;
                }
                return;
            }

            if (
                text === 'Нет в наличии'
                || text === 'Немає в наявності'
                || text === 'Out of stock'
            ) {
                if (element.textContent.trim() !== t.out_of_stock) {
                    element.textContent = t.out_of_stock;
                }
            }
        });

        document.querySelectorAll('p').forEach(function (element) {
            const text = element.textContent.replace(/\s+/g, ' ').trim();

            const stockMatch = text.match(
                /^(?:В наличии|В наявності|In stock):\s*(\d+)\s*(?:шт\.|pcs\.)$/i
            );

            if (stockMatch) {
                const normalized =
                    t.in_stock
                    + ': '
                    + stockMatch[1]
                    + ' '
                    + t.pcs;

                if (element.textContent.trim() !== normalized) {
                    element.textContent = normalized;
                }
            }
        });
    }

    const exactReplacements = [
        ['Фото товара', t.photo],
        ['Цены:', t.prices + ':'],
        ['Цена', t.price],
        ['Персональная цена', t.personal_price],
        ['Описание:', t.description + ':'],
        ['Выберите размер:', t.choose_size + ':'],
        ['Нет в наличии', t.out_of_stock],
        ['Добавить выбранное в корзину', t.add_to_cart]
    ];

    exactReplacements.forEach(function (pair) {
        replaceExactText(document.body, pair[0], pair[1]);
    });

    const leadingReplacements = [
        ['← Каталог', '← ' + t.back_catalog],
        ['Артикул:', t.sku + ':'],
        ['Старая цена:', t.old_price + ':'],
        ['Бренд:', t.brand + ':'],
        ['Страна:', t.country + ':'],
        ['Цвет:', t.color + ':'],
        ['Материал:', t.material + ':'],
        ['В наличии:', t.in_stock + ':']
    ];

    leadingReplacements.forEach(function (pair) {
        replaceLeadingLabel(document.body, pair[0], pair[1]);
    });

    normalizeStockTexts();

    const newBadge = document.querySelector('.product-badge-new');
    if (newBadge) {
        newBadge.textContent = t.badge_new;
    }

    document.querySelectorAll('.product-badge-sale').forEach(function (badge) {
        const original = badge.textContent.trim();
        const percent = original.match(/\d+(?:[.,]\d+)?\s*%/);

        badge.textContent = percent
            ? t.badge_sale + ' ' + percent[0]
            : t.badge_sale;
    });

    /*
     * После AJAX старый JS карточки обновляет остатки русскими
     * строками. MutationObserver здесь не используем: изменение
     * текста внутри observer может зациклить страницу.
     * Вместо этого делаем несколько одноразовых проверок после
     * отправки формы — достаточно для ответа локального AJAX.
     */
    const cartForm = document.getElementById('cart-form');

    if (cartForm) {
        cartForm.addEventListener('submit', function () {
            [100, 300, 700, 1200].forEach(function (delay) {
                window.setTimeout(normalizeStockTexts, delay);
            });
        });
    }

    const message = document.getElementById('site-message');

    window.showMessage = function (text) {
        let translated = text;

        const fixed = {
            'Выберите хотя бы один размер.': t.select_size,
            '✓ Товар добавлен в корзину': t.added,
            'Не удалось добавить товар в корзину.': t.add_error,
            'Не удалось добавить товар.': t.add_error,
            'Ошибка PHP. Смотри текст ниже.': t.php_error
        };

        if (fixed[text]) {
            translated = fixed[text];
        }

        const sizeMatch = text.match(/^Размер\s+(.+?)\s+закончился\.?$/);
        if (sizeMatch) {
            translated = t.size_sold_out.replace('{size}', sizeMatch[1]);
        }

        if (text.indexOf('Недостаточно товара на складе') === 0) {
            translated = t.stock_error;
        }

        if (!message) {
            return;
        }

        message.textContent = translated;
        message.classList.add('show');

        clearTimeout(window.siteMessageTimer);
        window.siteMessageTimer = window.setTimeout(function () {
            message.classList.remove('show');
        }, 2200);
    };
});
</script>
