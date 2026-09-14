(function () {
    'use strict';

    if (window.AnabelkaNotify) {
        return;
    }

    const storageKey = 'anabelka-notify-flash';
    const types = ['success', 'error', 'warning', 'info'];
    const durations = {
        success: 2800,
        error: 4800,
        warning: 4200,
        info: 3500
    };
    let messageTimer = null;

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
        return options
            && typeof options === 'object'
            && !Array.isArray(options)
            ? options
            : {};
    }

    function durationFor(type, options)
    {
        const value = Number(options.duration);

        if (
            Object.prototype.hasOwnProperty.call(options, 'duration')
            && Number.isFinite(value)
            && value >= 0
        ) {
            return value;
        }

        return durations[type];
    }

    function show(type, message, options)
    {
        const element = document.getElementById('site-message');
        const text = normalizeMessage(message);

        if (!element || text === '') {
            return false;
        }

        const normalizedType = normalizeType(type);
        const normalizedOptions = normalizeOptions(options);

        element.textContent = text;
        element.classList.add('anabelka-notify');
        types.forEach(function (availableType) {
            element.classList.remove('is-' + availableType);
        });
        element.classList.add('is-' + normalizedType);
        element.classList.add('show');

        window.clearTimeout(messageTimer);
        messageTimer = window.setTimeout(function () {
            element.classList.remove('show');
        }, durationFor(normalizedType, normalizedOptions));

        return true;
    }

    function store(type, message, options)
    {
        const text = normalizeMessage(message);

        if (text === '') {
            return false;
        }

        const payload = {
            type: normalizeType(type),
            message: text,
            options: normalizeOptions(options)
        };

        try {
            window.sessionStorage.setItem(
                storageKey,
                JSON.stringify(payload)
            );
            return true;
        } catch (error) {
            // Reload and navigation must work when storage is unavailable.
            return false;
        }
    }

    function consume()
    {
        let serialized = '';

        try {
            serialized = window.sessionStorage.getItem(storageKey) || '';
            window.sessionStorage.removeItem(storageKey);
        } catch (error) {
            return false;
        }

        if (serialized === '') {
            return false;
        }

        let payload;

        try {
            payload = JSON.parse(serialized);
        } catch (error) {
            return false;
        }

        if (!payload || typeof payload !== 'object') {
            return false;
        }

        return show(payload.type, payload.message, payload.options);
    }

    function formatCount(count)
    {
        const numericCount = Number(count);
        const safeCount = Number.isFinite(numericCount)
            ? Math.max(0, Math.floor(numericCount))
            : 0;

        return safeCount > 99 ? '99+' : String(safeCount);
    }

    window.AnabelkaNotify = {
        success(message, options) {
            return show('success', message, options);
        },
        error(message, options) {
            return show('error', message, options);
        },
        warning(message, options) {
            return show('warning', message, options);
        },
        info(message, options) {
            return show('info', message, options);
        },
        show: show,
        store: store,
        consume: consume,
        formatCount: formatCount
    };

    consume();
}());
