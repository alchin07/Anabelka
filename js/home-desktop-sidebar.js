(function () {
    'use strict';

    const storageKey = 'anabelka-public-sidebar-collapsed';

    function readCollapsed()
    {
        try {
            const raw = sessionStorage.getItem(storageKey);
            const parsed = raw ? JSON.parse(raw) : [];

            return new Set(
                Array.isArray(parsed)
                    ? parsed.map(String)
                    : []
            );
        } catch (error) {
            return new Set();
        }
    }

    function saveCollapsed(collapsed)
    {
        try {
            sessionStorage.setItem(
                storageKey,
                JSON.stringify(Array.from(collapsed))
            );
        } catch (error) {
            // Меню продолжает работать и без сохранения состояния.
        }
    }

    function setState(button, children, isCollapsed)
    {
        button.classList.toggle('is-collapsed', isCollapsed);
        button.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
        children.hidden = isCollapsed;
    }

    function init()
    {
        const sidebar = document.querySelector('.home-desktop-sidebar');

        if (!sidebar) {
            return;
        }

        const collapsed = readCollapsed();

        sidebar
            .querySelectorAll('[data-home-sidebar-toggle]')
            .forEach(function (button) {
                const id = String(button.dataset.homeSidebarToggle || '');
                const children = sidebar.querySelector(
                    '[data-home-sidebar-children="' + CSS.escape(id) + '"]'
                );

                if (!children) {
                    return;
                }

                setState(button, children, collapsed.has(id));

                button.addEventListener('click', function () {
                    const nextCollapsed = button.getAttribute('aria-expanded') === 'true';

                    if (nextCollapsed) {
                        collapsed.add(id);
                    } else {
                        collapsed.delete(id);
                    }

                    setState(button, children, nextCollapsed);
                    saveCollapsed(collapsed);
                });
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
