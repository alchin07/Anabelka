/*
 * Фірмова підказка до нативних required/email перевірок оформлення.
 * Подія invalid перехоплюється на capture без відміни захисту браузера.
 */
(function () {
    'use strict';

    const form = document.querySelector('form[action="/Anabelka/checkout"]');
    if (!form) return;

    let translations = {};
    try {
        const data = document.getElementById('checkout-validation-messages');
        translations = JSON.parse(data ? data.textContent : '{}') || {};
    } catch (error) {
        translations = {};
    }

    const errors = new Map();
    let pendingAnnouncement = false;
    let firstAnnouncement = '';

    function keyFor(field) {
        return String(field.name || field.id || '');
    }

    function labelFor(field) {
        const id = field.name === 'delivery_address'
            ? 'delivery-address-label'
            : field.name === 'delivery_option_input'
                ? 'delivery-option-customer-input-group' : '';
        const source = id ? document.getElementById(id) : null;
        const label = field.name === 'delivery_option_input' && source
            ? source.querySelector('label') : source;
        const dynamic = label ? (label.textContent || '').replace(/\*/g, '').trim() : '';
        return dynamic || (translations.labels || {})[field.name] || 'Дані доставки';
    }

    function messageFor(field) {
        if (field.validity && field.validity.valueMissing) {
            const radioPrompts = {
                delivery_method: translations.method || 'Оберіть спосіб доставки.',
                delivery_service: translations.service || 'Оберіть службу доставки.',
                delivery_service_option: translations.option || 'Оберіть варіант отримання.'
            };
            if (radioPrompts[field.name]) return radioPrompts[field.name];
            return (translations.required || 'Заповніть поле «{field}».')
                .replace('{field}', labelFor(field));
        }
        if (field.type === 'email' && field.validity && field.validity.typeMismatch) {
            return translations.email || 'Некоректний Email.';
        }
        return (translations.invalid || 'Перевірте значення поля «{field}».')
            .replace('{field}', labelFor(field));
    }

    function containerFor(field) {
        if (field.type === 'radio') {
            if (field.name === 'delivery_method') {
                return form.querySelector('.delivery-section');
            }
            if (field.name === 'delivery_service') {
                return field.closest('.delivery-services');
            }
            if (field.name === 'delivery_service_option') {
                return field.closest('.delivery-service-options');
            }
        }
        if (field.name === 'delivery_option_input') {
            return field.closest('#delivery-option-customer-input-group');
        }
        return field.parentElement;
    }

    function clear(key) {
        const entry = errors.get(key);
        if (!entry) return;
        entry.controls.forEach(function (field) {
            const current = (field.getAttribute('aria-describedby') || '')
                .split(/\s+/).filter(Boolean);
            const filtered = current.filter(id => id !== entry.message.id);
            if (filtered.length) {
                field.setAttribute('aria-describedby', filtered.join(' '));
            } else {
                field.removeAttribute('aria-describedby');
            }
            field.removeAttribute('aria-invalid');
            field.classList.remove('checkout-validation-invalid');
        });
        entry.container.classList.remove('checkout-validation-group-invalid');
        entry.message.remove();
        errors.delete(key);
    }

    function show(field) {
        const key = keyFor(field);
        const container = containerFor(field);
        if (!key || !container) return '';
        let entry = errors.get(key);
        if (entry && entry.container !== container) {
            clear(key);
            entry = null;
        }
        if (!entry) {
            const message = document.createElement('div');
            message.id = 'checkout-validation-' + key.replace(/[^a-z0-9_-]/gi, '-');
            message.className = 'checkout-validation-message';
            message.setAttribute('role', 'status');
            container.appendChild(message);
            entry = {container, message, controls: new Set()};
            errors.set(key, entry);
        }
        entry.message.textContent = messageFor(field);
        entry.controls.add(field);
        field.setAttribute('aria-invalid', 'true');
        const describedBy = (field.getAttribute('aria-describedby') || '')
            .split(/\s+/).filter(Boolean);
        if (!describedBy.includes(entry.message.id)) {
            describedBy.push(entry.message.id);
            field.setAttribute('aria-describedby', describedBy.join(' '));
        }
        if (field.type === 'radio') {
            container.classList.add('checkout-validation-group-invalid');
        } else {
            field.classList.add('checkout-validation-invalid');
        }
        return entry.message.textContent;
    }

    function refresh() {
        for (const [key, entry] of errors) {
            if (Array.from(entry.controls).some(field =>
                !field.willValidate || (field.validity && field.validity.valid))) {
                clear(key);
            } else {
                const field = entry.controls.values().next().value;
                entry.message.textContent = messageFor(field);
            }
        }
    }

    // HTML5 забороняє відправку невалідної форми; показуємо текст,
    // не викликаючи preventDefault та не встановлюючи novalidate.
    form.addEventListener('invalid', function (event) {
        const field = event.target;
        if (!field || !field.name || !field.willValidate) return;
        const message = show(field);
        if (!message || pendingAnnouncement) return;
        firstAnnouncement = message;
        pendingAnnouncement = true;
        window.setTimeout(function () {
            pendingAnnouncement = false;
            const text = firstAnnouncement;
            firstAnnouncement = '';
            if (window.AnabelkaNotify
                && typeof window.AnabelkaNotify.warning === 'function') {
                window.AnabelkaNotify.warning(text);
            }
        }, 0);
    }, true);

    form.addEventListener('input', refresh);
    form.addEventListener('change', function () {
        window.setTimeout(refresh, 0);
    });
    form.addEventListener('reset', function () {
        window.setTimeout(function () {
            Array.from(errors.keys()).forEach(clear);
        }, 0);
    });
    form.addEventListener('submit', function () {
        Array.from(errors.keys()).forEach(clear);
    });
}());
