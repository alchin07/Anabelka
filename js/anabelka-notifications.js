(function (window, document) {
    'use strict';

    const durations = Object.freeze({
        success: 2800,
        info: 3000,
        warning: 4000,
        error: 5000
    });
    const types = new Set(['success', 'info', 'warning', 'error']);
    const storageKey = 'anabelka.notifications.flash.v1';
    const accents = Object.freeze({
        success: '✓',
        info: 'i',
        warning: '!',
        error: '×'
    });
    const queue = [];
    const pendingSignatures = new Set();
    let active = null;
    let activeTimer = null;

    function signature(type, message)
    {
        return type + '\u0000' + message;
    }

    function normalizeType(type)
    {
        if (typeof type !== 'string') {
            return '';
        }

        const normalized = type.trim().toLowerCase();

        return types.has(normalized) ? normalized : '';
    }

    function normalizeMessage(message)
    {
        if (message === null || message === undefined) {
            return '';
        }

        return String(message).trim();
    }

    function normalizeOptions(type, options)
    {
        const supplied = options && typeof options === 'object'
            ? options
            : {};
        const suppliedDuration = Number(supplied.duration);

        return {
            duration: Number.isFinite(suppliedDuration) && suppliedDuration > 0
                ? suppliedDuration
                : durations[type],
            persistent: supplied.persistent === true
        };
    }

    function normalizeItem(type, message, options)
    {
        const normalizedType = normalizeType(type);
        const normalizedMessage = normalizeMessage(message);

        if (normalizedType === '' || normalizedMessage === '') {
            return null;
        }

        return {
            element: null,
            message: normalizedMessage,
            options: normalizeOptions(normalizedType, options),
            signature: signature(normalizedType, normalizedMessage),
            type: normalizedType
        };
    }

    function renderNext()
    {
        const container = document.getElementById('anabelka-notifications');

        if (!container || active || queue.length === 0) {
            return;
        }

        active = queue.shift();
        const notification = document.createElement('div');
        notification.className = 'anabelka-notification anabelka-notification--' + active.type;
        notification.setAttribute('role', active.type === 'error' ? 'alert' : 'status');
        notification.setAttribute('aria-live', active.type === 'error' ? 'assertive' : 'polite');
        notification.setAttribute('aria-atomic', 'true');

        const accent = document.createElement('span');
        accent.className = 'anabelka-notification__accent';
        accent.setAttribute('aria-hidden', 'true');
        accent.textContent = accents[active.type];
        notification.appendChild(accent);

        const messageElement = document.createElement('span');
        messageElement.className = 'anabelka-notification__message';
        messageElement.textContent = active.message;
        notification.appendChild(messageElement);

        if (
            active.type === 'warning'
            || active.type === 'error'
            || active.options.persistent
        ) {
            const closeButton = document.createElement('button');
            closeButton.className = 'anabelka-notification__close';
            closeButton.setAttribute('type', 'button');
            closeButton.setAttribute(
                'aria-label',
                container.dataset.closeLabel || 'Закрити сповіщення'
            );
            closeButton.textContent = '×';
            closeButton.addEventListener('click', dismiss);
            notification.appendChild(closeButton);
        }

        container.appendChild(notification);
        active.element = notification;

        if (!active.options.persistent) {
            activeTimer = window.setTimeout(
                dismiss,
                active.options.duration
            );
        }
    }

    function enqueue(type, message, options)
    {
        const item = normalizeItem(type, message, options);

        if (!item) {
            return false;
        }

        if (
            pendingSignatures.has(item.signature)
            || storedItems().some(function (storedItem) {
                return storedItem.signature === item.signature;
            })
        ) {
            return false;
        }

        pendingSignatures.add(item.signature);
        queue.push(item);
        renderNext();

        return true;
    }

    function parseStoredItems(serialized)
    {
        let payload;

        try {
            payload = JSON.parse(serialized || 'null');
        } catch (error) {
            return [];
        }

        if (!payload || payload.version !== 1 || !Array.isArray(payload.items)) {
            return [];
        }

        const items = [];
        const signatures = new Set();

        payload.items.forEach(function (candidate) {
            if (!candidate || typeof candidate !== 'object') {
                return;
            }

            const item = normalizeItem(
                candidate.type,
                candidate.message,
                candidate.options
            );

            if (!item || signatures.has(item.signature)) {
                return;
            }

            signatures.add(item.signature);
            items.push(item);
        });

        return items;
    }

    function storedItems()
    {
        try {
            return parseStoredItems(
                window.sessionStorage.getItem(storageKey)
            );
        } catch (error) {
            return [];
        }
    }

    function storageItem(item)
    {
        return {
            message: item.message,
            options: item.options,
            type: item.type
        };
    }

    function flash(type, message, options)
    {
        const item = normalizeItem(type, message, options);

        if (!item) {
            return false;
        }

        const items = storedItems();
        const isDuplicate = pendingSignatures.has(item.signature)
            || items.some(function (storedItem) {
                return storedItem.signature === item.signature;
            });

        if (isDuplicate) {
            return false;
        }

        items.push(item);

        try {
            window.sessionStorage.setItem(storageKey, JSON.stringify({
                items: items.map(storageItem),
                version: 1
            }));
        } catch (error) {
            return false;
        }

        return true;
    }

    function takeClientFlash()
    {
        let serialized;

        try {
            serialized = window.sessionStorage.getItem(storageKey);

            if (serialized === null) {
                return [];
            }

            window.sessionStorage.removeItem(storageKey);
        } catch (error) {
            return [];
        }

        return parseStoredItems(serialized);
    }

    function consumeBootstrapFlash()
    {
        const bootstrap = document.getElementById(
            'anabelka-notifications-bootstrap'
        );

        if (!bootstrap) {
            return;
        }

        const serialized = bootstrap.textContent || '';
        bootstrap.textContent = '';

        let items;

        try {
            items = JSON.parse(serialized || '[]');
        } catch (error) {
            return;
        }

        if (!Array.isArray(items)) {
            return;
        }

        items.forEach(function (item) {
            if (!item || typeof item !== 'object') {
                return;
            }

            enqueue(item.type, item.message, item.options);
        });
    }

    function dismiss()
    {
        if (!active) {
            return false;
        }

        if (activeTimer !== null) {
            window.clearTimeout(activeTimer);
            activeTimer = null;
        }

        if (active.element) {
            active.element.remove();
        }

        pendingSignatures.delete(active.signature);
        active = null;
        renderNext();

        return true;
    }

    window.AnabelkaNotify = Object.freeze({
        show: enqueue,
        success: function (message, options) {
            return enqueue('success', message, options);
        },
        info: function (message, options) {
            return enqueue('info', message, options);
        },
        warning: function (message, options) {
            return enqueue('warning', message, options);
        },
        error: function (message, options) {
            return enqueue('error', message, options);
        },
        flash: flash,
        dismiss: dismiss
    });

    const clientFlashItems = takeClientFlash();
    consumeBootstrapFlash();
    clientFlashItems.forEach(function (item) {
        enqueue(item.type, item.message, item.options);
    });
}(window, document));
