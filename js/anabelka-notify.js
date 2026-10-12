(function () {
    'use strict';

    if (window.AnabelkaNotify) {
        return;
    }

    const storageKey = 'anabelka-notify-flash';
    const types = ['success', 'error', 'warning', 'info'];
    const durations = {success: 2800, error: 4800, warning: 4200, info: 3500};
    const queue = [];
    let active = null;
    let messageTimer = null;
    let host = null;
    let remaining = 0;
    let deadline = 0;
    let focused = false;
    let hovered = false;

    function normalizeType(type)
    {
        const normalized = String(type || '').trim().toLowerCase();
        return types.includes(normalized) ? normalized : 'info';
    }

    function normalizeMessage(message)
    {
        return String(message == null ? '' : message).trim();
    }

    function normalizeOptions(options)
    {
        return options && typeof options === 'object' && !Array.isArray(options)
            ? options : {};
    }

    function durationFor(type, options)
    {
        let value;
        try {
            value = Number(options.duration);
        } catch (error) {
            return durations[type];
        }
        return Object.prototype.hasOwnProperty.call(options, 'duration')
            && Number.isFinite(value) && value >= 0 ? value : durations[type];
    }

    function payloadFor(type, message, options)
    {
        return {type: normalizeType(type), message: normalizeMessage(message),
            options: normalizeOptions(options)};
    }

    function sameMessage(first, second)
    {
        return first && second && first.type === second.type
            && first.message === second.message;
    }

    function readPayloads(serialized)
    {
        try {
            const parsed = JSON.parse(serialized);
            const entries = Array.isArray(parsed) ? parsed : [parsed];
            return entries.filter(function (entry) {
                return entry && typeof entry === 'object' && !Array.isArray(entry)
                    && typeof entry.message === 'string' && entry.message.trim() !== '';
            }).map(function (entry) {
                return payloadFor(entry.type, entry.message, entry.options);
            });
        } catch (error) {
            return [];
        }
    }

    function configureAccessibility(element, type)
    {
        element.setAttribute('role', type === 'error' ? 'alert' : 'status');
        element.setAttribute('aria-live', type === 'error' ? 'assertive' : 'polite');
        element.setAttribute('aria-atomic', 'true');
        element.setAttribute('aria-hidden', 'false');
        element.setAttribute('tabindex', '0');
    }

    function schedule()
    {
        window.clearTimeout(messageTimer);
        if (!active || focused || hovered) {
            return;
        }
        deadline = Date.now() + remaining;
        messageTimer = window.setTimeout(finish, remaining);
    }

    function pause()
    {
        if (active && !focused && !hovered) {
            remaining = Math.max(0, deadline - Date.now());
            window.clearTimeout(messageTimer);
        }
    }

    function messageHost()
    {
        const element = document.getElementById('site-message');
        if (element) {
            if (host !== element) {
                host = element;
                // Live messages never steal focus. Focusing or hovering lets a
                // person read/scroll a long message before the timer continues.
                if (typeof host.addEventListener === 'function') {
                    host.addEventListener('focus', function () { pause(); focused = true; });
                    host.addEventListener('blur', function () { focused = false; schedule(); });
                    host.addEventListener('mouseenter', function () { pause(); hovered = true; });
                    host.addEventListener('mouseleave', function () { hovered = false; schedule(); });
                    host.addEventListener('keydown', function (event) {
                        if (event.key === 'Escape') {
                            event.preventDefault();
                            event.stopPropagation();
                            finish();
                        }
                    });
                }
            }
            return host;
        }
        if (document.readyState === 'loading' || !document.body
            || typeof document.createElement !== 'function') {
            return null;
        }
        const created = document.createElement('div');
        created.id = 'site-message';
        created.className = 'site-message anabelka-notify';
        document.body.appendChild(created);
        return messageHost();
    }

    function displayNext()
    {
        if (active || queue.length === 0) {
            return;
        }
        const element = messageHost();
        if (!element) {
            return; // Keep early head-script messages until the body is ready.
        }
        active = queue.shift();
        configureAccessibility(element, active.type);
        element.textContent = active.message;
        element.classList.add('anabelka-notify');
        types.forEach(function (type) { element.classList.remove('is-' + type); });
        element.classList.add('is-' + active.type, 'show');
        remaining = durationFor(active.type, active.options);
        schedule();
    }

    function finish()
    {
        window.clearTimeout(messageTimer);
        messageTimer = null;
        active = null;
        if (queue.length === 0) {
            focused = false;
            hovered = false;
            if (host && document.activeElement === host
                && typeof host.blur === 'function') {
                host.blur();
            }
        }
        if (host) {
            host.classList.remove('show');
            host.textContent = '';
            host.setAttribute('tabindex', '-1');
            host.setAttribute('aria-hidden', 'true');
        }
        displayNext();
    }

    function show(type, message, options)
    {
        const payload = payloadFor(type, message, options);
        if (payload.message === '') {
            return false;
        }
        if (!sameMessage(active, payload)
            && !queue.some(function (entry) { return sameMessage(entry, payload); })) {
            queue.push(payload);
        }
        displayNext();
        return true;
    }

    function store(type, message, options)
    {
        const payload = payloadFor(type, message, options);
        if (payload.message === '') {
            return false;
        }
        // Only the supported duration crosses reload; arbitrary/cyclic caller
        // objects cannot corrupt the flash queue or make JSON serialization fail.
        payload.options = Object.prototype.hasOwnProperty.call(payload.options, 'duration')
            ? {duration: durationFor(payload.type, payload.options)} : {};
        try {
            const stored = readPayloads(window.sessionStorage.getItem(storageKey) || '');
            // Category actions store a success immediately before navigation.
            // Carry an unread error through that navigation as well.
            [active].concat(queue).forEach(function (entry) {
                if (entry && entry.type === 'error'
                    && !stored.some(function (previous) { return sameMessage(previous, entry); })) {
                    stored.push({type: entry.type, message: entry.message,
                        options: {duration: durationFor(entry.type, entry.options)}});
                }
            });
            if (!stored.some(function (entry) { return sameMessage(entry, payload); })) {
                stored.push(payload);
            }
            window.sessionStorage.setItem(storageKey, JSON.stringify(stored));
            return true;
        } catch (error) {
            return false; // Navigation still works when storage is unavailable.
        }
    }

    function consume()
    {
        let entries = [];
        const phpFlash = document.getElementById('anabelka-flash');
        if (phpFlash) {
            const serialized = phpFlash.textContent;
            phpFlash.textContent = ''; // Clear before delivery, including corrupt data.
            entries = readPayloads(serialized);
        }
        try {
            const serialized = window.sessionStorage.getItem(storageKey) || '';
            window.sessionStorage.removeItem(storageKey);
            entries = entries.concat(readPayloads(serialized));
        } catch (error) {
            // PHP flash and direct messages also work without sessionStorage.
        }
        entries.forEach(function (entry) { show(entry.type, entry.message, entry.options); });
        displayNext();
        return entries.length > 0;
    }

    function formatCount(count)
    {
        const numericCount = Number(count);
        const safeCount = Number.isFinite(numericCount) ? Math.max(0, Math.floor(numericCount)) : 0;
        return safeCount > 99 ? '99+' : String(safeCount);
    }

    window.AnabelkaNotify = {
        success(message, options) { return show('success', message, options); },
        error(message, options) { return show('error', message, options); },
        warning(message, options) { return show('warning', message, options); },
        info(message, options) { return show('info', message, options); },
        show: show, store: store, consume: consume, formatCount: formatCount
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', consume, {once: true});
    } else {
        consume();
    }
}());
