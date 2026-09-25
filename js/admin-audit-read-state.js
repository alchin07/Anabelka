(function () {
    'use strict';

    const root = document.querySelector('[data-admin-audit-journal]');

    if (!root) {
        return;
    }

    const endpoint = root.getAttribute('data-audit-seen-endpoint') || '';
    const csrf = root.getAttribute('data-audit-csrf') || '';
    const navBadge = document.querySelector('[data-admin-audit-badge]');
    const detailsItems = Array.from(
        root.querySelectorAll('details[data-audit-entry-id]')
    );

    if (endpoint === '' || csrf === '') {
        return;
    }

    function formatCount(count)
    {
        const safe = Math.max(0, Number(count) || 0);

        if (safe > 99) {
            return '99+';
        }

        return String(safe);
    }

    function formatNewActions(count)
    {
        const safe = Math.max(0, Number(count) || 0);
        const mod10 = safe % 10;
        const mod100 = safe % 100;

        if (mod10 === 1 && mod100 !== 11) {
            return safe + ' нова';
        }

        if (
            mod10 >= 2
            && mod10 <= 4
            && (mod100 < 12 || mod100 > 14)
        ) {
            return safe + ' нові';
        }

        return safe + ' нових';
    }

    function updateGroupBadge(actorKey, count)
    {
        const badges = root.querySelectorAll('[data-audit-group-badge]');

        badges.forEach(function (badge) {
            if (badge.getAttribute('data-audit-group-badge') !== actorKey) {
                return;
            }

            if (count <= 0) {
                badge.remove();
                return;
            }

            badge.setAttribute('data-audit-count', String(count));
            badge.textContent = formatNewActions(count);
        });
    }

    function updateNavBadge(count)
    {
        if (!navBadge) {
            return;
        }

        if (count <= 0) {
            navBadge.hidden = true;
            navBadge.textContent = '0';
            return;
        }

        navBadge.textContent = formatCount(count);
        navBadge.hidden = false;
    }

    async function markSeen(details)
    {
        if (
            !details
            || details.getAttribute('data-audit-unread') !== '1'
            || details.getAttribute('data-audit-pending') === '1'
        ) {
            return;
        }

        const auditLogId = Number(
            details.getAttribute('data-audit-entry-id') || 0
        );

        if (auditLogId <= 0) {
            return;
        }

        details.setAttribute('data-audit-pending', '1');

        try {
            const body = new URLSearchParams();
            body.set('_csrf', csrf);
            body.set('audit_log_id', String(auditLogId));

            const response = await fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body.toString()
            });

            const data = await response.json();

            if (!response.ok || !data || data.ok !== true) {
                throw new Error(
                    data && data.message
                        ? data.message
                        : 'Не вдалося позначити дію прочитаною.'
                );
            }

            details.setAttribute('data-audit-unread', '0');

            const entryBadge = details.querySelector(
                '[data-audit-entry-new-badge]'
            );

            if (entryBadge) {
                entryBadge.remove();
            }

            updateGroupBadge(
                String(data.actor_key || ''),
                Number(data.actor_remaining) || 0
            );
            updateNavBadge(
                Number(data.remaining_total) || 0
            );
        } catch (error) {
            // Keep the badge visible if the server did not persist the state.
        } finally {
            details.removeAttribute('data-audit-pending');
        }
    }

    detailsItems.forEach(function (details) {
        details.addEventListener('toggle', function () {
            if (details.open) {
                markSeen(details);
            }
        });
    });

    (async function markInitiallyOpenItems() {
        for (const details of detailsItems) {
            if (
                details.open
                && details.getAttribute('data-audit-unread') === '1'
            ) {
                await markSeen(details);
            }
        }
    }());
}());
