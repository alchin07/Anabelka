(function () {
    'use strict';

    const root = document.querySelector('[data-mobile-navigation-root]');

    if (!root) {
        return;
    }

    const list = root.querySelector('[data-mobile-navigation-list]');
    const addButton = root.querySelector('[data-mobile-navigation-add]');
    const createPanel = root.querySelector('[data-mobile-navigation-create]');
    const createCancel = root.querySelector('[data-mobile-navigation-create-cancel]');
    let revision = String(root.dataset.revision || '');
    let dragState = null;

    function notify(type, message)
    {
        const shared = window.AnabelkaNotify;

        return shared && typeof shared.show === 'function'
            ? shared.show(type, message)
            : false;
    }


    function createFieldMessage(field)
    {
        const raw = String(field.value || '');
        const value = raw.trim();

        if (field.name === 'name_uk') {
            if (!value) {
                return 'Вкажіть українську назву пункту меню.';
            }

            return Array.from(value).length > 160
                ? 'Назва пункту меню занадто довга.'
                : '';
        }

        if (field.name !== 'url') {
            return '';
        }

        if (!value) {
            return 'Вкажіть посилання на сторінку.';
        }

        if (Array.from(value).length > 1000) {
            return 'Посилання занадто довге (максимум 1000 символів).';
        }

        if (/[\\\x00-\x1F\x7F]/.test(raw) || value.startsWith('//')) {
            return 'Посилання містить недопустимі символи.';
        }

        if (/^(?:javascript|data|file):/i.test(value)) {
            return 'Недозволена схема посилання.';
        }

        if (/^https?:\/\//i.test(value)) {
            try {
                const address = new URL(value);

                return /^(?:http:|https:)$/.test(address.protocol)
                    && address.hostname
                    && !address.username
                    && !address.password
                    ? ''
                    : 'Вкажіть коректне зовнішнє посилання.';
            } catch (error) {
                return 'Вкажіть коректне зовнішнє посилання.';
            }
        }

        const path = value.split(/[?#]/, 1)[0];
        let decoded;
        let decodedTwice;

        try {
            decoded = decodeURIComponent(path);
            decodedTwice = decodeURIComponent(decoded);
        } catch (error) {
            return 'Некоректне внутрішнє посилання.';
        }

        if (decoded !== decodedTwice) {
            return 'Неоднозначне кодування посилання.';
        }

        if (decoded !== '/Anabelka' && !decoded.startsWith('/Anabelka/')) {
            return 'Внутрішнє посилання повинно вести в Анабельку.';
        }

        if (decoded.split('/').some(function (part) {
            return part === '.' || part === '..';
        })) {
            return 'Шлях не може виходити за межі Анабельки.';
        }

        return '';
    }


    function setCreateFieldError(form, field, message)
    {
        const help = form.querySelector(
            '[data-mobile-navigation-error="' + field.name + '"]'
        );

        if (!help) {
            return;
        }

        help.textContent = message;
        help.hidden = !message;

        if (message) {
            field.setAttribute('aria-invalid', 'true');
        } else {
            field.removeAttribute('aria-invalid');
        }
    }


    function clearCreateErrors(form)
    {
        ['name_uk', 'url'].forEach(function (name) {
            const field = form.querySelector('input[name="' + name + '"]');

            if (field) {
                setCreateFieldError(form, field, '');
            }
        });
    }


    function validateCreateForm(form)
    {
        let firstInvalid = null;

        ['name_uk', 'url'].forEach(function (name) {
            const field = form.querySelector('input[name="' + name + '"]');

            if (!field) {
                return;
            }

            const message = createFieldMessage(field);
            setCreateFieldError(form, field, message);

            if (message && !firstInvalid) {
                firstInvalid = field;
            }
        });

        if (firstInvalid) {
            firstInvalid.focus();
        }

        return !firstInvalid;
    }


    function showCreateServerError(form, error)
    {
        if (
            form.dataset.mobileNavigationForm !== 'create'
            || error.status !== 422
        ) {
            return false;
        }

        const message = String(error.message || '');
        let name = '';

        if (/посилан|URL|схем|кодуван|шлях/i.test(message)) {
            name = 'url';
        } else if (/назв/i.test(message)) {
            name = 'name_uk';
        }

        const field = name
            ? form.querySelector('input[name="' + name + '"]')
            : null;

        if (!field) {
            return false;
        }

        setCreateFieldError(form, field, message);
        field.focus();
        return true;
    }


    function csrf()
    {
        return String(root.dataset.csrf || '');
    }

    function itemNodes()
    {
        if (!list) {
            return [];
        }

        return Array.from(
            list.querySelectorAll('[data-mobile-navigation-item]')
        );
    }

    function itemId(item)
    {
        return Number(item && item.dataset ? item.dataset.itemId : 0) || 0;
    }

    function orderIds()
    {
        return itemNodes()
            .map(itemId)
            .filter(function (id) {
                return id > 0;
            });
    }

    function restoreOrder(ids)
    {
        if (!list || !Array.isArray(ids)) {
            return;
        }

        const byId = new Map();

        itemNodes().forEach(function (item) {
            byId.set(itemId(item), item);
        });

        ids.forEach(function (id) {
            const item = byId.get(Number(id));

            if (item) {
                list.appendChild(item);
            }
        });
    }

    function buildFormData(values)
    {
        const data = new FormData();

        Object.keys(values).forEach(function (key) {
            const value = values[key];

            if (Array.isArray(value)) {
                value.forEach(function (entry) {
                    data.append(key + '[]', String(entry));
                });
                return;
            }

            data.append(key, String(value));
        });

        return data;
    }

    async function request(url, body)
    {
        const response = await window.fetch(url, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        let payload = null;

        try {
            payload = await response.json();
        } catch (error) {
            payload = null;
        }

        if (!response.ok || !payload || payload.ok !== true) {
            const message = payload && payload.message
                ? payload.message
                : 'Не вдалося виконати дію. Спробуйте ще раз.';
            const failure = new Error(String(message));
            failure.status = response.status;
            throw failure;
        }

        return payload;
    }

    function reloadWithSuccess(message)
    {
        const shared = window.AnabelkaNotify;
        const stored = shared && typeof shared.store === 'function'
            && shared.store('success', message);

        if (!stored) {
            // If storage is blocked, report success without a second flash queue.
            notify('success', message);
        }

        window.location.reload();
    }

    function setFormPending(form, pending)
    {
        form.dataset.pending = pending ? '1' : '0';

        Array.from(form.querySelectorAll('button[type="submit"]')).forEach(
            function (button) {
                button.disabled = Boolean(pending);
            }
        );
    }

    root.addEventListener('submit', function (event) {
        const form = event.target;

        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        if (!form.matches('[data-mobile-navigation-form]')) {
            return;
        }

        event.preventDefault();

        if (form.dataset.pending === '1') {
            return;
        }

        if (
            form.dataset.mobileNavigationForm === 'create'
            && !validateCreateForm(form)
        ) {
            return;
        }

        setFormPending(form, true);
        request(form.action, new FormData(form))
            .then(function (payload) {
                reloadWithSuccess(payload.message || 'Зміни збережено.');
            })
            .catch(function (error) {
                if (!showCreateServerError(form, error)) {
                    notify('error', error.message);
                }
                setFormPending(form, false);
            });
    });


    root.addEventListener('input', function (event) {
        const field = event.target;
        const form = field && field.form;

        if (
            !form
            || form.dataset.mobileNavigationForm !== 'create'
            || !['name_uk', 'url'].includes(field.name)
        ) {
            return;
        }

        const hint = form.querySelector(
            '[data-mobile-navigation-error="' + field.name + '"]'
        );

        if (hint && !hint.hidden) {
            setCreateFieldError(form, field, createFieldMessage(field));
        }
    });

    if (addButton && createPanel) {
        addButton.addEventListener('click', function () {
            createPanel.hidden = false;
            const input = createPanel.querySelector('input[name="name_uk"]');

            if (input) {
                input.focus();
            }
        });
    }

    if (createCancel && createPanel) {
        createCancel.addEventListener('click', function () {
            const form = createPanel.querySelector(
                'form[data-mobile-navigation-form="create"]'
            );

            if (form) {
                clearCreateErrors(form);
            }

            createPanel.hidden = true;
            if (addButton) {
                addButton.focus();
            }
        });
    }

    function itemForControl(control)
    {
        return control.closest('[data-mobile-navigation-item]');
    }

    function sendToggle(button)
    {
        const item = itemForControl(button);
        const id = itemId(item);

        if (id <= 0) {
            return;
        }

        button.disabled = true;
        request(
            '/Anabelka/admin/mobile-navigation/toggle',
            buildFormData({
                _csrf: csrf(),
                item_id: id,
                is_active: button.dataset.nextActive === '1' ? 1 : 0
            })
        ).then(function (payload) {
            reloadWithSuccess(payload.message || 'Стан пункту змінено.');
        }).catch(function (error) {
            button.disabled = false;
            notify('error', error.message);
        });
    }

    function sendDelete(button)
    {
        const item = itemForControl(button);
        const id = itemId(item);

        if (id <= 0) {
            return;
        }

        const nameNode = item.querySelector('.mobile-navigation-item-copy strong');
        const itemName = nameNode ? nameNode.textContent.trim() : '';
        const confirmDelete = window.AnabelkaDialog
            && typeof window.AnabelkaDialog.confirm === 'function'
            ? window.AnabelkaDialog.confirm({
                title: 'Видалити пункт меню?',
                message: itemName
                    ? 'Пункт «' + itemName + '» буде видалено разом із перекладами.'
                    : 'Пункт буде видалено разом із перекладами.',
                confirmText: 'Видалити',
                cancelText: 'Скасувати',
                danger: true
            })
            : Promise.resolve(window.confirm('Видалити цей пункт меню?'));

        confirmDelete.then(function (accepted) {
            if (!accepted) {
                return;
            }

            button.disabled = true;
            return request(
                '/Anabelka/admin/mobile-navigation/delete',
                buildFormData({
                    _csrf: csrf(),
                    item_id: id
                })
            ).then(function (payload) {
                reloadWithSuccess(payload.message || 'Пункт видалено.');
            }).catch(function (error) {
                button.disabled = false;
                notify('error', error.message);
            });
        });
    }

    function persistOrder(previousOrder)
    {
        const ids = orderIds();

        return request(
            '/Anabelka/admin/mobile-navigation/move',
            buildFormData({
                _csrf: csrf(),
                revision: revision,
                ids: ids
            })
        ).then(function (payload) {
            revision = String(payload.revision || revision);
            root.dataset.revision = revision;
            reloadWithSuccess(payload.message || 'Порядок меню збережено.');
        }).catch(function (error) {
            restoreOrder(previousOrder);
            notify('error', error.message);
            throw error;
        });
    }

    function moveAccessible(button)
    {
        const item = itemForControl(button);

        if (!item || !list) {
            return;
        }

        const previousOrder = orderIds();
        const direction = button.dataset.mobileNavigationMove;

        if (direction === 'up') {
            const previous = item.previousElementSibling;
            if (!previous) {
                return;
            }
            list.insertBefore(item, previous);
        } else if (direction === 'down') {
            const next = item.nextElementSibling;
            if (!next) {
                return;
            }
            list.insertBefore(next, item);
        } else {
            return;
        }

        button.disabled = true;
        persistOrder(previousOrder).catch(function () {
            button.disabled = false;
        });
    }

    root.addEventListener('click', function (event) {
        const control = event.target.closest('button');

        if (!control || !root.contains(control)) {
            return;
        }

        if (control.hasAttribute('data-mobile-navigation-toggle')) {
            sendToggle(control);
            return;
        }

        if (control.hasAttribute('data-mobile-navigation-delete')) {
            sendDelete(control);
            return;
        }

        if (control.hasAttribute('data-mobile-navigation-move')) {
            moveAccessible(control);
        }
    });

    function finishDrag(event, shouldSave)
    {
        if (!dragState) {
            return;
        }

        const state = dragState;
        dragState = null;
        state.item.classList.remove('is-dragging');

        if (
            state.handle
            && typeof state.handle.releasePointerCapture === 'function'
            && state.pointerId !== null
        ) {
            try {
                state.handle.releasePointerCapture(state.pointerId);
            } catch (error) {
                // The browser may already have released capture.
            }
        }

        if (shouldSave && state.changed) {
            persistOrder(state.previousOrder).catch(function () {});
        } else if (!shouldSave && state.changed) {
            restoreOrder(state.previousOrder);
        }

        if (event) {
            event.preventDefault();
        }
    }

    root.addEventListener('pointerdown', function (event) {
        const handle = event.target.closest('[data-mobile-navigation-drag-handle]');

        if (!handle || !list || event.button !== 0) {
            return;
        }

        const item = itemForControl(handle);

        if (!item) {
            return;
        }

        event.preventDefault();
        dragState = {
            item: item,
            handle: handle,
            pointerId: event.pointerId,
            previousOrder: orderIds(),
            changed: false
        };
        item.classList.add('is-dragging');

        if (typeof handle.setPointerCapture === 'function') {
            try {
                handle.setPointerCapture(event.pointerId);
            } catch (error) {
                // Pointer capture is an enhancement, not a requirement.
            }
        }
    });

    root.addEventListener('pointermove', function (event) {
        if (!dragState || !list) {
            return;
        }

        event.preventDefault();
        const dragged = dragState.item;
        const siblings = itemNodes().filter(function (item) {
            return item !== dragged;
        });

        let inserted = false;

        for (const sibling of siblings) {
            const rect = sibling.getBoundingClientRect();

            if (event.clientY < rect.top + rect.height / 2) {
                if (dragged.nextElementSibling !== sibling) {
                    list.insertBefore(dragged, sibling);
                    dragState.changed = true;
                }
                inserted = true;
                break;
            }
        }

        if (!inserted && list.lastElementChild !== dragged) {
            list.appendChild(dragged);
            dragState.changed = true;
        }
    });

    root.addEventListener('pointerup', function (event) {
        finishDrag(event, true);
    });

    root.addEventListener('pointercancel', function (event) {
        finishDrag(event, false);
    });

}());
