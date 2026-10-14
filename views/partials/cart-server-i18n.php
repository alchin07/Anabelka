<?php

ProductInterfaceTranslator::seed();

$cartServerUi = [
    'add_error' => Translator::t(
        'product.add_error',
        'Не вдалося додати товар до кошика.'
    ),
    'size_sold_out' => Translator::t(
        'product.size_sold_out',
        'Розмір {size} закінчився.'
    ),
    'stock_error' => Translator::t(
        'product.stock_error',
        'Недостатньо товару на складі.'
    ),
    'change_error' => Translator::t(
        'cart.error_change',
        'Не вдалося змінити кошик.'
    ),
    'color_unavailable' => Translator::t(
        'cart.color_unavailable',
        'Вибраний колір недоступний.'
    ),
    'color_sold_out' => Translator::t(
        'cart.color_sold_out',
        'Цей розмір у вибраному кольорі закінчився.'
    )
];

?>
<script>
window.addEventListener('load', function () {
    const t = <?= json_encode(
        $cartServerUi,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
            | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?>;

    const baseShowMessage = window.showMessage;

    if (typeof baseShowMessage !== 'function') {
        return;
    }

    window.showMessage = function (text, type = 'error') {
        let translated = String(text || '').trim();

        const sizeMatch = translated.match(
            /^Размер\s+(.+?)\s+закончился\.?$/
        );

        if (sizeMatch) {
            translated = t.size_sold_out.replace(
                '{size}',
                sizeMatch[1]
            );
        } else if (/^Этот размер в выбранном цвете закончился\.?$/.test(translated)) {
            translated = t.color_sold_out;
        } else if (translated === 'Цвет недоступен') {
            translated = t.color_unavailable;
        } else if (translated === 'ERROR' || /^\s*</.test(translated)
            || translated.length > 500) {
            translated = t.change_error;
        } else if (
            translated === 'Размер закончился'
            || translated === 'Товар закончился'
            || translated === 'Товар не найден'
        ) {
            translated = t.add_error;
        } else if (
            translated.indexOf('Недостаточно товара на складе') === 0
        ) {
            translated = t.stock_error;
        }

        baseShowMessage(translated, type);
    };
});
</script>
