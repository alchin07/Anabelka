(function (window, document) {
    'use strict';

    const durations = Object.freeze({
        success: 2800,
        info: 3000,
        warning: 4000,
        error: 5000
    });
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
        const text = String(message || '').trim();

        if (!durations[type] || text === '') {
            return false;
        }

        const itemSignature = signature(type, text);

        if (pendingSignatures.has(itemSignature)) {
            return false;
        }

        pendingSignatures.add(itemSignature);
        queue.push({
            element: null,
            message: text,
            options: normalizeOptions(type, options),
            signature: itemSignature,
            type: type
        });
        renderNext();

        return true;
    }

    function storedItems()
    {
        let payload;

        try {
            payload = JSON.parse(window.sessionStorage.getItem(storageKey) || 'null');
        } catch (error) {
            return [];
        }

        if (!payload || payload.version !== 1 || !Array.isArray(payload.items)) {
            return [];
        }

        return payload.items.filter(function (item) {
            return item
                && durations[item.type]
                && typeof item.message === 'string'
                && item.message.trim() !== '';
        }).map(function (item) {
            return {
                message: item.message.trim(),
                options: normalizeOptions(item.type, item.options),
                type: item.type
            };
        });
    }

    function flash(type, message, options)
    {
        const text = String(message || '').trim();

        if (!durations[type] || text === '') {
            return false;
        }

        const itemSignature = signature(type, text);
        const items = storedItems();
        const isDuplicate = pendingSignatures.has(itemSignature)
            || items.some(function (item) {
                return signature(item.type, item.message) === itemSignature;
            });

        if (isDuplicate) {
            return false;
        }

        items.push({
            message: text,
            options: normalizeOptions(type, options),
            type: type
        });

        try {
            window.sessionStorage.setItem(storageKey, JSON.stringify({
                items: items,
                version: 1
            }));
        } catch (error) {
            return false;
        }

        return true;
    }

    function consumeClientFlash()
    {
        let hasStoredFlash = false;

        try {
            hasStoredFlash = window.sessionStorage.getItem(storageKey) !== null;

            if (hasStoredFlash) {
                const items = storedItems();
                window.sessionStorage.removeItem(storageKey);
                items.forEach(function (item) {
                    enqueue(item.type, item.message, item.options);
                });
            }
        } catch (error) {
            return;
        }
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

    consumeBootstrapFlash();
    consumeClientFlash();
}(window, document));
