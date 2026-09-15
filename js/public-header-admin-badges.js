(function () {
    'use strict';

    const endpoint = '/Anabelka/admin/system/error-notifications';
    const adminLink = document.querySelector('.public-header-admin-action');
    const messageBadge = adminLink
        ? adminLink.querySelector('.public-header-admin-message-badge')
        : null;
    const systemBadge = document.getElementById('admin-system-error-count');

    if (!adminLink || !messageBadge || !systemBadge) {
        return;
    }

    const regularText = (messageBadge.textContent || '').trim();
    const regularHidden = messageBadge.hidden;
    const regularAriaLabel = adminLink.getAttribute('aria-label') || 'Адмін-панель';
    const regularTitle = adminLink.getAttribute('title') || 'Адмін-панель';

    function formatCount(count)
    {
        if (
            window.AnabelkaNotify
            && typeof window.AnabelkaNotify.formatCount === 'function'
        ) {
            return window.AnabelkaNotify.formatCount(count);
        }

        const safeCount = Math.max(0, Number(count) || 0);
        return safeCount > 99 ? '99+' : String(safeCount);
    }

    function restoreRegularBadge()
    {
        systemBadge.hidden = true;
        messageBadge.textContent = regularText;
        messageBadge.hidden = regularHidden;
        adminLink.setAttribute('aria-label', regularAriaLabel);
        adminLink.setAttribute('title', regularTitle);
    }

    restoreRegularBadge();

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
                restoreRegularBadge();
                return;
            }

            const count = Math.max(0, Number(data.count) || 0);

            if (count > 0) {
                messageBadge.hidden = true;
                systemBadge.textContent = formatCount(count);
                systemBadge.hidden = false;

                const label = 'Адмін-панель. Нових системних помилок: ' + count;
                adminLink.setAttribute('aria-label', label);
                adminLink.setAttribute('title', label);
                return;
            }

            systemBadge.hidden = true;
            messageBadge.textContent = regularText;
            messageBadge.hidden = regularHidden;
            adminLink.setAttribute('aria-label', regularAriaLabel);
            adminLink.setAttribute('title', regularTitle);
        })
        .catch(function () {
            systemBadge.hidden = true;
            messageBadge.textContent = regularText;
            messageBadge.hidden = regularHidden;
            adminLink.setAttribute('aria-label', regularAriaLabel);
            adminLink.setAttribute('title', regularTitle);
        });
})();
