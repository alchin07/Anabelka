(function () {
    'use strict';

    // The quick-order button must never submit the add-to-cart form.
    function init()
    {
        const cartForm = document.getElementById('cart-form');
        const quickButton = cartForm
            ? cartForm.querySelector('[data-product-quick-order]')
            : null;

        if (!cartForm || !quickButton) {
            return;
        }

        function showWarning(message)
        {
            const notify = window.AnabelkaNotify;
            if (notify && typeof notify.show === 'function') {
                notify.show('warning', message);
            }
        }

        quickButton.addEventListener('click', function (event) {
            event.preventDefault();

            const productField = cartForm.querySelector(
                'input[name="product_id"]'
            );
            const productId = String(productField ? productField.value : '').trim();
            const selectedSizes = Array.from(cartForm.querySelectorAll(
                'input[name="sizes[]"]:checked:not(:disabled)'
            ));
            const messages = window.AnabelkaProductI18n || {};

            if (!/^[1-9][0-9]*$/.test(productId)) {
                showWarning('Товар недоступний.');
                return;
            }

            if (selectedSizes.length === 0) {
                showWarning(
                    messages.select_size || 'Оберіть хоча б один розмір.'
                );
                return;
            }

            const colorField = cartForm.querySelector('input[name="color_key"]');
            const colorKey = String(colorField ? colorField.value : '').trim();
            const colorOptions = cartForm.querySelector(
                '[data-product-color-options]'
            );

            if (
                colorOptions
                && colorOptions.querySelector('.product-page-color-swatch')
                && colorKey === ''
            ) {
                showWarning(messages.select_color || 'Оберіть колір.');
                return;
            }

            const query = new URLSearchParams();
            query.set('product_id', productId);
            selectedSizes.forEach(function (input) {
                query.append('sizes[]', String(input.value));
            });
            if (colorKey !== '') {
                query.set('color_key', colorKey);
            }

            window.location.assign(
                '/Anabelka/quick-order/product?' + query.toString()
            );
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
})();
