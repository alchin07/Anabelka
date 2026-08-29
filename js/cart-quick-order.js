/*
 * ========================================
 * КНОПКА «БЫСТРЫЙ ЗАКАЗ» В КОРЗИНЕ
 * ========================================
 */

(function () {
    'use strict';

    function init()
    {
        const checkoutButton =
            document.querySelector(
                'a[href="/Anabelka/checkout"]'
            );

        if (!checkoutButton) {
            return;
        }

        if (
            document.getElementById(
                'cart-quick-order-button'
            )
        ) {
            return;
        }

        const quickButton =
            document.createElement('a');

        quickButton.id =
            'cart-quick-order-button';

        quickButton.href =
            '/Anabelka/quick-order';

        quickButton.textContent =
            checkoutButton.dataset.quickOrderLabel
            || 'Швидке замовлення';

        quickButton.style.display = 'block';
        quickButton.style.width = '100%';
        quickButton.style.boxSizing = 'border-box';
        quickButton.style.marginTop = '10px';
        quickButton.style.padding = '14px';
        quickButton.style.borderRadius = '12px';
        quickButton.style.background =
            'var(--primary-light-color)';
        quickButton.style.color =
            'var(--primary-color)';
        quickButton.style.textAlign = 'center';
        quickButton.style.textDecoration = 'none';
        quickButton.style.fontSize = '16px';
        quickButton.style.fontWeight = 'bold';

        checkoutButton.insertAdjacentElement(
            'afterend',
            quickButton
        );
    }

    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            init
        );
    } else {
        init();
    }
})();
