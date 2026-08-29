/*
 * Простая навигация между текущими разделами админ-панели.
 */
(function () {
    'use strict';

    function init()
    {
        const header =
            document.querySelector('.catalog-header');

        if (!header) {
            return;
        }

        if (document.getElementById('admin-section-nav')) {
            return;
        }

        const nav = document.createElement('nav');
        nav.id = 'admin-section-nav';
        nav.style.display = 'flex';
        nav.style.justifyContent = 'center';
        nav.style.gap = '14px';
        nav.style.flexWrap = 'wrap';
        nav.style.marginTop = '12px';

        const orders = document.createElement('a');
        orders.href = '/Anabelka/admin/orders';
        orders.textContent = 'Быстрые заказы';
        orders.style.color = 'var(--primary-color)';
        orders.style.fontWeight = 'bold';
        orders.style.textDecoration = 'none';

        const delivery = document.createElement('a');
        delivery.href = '/Anabelka/admin/delivery';
        delivery.textContent = 'Доставка';
        delivery.style.color = 'var(--primary-color)';
        delivery.style.fontWeight = 'bold';
        delivery.style.textDecoration = 'none';

        nav.appendChild(orders);
        nav.appendChild(delivery);
        header.appendChild(nav);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
