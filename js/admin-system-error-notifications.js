/*
 * Сповіщення про нові системні помилки для Розробника.
 */
(function () {
    'use strict';

    const endpoint = '/Anabelka/admin/system/error-notifications';
    const errorsUrl = '/Anabelka/admin/system/errors';


    function updateMenu(count)
    {
        const link = document.querySelector(
            '[data-admin-route="' + errorsUrl + '"]'
        );

        if (!link) {
            return;
        }

        let badge = link.querySelector('[data-system-error-badge]');

        if (count <= 0) {
            if (badge) {
                badge.remove();
            }
            return;
        }

        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'admin-nav-badge is-attention';
            badge.dataset.systemErrorBadge = '1';
            link.appendChild(badge);
        }

        badge.textContent = String(count);
        badge.setAttribute(
            'aria-label',
            'Нових системних помилок: ' + count
        );
    }


    function notificationPanel()
    {
        const panels = document.querySelectorAll('.dashboard-panel');

        for (const panel of panels) {
            const heading = panel.querySelector('.dashboard-section-head h2');

            if (
                heading
                && (heading.textContent || '').trim() === 'Центр сповіщень'
            ) {
                return panel;
            }
        }

        return null;
    }


    function updateDashboard(count)
    {
        const panel = notificationPanel();

        if (!panel) {
            return;
        }

        let card = panel.querySelector('[data-system-error-notification]');

        if (count <= 0) {
            if (card) {
                card.remove();
            }
            return;
        }

        const empty = panel.querySelector('.dashboard-empty');
        if (empty) {
            empty.remove();
        }

        let list = panel.querySelector('.dashboard-status-list');

        if (!list) {
            list = document.createElement('div');
            list.className = 'dashboard-status-list';
            panel.appendChild(list);
        }

        if (!card) {
            card = document.createElement('a');
            card.className = 'dashboard-status-card';
            card.href = errorsUrl;
            card.dataset.systemErrorNotification = '1';
            card.innerHTML = [
                '<span>',
                '<strong>Нові системні помилки</strong>',
                '<span>Нове після останнього перегляду</span>',
                '</span>',
                '<span class="dashboard-status-value is-error" data-system-error-count></span>'
            ].join('');
            list.prepend(card);
        }

        const value = card.querySelector('[data-system-error-count]');
        if (value) {
            value.textContent = String(count);
        }
    }


    function load()
    {
        const menuLink = document.querySelector(
            '[data-admin-route="' + errorsUrl + '"]'
        );

        // Пункт існує тільки у Розробника. Іншим ролям запит не потрібен.
        if (!menuLink) {
            return;
        }

        fetch(endpoint, {
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
                updateMenu(count);
                updateDashboard(count);
            })
            .catch(function () {
                // Сам центр сповіщень не повинен створювати нову помилку
                // або заважати роботі адмін-панелі.
            });
    }


    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', load);
    } else {
        load();
    }
})();
