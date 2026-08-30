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
    )
];

?>
<script>
window.addEventListener('load', function () {
    const t = <?= json_encode(
        $cartServerUi,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) ?>;

    const baseShowMessage = window.showMessage;

    if (typeof baseShowMessage !== 'function') {
        return;
    }

    window.showMessage = function (text) {
        let translated = String(text || '');

        const sizeMatch = translated.match(
            /^Размер\s+(.+?)\s+закончился\.?$/
        );

        if (sizeMatch) {
            translated = t.size_sold_out.replace(
                '{size}',
                sizeMatch[1]
            );
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

        baseShowMessage(translated);
    };
});
</script>
