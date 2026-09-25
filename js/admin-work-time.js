(function () {
    'use strict';

    const script = document.querySelector('script[data-admin-work-time]');

    if (!script) {
        return;
    }

    const endpoint = script.getAttribute('data-work-endpoint') || '';
    const csrf = script.getAttribute('data-work-csrf') || '';
    const surface = script.getAttribute('data-work-surface') || '';

    if (
        endpoint === ''
        || csrf === ''
        || (surface !== 'admin' && surface !== 'public')
    ) {
        return;
    }

    const IDLE_LIMIT_MS = 5 * 60 * 1000;
    const TICK_MS = 5000;
    const HEARTBEAT_MS = 30000;
    const MAX_PENDING_SECONDS = 60;

    let lastActivityAt = Date.now();
    let lastTickAt = Date.now();
    let pendingSeconds = 0;
    let sending = false;

    function markActivity()
    {
        lastActivityAt = Date.now();
    }

    function isActive(now)
    {
        return document.visibilityState === 'visible'
            && now - lastActivityAt <= IDLE_LIMIT_MS;
    }

    function collectActiveTime()
    {
        const now = Date.now();
        const elapsedSeconds = Math.max(
            0,
            Math.min(
                TICK_MS / 1000 + 1,
                (now - lastTickAt) / 1000
            )
        );
        lastTickAt = now;

        if (!isActive(now)) {
            return;
        }

        pendingSeconds = Math.min(
            MAX_PENDING_SECONDS,
            pendingSeconds + elapsedSeconds
        );
    }

    function payload(seconds)
    {
        const body = new URLSearchParams();
        body.set('_csrf', csrf);
        body.set('surface', surface);
        body.set(
            'active_seconds',
            String(Math.max(0, Math.floor(seconds)))
        );

        return body;
    }

    async function flush()
    {
        if (sending) {
            return;
        }

        collectActiveTime();

        const seconds = Math.min(
            MAX_PENDING_SECONDS,
            Math.floor(pendingSeconds)
        );

        if (seconds <= 0) {
            return;
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
                body: payload(seconds).toString(),
                cache: 'no-store'
            });

            if (!response.ok) {
                throw new Error('Work time heartbeat failed');
            }

            pendingSeconds = Math.max(
                0,
                pendingSeconds - seconds
            );
        } catch (error) {
            pendingSeconds = Math.min(
                MAX_PENDING_SECONDS,
                pendingSeconds
            );
        } finally {
            sending = false;
        }
    }

    function flushBeacon()
    {
        collectActiveTime();

        const seconds = Math.min(
            MAX_PENDING_SECONDS,
            Math.floor(pendingSeconds)
        );

        if (
            seconds <= 0
            || typeof navigator.sendBeacon !== 'function'
        ) {
            return;
        }

        const form = new FormData();
        form.append('_csrf', csrf);
        form.append('surface', surface);
        form.append('active_seconds', String(seconds));

        if (navigator.sendBeacon(endpoint, form)) {
            pendingSeconds = Math.max(
                0,
                pendingSeconds - seconds
            );
        }
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

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            lastTickAt = Date.now();
            markActivity();
            return;
        }

        flushBeacon();
    });

    window.addEventListener('pagehide', flushBeacon);

    window.setInterval(
        collectActiveTime,
        TICK_MS
    );

    window.setInterval(
        flush,
        HEARTBEAT_MS
    );
}());
