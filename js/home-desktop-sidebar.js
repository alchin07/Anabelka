(function () {
    'use strict';

    const storageKey = 'anabelka-public-sidebar-collapsed';
    const systemErrorEndpoint = '/Anabelka/admin/system/error-notifications';

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

    function formatBadgeCount(count)
    {
        const safeCount = Math.max(0, Number(count) || 0);
        return safeCount > 99 ? '99+' : String(safeCount);
    }

    function initAdminSystemErrorBadge()
    {
        const adminLink = document.querySelector('.public-header-admin');
        const badge = document.getElementById('admin-notification-count');

        if (!adminLink || !badge) {
            return;
        }

        const regularText = (badge.textContent || '').trim();
        const regularHidden = badge.hidden;
        const regularAriaLabel = adminLink.getAttribute('aria-label') || 'Адмін-панель';
        const regularTitle = adminLink.getAttribute('title') || 'Адмін-панель';

        fetch(systemErrorEndpoint, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            cache: 'no-store'
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('System error notification request failed');
                }

                return response.json();
            })
            .then(function (data) {
                if (!data || data.ok !== true) {
                    return;
                }

                const count = Math.max(0, Number(data.count) || 0);

                if (count > 0) {
                    badge.textContent = formatBadgeCount(count);
                    badge.hidden = false;
                    badge.classList.add('is-system-error');
                    badge.dataset.badgeSource = 'system-error';

                    const label = 'Адмін-панель. Нових системних помилок: ' + count;
                    adminLink.setAttribute('aria-label', label);
                    adminLink.setAttribute('title', label);
                    return;
                }

                badge.textContent = regularText;
                badge.hidden = regularHidden;
                badge.classList.remove('is-system-error');
                delete badge.dataset.badgeSource;
                adminLink.setAttribute('aria-label', regularAriaLabel);
                adminLink.setAttribute('title', regularTitle);
            })
            .catch(function () {
                // Якщо перевірка недоступна, залишаємо звичайний бейдж сповіщень.
            });
    }

    function initSidebar()
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

    function init()
    {
        initSidebar();
        initAdminSystemErrorBadge();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();