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
    const flashKey = 'anabelka-mobile-navigation-flash';
    let revision = String(root.dataset.revision || '');
    let dragState = null;

    function notify(type, message)
    {
        const text = String(message || '');

        if (window.AnabelkaNotify && typeof window.AnabelkaNotify[type] === 'function') {
            window.AnabelkaNotify[type](text);
            return;
        }

        const host = document.getElementById('site-message');

        if (host) {
            host.textContent = text;
        }
    }

    function consumeStoredFlash()
    {
        try {
            const raw = window.sessionStorage.getItem(flashKey);

            if (!raw) {
                return;
            }

            window.sessionStorage.removeItem(flashKey);
            const parsed = JSON.parse(raw);
            notify(parsed.type || 'success', parsed.message || '');
        } catch (error) {
            window.sessionStorage.removeItem(flashKey);
        }
    }

    function storeFlash(type, message)
    {
        try {
            window.sessionStorage.setItem(
                flashKey,
                JSON.stringify({
                    type: String(type || 'success'),
                    message: String(message || '')
                })
            );
        } catch (error) {
            // A blocked storage area must not prevent the mutation itself.
        }
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
        storeFlash('success', message);
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

        setFormPending(form, true);
        request(form.action, new FormData(form))
            .then(function (payload) {
                reloadWithSuccess(payload.message || 'Зміни збережено.');
            })
            .catch(function (error) {
                notify('error', error.message);
                setFormPending(form, false);
            });
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

    consumeStoredFlash();
}());
