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
            // Sidebar remains usable when storage is unavailable.
        }
    }

    function setState(button, children, isCollapsed)
    {
        button.classList.toggle('is-collapsed', isCollapsed);
        button.setAttribute(
            'aria-expanded',
            isCollapsed ? 'false' : 'true'
        );
        children.hidden = isCollapsed;
    }

    function initSidebar()
    {
        const sidebar = document.querySelector('.public-catalog-sidebar');

        if (!sidebar) {
            return;
        }

        const collapsed = readCollapsed();

        sidebar
            .querySelectorAll('[data-public-catalog-sidebar-toggle]')
            .forEach(function (button) {
                const id = String(
                    button.dataset.publicCatalogSidebarToggle || ''
                );
                const children = sidebar.querySelector(
                    '[data-public-catalog-sidebar-children="'
                    + CSS.escape(id)
                    + '"]'
                );

                if (!children) {
                    return;
                }

                setState(button, children, collapsed.has(id));

                button.addEventListener('click', function () {
                    const nextCollapsed =
                        button.getAttribute('aria-expanded') === 'true';

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
        document.addEventListener('DOMContentLoaded', initSidebar);
    } else {
        initSidebar();
    }
})();
