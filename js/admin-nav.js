/*
 * Спільне меню і допоміжні модулі адмін-панелі.
 */
(function () {
    'use strict';

    function ensureAdminControlsStyles()
    {
        if (document.querySelector('link[data-admin-controls]')) {
            return;
        }

        const stylesheet = document.createElement('link');
        stylesheet.rel = 'stylesheet';
        stylesheet.href = '/Anabelka/css/admin-controls.css?v=2';
        stylesheet.dataset.adminControls = '1';
        document.head.appendChild(stylesheet);
    }


    function normalizeAdminHeading(header)
    {
        const heading = header ? header.querySelector('.admin-page-name') : null;

        if (!heading) {
            return;
        }

        const replacements = {
            'Быстрые заказы': 'Швидкі замовлення',
            'Доставка': 'Доставка',
            'Языки': 'Мови',
            'Переводы': 'Переклади',
            'ИИ-перевод': 'ШІ-переклад',
            'Категории': 'Категорії',
            'Товары': 'Товари'
        };

        const current = (heading.textContent || '').trim();

        if (replacements[current]) {
            heading.textContent = replacements[current];
        }
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


    function ensureAiTranslationSwitcher()
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
            label.textContent = 'ШІ-переклад';

            const select = document.createElement('select');
            select.id = 'ai-provider-select';
            select.className = 'ai-provider-select';
            select.setAttribute('aria-label', 'Провайдер ШІ-перекладу');

            const status = document.createElement('span');
            status.id = 'ai-provider-status';
            status.className = 'ai-provider-status';
            status.setAttribute('aria-live', 'polite');

            switcher.appendChild(label);
            switcher.appendChild(select);
            switcher.appendChild(status);
        }

        const target = document.getElementById('admin-ai-slot');

        if (target && switcher.parentElement !== target) {
            target.appendChild(switcher);
        }

        if (!document.querySelector('script[data-admin-ai-translation]')) {
            const script = document.createElement('script');
            script.src = '/Anabelka/js/admin-ai-translation.js?v=5';
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
                '/Anabelka/js/admin-category-ai-translation.js?v=3',
                'data-admin-category-ai'
            );

            appendScript(
                '/Anabelka/js/admin-category-cards.js?v=2',
                'data-admin-category-cards'
            );

            return;
        }

        if (path === '/Anabelka/admin/products') {
            appendScript(
                '/Anabelka/js/admin-translation-target.js?v=2',
                'data-admin-translation-target'
            );
            return;
        }

        if (path === '/Anabelka/admin/delivery') {
            appendScript(
                '/Anabelka/js/admin-delivery-ai-translation.js?v=3',
                'data-admin-delivery-ai'
            );

            appendScript(
                '/Anabelka/js/admin-translation-target.js?v=2',
                'data-admin-translation-target'
            );
        }
    }


    function markActiveSection()
    {
        const currentPath = window.location.pathname.replace(/\/$/, '');

        document.querySelectorAll('[data-admin-route]').forEach(function (link) {
            const route = (link.dataset.adminRoute || '').replace(/\/$/, '');
            const isExact = link.dataset.adminExact === 'true';
            const isActive = route !== '' && (
                currentPath === route
                || (!isExact && currentPath.indexOf(route + '/') === 0)
            );

            link.classList.toggle('is-active', isActive);

            if (isActive) {
                link.setAttribute('aria-current', 'page');
            } else {
                link.removeAttribute('aria-current');
            }
        });
    }


    function initDrawer()
    {
        const toggle = document.getElementById('admin-menu-toggle');
        const close = document.getElementById('admin-menu-close');
        const drawer = document.getElementById('admin-drawer');
        const backdrop = document.getElementById('admin-menu-backdrop');

        if (!toggle || !drawer || !backdrop) {
            return;
        }

        let previousFocus = null;

        function setOpen(isOpen)
        {
            drawer.classList.toggle('is-open', isOpen);
            backdrop.classList.toggle('is-open', isOpen);
            document.body.classList.toggle('admin-menu-open', isOpen);
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            drawer.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
            backdrop.setAttribute('aria-hidden', isOpen ? 'false' : 'true');

            if (isOpen) {
                previousFocus = document.activeElement;

                if (close) {
                    close.focus();
                }
            } else if (
                previousFocus
                && typeof previousFocus.focus === 'function'
            ) {
                previousFocus.focus();
            }
        }

        toggle.addEventListener('click', function () {
            setOpen(!drawer.classList.contains('is-open'));
        });

        if (close) {
            close.addEventListener('click', function () {
                setOpen(false);
            });
        }

        backdrop.addEventListener('click', function () {
            setOpen(false);
        });

        drawer.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                setOpen(false);
            });
        });

        document.addEventListener('keydown', function (event) {
            if (
                event.key === 'Escape'
                && drawer.classList.contains('is-open')
            ) {
                setOpen(false);
            }
        });
    }


    function init()
    {
        const header = document.querySelector('.admin-site-header');

        if (!header) {
            return;
        }

        ensureAdminControlsStyles();
        normalizeAdminHeading(header);
        normalizePencilButtons();
        markActiveSection();
        initDrawer();
        ensureAiTranslationSwitcher();
        ensurePageAiModules();
    }


    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
