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


    function ensureAdminControlsStyles()
    {
        if (document.querySelector('link[data-admin-controls]')) {
            return;
        }

        const stylesheet = document.createElement('link');
        stylesheet.rel = 'stylesheet';
        stylesheet.href = '/Anabelka/css/admin-controls.css?v=1';
        stylesheet.dataset.adminControls = '1';
        document.head.appendChild(stylesheet);
    }


    function normalizePencilButtons()
    {
        const pencilSvg = [
            '<svg',
            ' viewBox="0 0 24 24"',
            ' fill="none"',
            ' stroke="currentColor"',
            ' stroke-width="2"',
            ' stroke-linecap="round"',
            ' stroke-linejoin="round"',
            ' aria-hidden="true"',
            '>',
            '<path d="M16.5 3.5 a2.1 2.1 0 0 1 3 3 L8 18 l-4 1 1-4 Z"></path>',
            '</svg>'
        ].join('');

        document.querySelectorAll('button').forEach(function (button) {
            const text = (button.textContent || '').trim();

            if (!['✎', '✏', '✐'].includes(text)) {
                return;
            }

            button.classList.add('admin-pencil-button');
            button.innerHTML = pencilSvg;
        });
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


    function appendScript(src, dataName, dataValue)
    {
        if (document.querySelector('script[' + dataName + ']')) {
            return;
        }

        const script = document.createElement('script');
        script.src = src;
        script.setAttribute(dataName, dataValue || '1');
        document.body.appendChild(script);
    }


    function ensurePageAiModules()
    {
        const path = window.location.pathname.replace(/\/$/, '');

        if (path === '/Anabelka/admin/categories') {
            appendScript(
                '/Anabelka/js/admin-category-ai-translation.js?v=1',
                'data-admin-category-ai'
            );

            appendScript(
                '/Anabelka/js/admin-category-cards.js?v=1',
                'data-admin-category-cards'
            );

            return;
        }

        if (path === '/Anabelka/admin/delivery') {
            appendScript(
                '/Anabelka/js/admin-delivery-ai-translation.js?v=1',
                'data-admin-delivery-ai'
            );
        }
    }


    function init()
    {
        const header =
            document.querySelector('.catalog-header');

        if (!header) {
            return;
        }

        ensureAdminControlsStyles();

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
                    '/Anabelka/admin/translations',
                    'Переводы'
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

        normalizePencilButtons();
        ensureAiTranslationSwitcher(header);
        ensurePageAiModules();
    }


    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();