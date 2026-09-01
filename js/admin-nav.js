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


    function ensureAiTranslationSwitcher(header)
    {
        if (!document.querySelector('link[data-admin-ai-translation]')) {
            const stylesheet = document.createElement('link');
            stylesheet.rel = 'stylesheet';
            stylesheet.href = '/Anabelka/css/admin-ai-translation.css?v=2';
            stylesheet.dataset.adminAiTranslation = '1';
            document.head.appendChild(stylesheet);
        }

        let switcher = document.getElementById('ai-provider-switcher');

        if (!switcher) {
            switcher = document.createElement('div');
            switcher.id = 'ai-provider-switcher';
            switcher.className = 'ai-provider-switcher';
            switcher.hidden = true;

            const label = document.createElement('label');
            label.htmlFor = 'ai-provider-select';
            label.textContent = 'ИИ-перевод';

            const select = document.createElement('select');
            select.id = 'ai-provider-select';
            select.className = 'ai-provider-select';
            select.setAttribute('aria-label', 'Провайдер ИИ-перевода');

            const status = document.createElement('span');
            status.id = 'ai-provider-status';
            status.className = 'ai-provider-status';
            status.setAttribute('aria-live', 'polite');

            switcher.appendChild(label);
            switcher.appendChild(select);
            switcher.appendChild(status);
        }

        /*
         * Переключатель ИИ является частью шапки админ-панели,
         * а не плавающим элементом поверх содержимого.
         * Поэтому он больше не перекрывает кнопки модальных окон.
         */
        if (header && switcher.parentElement !== header) {
            header.appendChild(switcher);
        }

        if (!document.querySelector('script[data-admin-ai-translation]')) {
            const script = document.createElement('script');
            script.src = '/Anabelka/js/admin-ai-translation.js?v=1';
            script.dataset.adminAiTranslation = '1';
            document.body.appendChild(script);
        }
    }


    function ensurePageAiModules()
    {
        const path = window.location.pathname.replace(/\/$/, '');

        if (
            path === '/Anabelka/admin/categories'
            && !document.querySelector('script[data-admin-category-ai]')
        ) {
            const script = document.createElement('script');
            script.src = '/Anabelka/js/admin-category-ai-translation.js?v=1';
            script.dataset.adminCategoryAi = '1';
            document.body.appendChild(script);
        }
    }


    function init()
    {
        const header =
            document.querySelector('.catalog-header');

        if (!header) {
            return;
        }

        if (!document.getElementById('admin-section-nav')) {
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

            nav.appendChild(
                createLink(
                    '/Anabelka/admin/categories',
                    'Категории'
                )
            );

            nav.appendChild(
                createLink(
                    '/Anabelka/admin/products',
                    'Товары'
                )
            );

            header.appendChild(nav);
        }

        ensureAiTranslationSwitcher(header);
        ensurePageAiModules();
    }


    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
