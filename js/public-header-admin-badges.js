(function () {
    'use strict';

    const endpoint = '/Anabelka/admin/system/error-notifications';
    const adminLink = document.querySelector('.public-header-admin-action');
    const systemBadge = document.getElementById('admin-system-error-count');

    if (!adminLink || !systemBadge) {
        return;
    }

    function formatCount(count)
    {
        const safeCount = Math.max(0, Number(count) || 0);
        return safeCount > 99 ? '99+' : String(safeCount);
    }

    function updateAccessibleLabel(systemCount)
    {
        const messageBadge = adminLink.querySelector(
            '.public-header-admin-message-badge'
        );
        const messageCount = Math.max(
            0,
            Number(
                String(messageBadge?.textContent || '').replace(/[^0-9]/g, '')
            ) || 0
        );
        const parts = ['Адмін-панель'];

        if (messageCount > 0) {
            parts.push('Нових повідомлень: ' + messageCount);
        }

        if (systemCount > 0) {
            parts.push('Нових системних помилок: ' + systemCount);
        }

        const label = parts.join('. ');
        adminLink.setAttribute('aria-label', label);
        adminLink.setAttribute('title', label);
    }

    updateAccessibleLabel(0);

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
            systemBadge.textContent = formatCount(count);
            systemBadge.hidden = count <= 0;
            updateAccessibleLabel(count);
        })
        .catch(function () {
            // Regular admin notifications remain available if this check fails.
        });
})();
