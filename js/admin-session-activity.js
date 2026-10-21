(function () {
    'use strict';

    const script = document.querySelector(
        'script[data-admin-session-activity]'
    );
    if (!script) {
        return;
    }

    const csrf = script.dataset.csrfToken || '';
    if (!csrf) {
        return;
    }

    const ENDPOINT = '/Anabelka/admin/session/activity';
    const MIN_INTERVAL_MS = 2 * 60 * 1000;
    let lastSentAt = Date.now();
    let sending = false;

    function activity(event) {
        if (
            !event.isTrusted
            || document.visibilityState !== 'visible'
            || sending
        ) {
            return;
        }

        const now = Date.now();
        if (now - lastSentAt < MIN_INTERVAL_MS) {
            return;
        }

        sending = true;
        lastSentAt = now;

        fetch(ENDPOINT, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams({ _csrf: csrf }).toString(),
            cache: 'no-store'
        })
            .catch(function () {
                // The server remains the authority for session expiry.
            })
            .finally(function () {
                sending = false;
            });
    }

    document.addEventListener('pointerdown', activity, { passive: true });
    document.addEventListener('keydown', activity);
    document.addEventListener('wheel', activity, { passive: true });
    document.addEventListener('touchstart', activity, { passive: true });
})();
