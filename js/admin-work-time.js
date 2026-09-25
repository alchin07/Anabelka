(function () {
    'use strict';

    const script = document.querySelector(
        'script[data-admin-work-time]'
    );

    if (!script) {
        return;
    }

    const endpoint = String(
        script.getAttribute('data-work-endpoint') || ''
    ).trim();
    const csrf = String(
        script.getAttribute('data-work-csrf') || ''
    ).trim();
    const source = String(
        script.getAttribute('data-work-source')
        || script.getAttribute('data-work-surface')
        || ''
    ).trim();

    const sourceAliases = {
        admin: 'web_admin',
        public: 'web_public'
    };
    const normalizedSource = sourceAliases[source] || source;
    const allowedSources = [
        'web_admin',
        'web_public',
        'android',
        'ios'
    ];

    if (
        endpoint === ''
        || csrf === ''
        || !allowedSources.includes(normalizedSource)
    ) {
        return;
    }

    const IDLE_LIMIT_MS = 5 * 60 * 1000;
    const HEARTBEAT_MS = 30000;
    const SESSION_STORAGE_KEY =
        'anabelka-admin-work-session-v2';

    let lastActivityAt = Date.now();
    let sending = false;
    let lastSentActive = null;

    function randomSessionId()
    {
        if (
            window.crypto
            && typeof window.crypto.randomUUID === 'function'
        ) {
            return window.crypto.randomUUID();
        }

        const random = Math.random()
            .toString(36)
            .slice(2);
        const time = Date.now().toString(36);

        return 'web-' + time + '-' + random;
    }

    function sessionId()
    {
        try {
            let value = sessionStorage.getItem(
                SESSION_STORAGE_KEY
            );

            if (!value) {
                value = randomSessionId();
                sessionStorage.setItem(
                    SESSION_STORAGE_KEY,
                    value
                );
            }

            return value;
        } catch (error) {
            if (!window.__anabelkaWorkSessionId) {
                window.__anabelkaWorkSessionId =
                    randomSessionId();
            }

            return window.__anabelkaWorkSessionId;
        }
    }

    const clientSessionId = sessionId();

    function markActivity()
    {
        lastActivityAt = Date.now();
    }

    function isActive(now)
    {
        return document.visibilityState === 'visible'
            && now - lastActivityAt <= IDLE_LIMIT_MS;
    }

    function payload(active)
    {
        const body = new URLSearchParams();
        body.set('_csrf', csrf);
        body.set('source', normalizedSource);
        body.set('session_id', clientSessionId);
        body.set('active', active ? '1' : '0');

        return body;
    }

    async function sendState(force)
    {
        if (sending) {
            return;
        }

        const active = isActive(Date.now());

        if (!force && lastSentActive === active) {
            // Active heartbeats must still continue so the server can
            // measure elapsed time using its own clock.
        }

        sending = true;

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type':
                        'application/x-www-form-urlencoded;charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: payload(active).toString(),
                cache: 'no-store'
            });

            if (!response.ok) {
                throw new Error(
                    'Work activity heartbeat failed'
                );
            }

            lastSentActive = active;
        } catch (error) {
            // A failed ping must never invent work time locally.
        } finally {
            sending = false;
        }
    }

    function sendBeacon(active)
    {
        if (
            typeof navigator.sendBeacon !== 'function'
            || endpoint === ''
        ) {
            return;
        }

        const form = new FormData();
        form.append('_csrf', csrf);
        form.append('source', normalizedSource);
        form.append('session_id', clientSessionId);
        form.append('active', active ? '1' : '0');

        navigator.sendBeacon(endpoint, form);
    }

    [
        'pointerdown',
        'keydown',
        'touchstart',
        'wheel',
        'scroll'
    ].forEach(function (eventName) {
        window.addEventListener(
            eventName,
            markActivity,
            {passive: true}
        );
    });

    document.addEventListener(
        'visibilitychange',
        function () {
            if (document.visibilityState === 'visible') {
                markActivity();
                sendState(true);
                return;
            }

            sendBeacon(false);
        }
    );

    window.addEventListener(
        'pagehide',
        function () {
            sendBeacon(false);
        }
    );

    window.setInterval(
        function () {
            sendState(false);
        },
        HEARTBEAT_MS
    );

    sendState(true);
}());
