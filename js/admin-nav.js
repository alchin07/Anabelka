/*
 * Простая навигация между текущими разделами админ-панели.
 */
(function () {
    'use strict';

    function createLink(href, text)
    {
        const link = document.createElement('a');
        link.href = href;
        link.textContent = text;
        link.style.color = 'var(--primary-color)';
        link.style.fontWeight = 'bold';
        link.style.textDecoration = 'none';

        return link;
    }


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

        nav.appendChild(
            createLink(
                '/Anabelka/admin/orders',
                'Быстрые заказы'
            )
        );

        nav.appendChild(
            createLink(
                '/Anabelka/admin/delivery',
                'Доставка'
            )
        );

        nav.appendChild(
            createLink(
                '/Anabelka/admin/languages',
                'Языки'
            )
        );

        header.appendChild(nav);
    }


    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
