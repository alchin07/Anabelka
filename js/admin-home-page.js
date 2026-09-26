(function () {
    'use strict';

    if (!window.PointerEvent || !window.fetch) {
        return;
    }

    const root = document.querySelector('[data-home-builder-root]');

    if (!root) {
        return;
    }

    const reorderUrl = '/Anabelka/admin/home-page/reorder';
    const messageElement = document.getElementById(
        'admin-home-builder-dnd-message'
    );
    const previewFrames = Array.from(
        root.querySelectorAll('[data-home-builder-preview-frame]')
    );
    const previewCards = Array.from(
        root.querySelectorAll('[data-home-builder-preview-card]')
    );
    let activeDrag = null;
    let previewDragState = null;
    let messageTimer = 0;
    let selectedBlockId = '';

    function setHomeBlockCollapsed(item, collapsed)
    {
        if (!item) {
            return;
        }

        const isCollapsed = Boolean(collapsed);
        const button = item.querySelector(
            '[data-home-builder-editor-toggle]'
        );

        item.classList.toggle(
            'is-collapsed',
            isCollapsed
        );

        if (button) {
            button.setAttribute(
                'aria-expanded',
                isCollapsed ? 'false' : 'true'
            );
            button.textContent = isCollapsed
                ? 'Редагувати'
                : 'Згорнути';
        }
    }


    function collapseAllHomeBlocks()
    {
        root.querySelectorAll(
            '[data-block-id]'
        ).forEach(function (item) {
            setHomeBlockCollapsed(item, true);
        });
    }


    function blocks(list)
    {
        return Array.from(
            list.querySelectorAll(':scope > [data-block-id]')
        );
    }


    function orderIds(list)
    {
        return blocks(list).map(function (item) {
            return String(item.dataset.blockId || '');
        }).filter(Boolean);
    }


    function sameOrder(left, right)
    {
        return left.length === right.length
            && left.every(function (value, index) {
                return value === right[index];
            });
    }


    function restoreOrder(list, ids)
    {
        const byId = new Map();

        blocks(list).forEach(function (item) {
            byId.set(String(item.dataset.blockId || ''), item);
        });

        ids.forEach(function (id) {
            const item = byId.get(String(id));

            if (item) {
                list.appendChild(item);
            }
        });
    }


    function previewMessage(payload)
    {
        const message = Object.assign({
            builder: 'home'
        }, payload);

        previewFrames.forEach(function (frame) {
            if (frame.contentWindow) {
                frame.contentWindow.postMessage(
                    message,
                    window.location.origin
                );
            }
        });
    }


    function zoneNameForList(list)
    {
        const zone = list.closest('[data-home-builder-zone]');

        return zone
            ? String(zone.dataset.homeBuilderZone || '')
            : '';
    }


    function broadcastZoneOrder(list)
    {
        const zone = zoneNameForList(list);

        if (!zone) {
            return;
        }

        previewMessage({
            type: 'anabelka-builder-order',
            zone: zone,
            blockIds: orderIds(list)
        });
    }


    function broadcastAllOrders()
    {
        root.querySelectorAll(
            '[data-home-builder-list]'
        ).forEach(function (list) {
            broadcastZoneOrder(list);
        });
    }


    function broadcastHighlight(blockId)
    {
        selectedBlockId = String(blockId || '');

        previewMessage({
            type: 'anabelka-builder-highlight',
            blockId: selectedBlockId
        });
    }


    function selectBuilderCard(blockId, shouldScroll)
    {
        const id = String(blockId || '');
        let selected = null;

        root.querySelectorAll('[data-block-id]').forEach(function (item) {
            const matches = String(item.dataset.blockId || '') === id;
            item.classList.toggle('is-preview-selected', matches);

            if (matches) {
                selected = item;
            }
        });

        if (
            selected
            && shouldScroll
            && typeof selected.scrollIntoView === 'function'
        ) {
            selected.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
        }
    }


    function resizePreviewCard(card)
    {
        const stage = card.querySelector(
            '[data-home-builder-preview-stage]'
        );
        const surface = card.querySelector(
            '[data-home-builder-preview-surface]'
        );

        if (!stage || !surface || card.offsetParent === null) {
            return;
        }

        const targetWidth = Number(surface.dataset.previewWidth || 0);
        const targetHeight = Number(surface.dataset.previewHeight || 0);

        if (targetWidth <= 0 || targetHeight <= 0) {
            return;
        }

        const availableWidth = Math.max(1, stage.clientWidth - 24);
        const availableHeight = Math.max(1, stage.clientHeight - 24);
        const scale = Math.min(
            1,
            availableWidth / targetWidth,
            availableHeight / targetHeight
        );

        surface.style.width = targetWidth + 'px';
        surface.style.height = targetHeight + 'px';
        surface.style.transform =
            'translateX(-50%) scale(' + scale.toFixed(4) + ')';
    }


    function resizePreviews()
    {
        previewCards.forEach(resizePreviewCard);
    }


    function activatePreview(device)
    {
        const value = String(device || 'mobile');

        root.querySelectorAll(
            '[data-home-builder-preview-tab]'
        ).forEach(function (button) {
            const active = button.dataset.homeBuilderPreviewTab === value;
            button.classList.toggle('is-active', active);
            button.setAttribute(
                'aria-selected',
                active ? 'true' : 'false'
            );
        });

        previewCards.forEach(function (card) {
            card.classList.toggle(
                'is-active',
                card.dataset.homeBuilderPreviewCard === value
            );
        });

        window.requestAnimationFrame(resizePreviews);
    }


    function listForZone(zoneName)
    {
        const wanted = String(zoneName || '');
        let result = null;

        root.querySelectorAll(
            '[data-home-builder-zone]'
        ).forEach(function (zone) {
            if (
                !result
                && String(zone.dataset.homeBuilderZone || '')
                    === wanted
            ) {
                result = zone.querySelector(
                    '[data-home-builder-list]'
                );
            }
        });

        return result;
    }


    function sameIdSet(left, right)
    {
        if (left.length !== right.length) {
            return false;
        }

        const first = left.map(String).sort();
        const second = right.map(String).sort();

        return first.every(function (value, index) {
            return value === second[index];
        });
    }


    function applyPreviewSubsetOrder(list, visibleIds)
    {
        if (!list || !Array.isArray(visibleIds)) {
            return false;
        }

        const submitted = visibleIds.map(String).filter(Boolean);

        if (
            !submitted.length
            || new Set(submitted).size !== submitted.length
        ) {
            return false;
        }

        const current = orderIds(list);
        const visibleSet = new Set(submitted);
        const currentVisible = current.filter(function (id) {
            return visibleSet.has(id);
        });

        if (!sameIdSet(currentVisible, submitted)) {
            return false;
        }

        let cursor = 0;
        const merged = current.map(function (id) {
            if (!visibleSet.has(id)) {
                return id;
            }

            const nextId = submitted[cursor];
            cursor += 1;
            return nextId;
        });

        restoreOrder(list, merged);
        syncArrowButtons(list);
        return true;
    }


    function sendPreviewSaveResult(state, success)
    {
        if (!state || !state.list) {
            return;
        }

        previewMessage({
            type: 'anabelka-builder-preview-save-result',
            zone: state.zone,
            success: Boolean(success),
            blockIds: orderIds(state.list)
        });
    }


    function rejectPreviewDrag(zone)
    {
        const list = listForZone(zone);

        if (list) {
            broadcastZoneOrder(list);
        }

        previewMessage({
            type: 'anabelka-builder-preview-save-result',
            zone: String(zone || ''),
            success: false,
            blockIds: list ? orderIds(list) : []
        });
    }


    function beginPreviewDrag(source, data)
    {
        const zone = String(data.zone || '');
        const visibleIds = Array.isArray(data.blockIds)
            ? data.blockIds.map(String).filter(Boolean)
            : [];
        const list = listForZone(zone);

        if (
            activeDrag
            || previewDragState
            || !list
            || list.classList.contains('is-saving')
            || !visibleIds.length
            || new Set(visibleIds).size !== visibleIds.length
        ) {
            rejectPreviewDrag(zone);
            return;
        }

        const fullIds = orderIds(list);

        collapseAllHomeBlocks();

        if (!visibleIds.every(function (id) {
            return fullIds.includes(id);
        })) {
            rejectPreviewDrag(zone);
            return;
        }

        previewDragState = {
            source: source,
            zone: zone,
            list: list,
            originalIds: fullIds,
            visibleIds: visibleIds
        };

        list.classList.add('is-dragging');
        selectBuilderCard(data.blockId, false);
        broadcastHighlight(data.blockId);
    }


    function handlePreviewOrder(source, data)
    {
        const state = previewDragState;
        const submitted = Array.isArray(data.blockIds)
            ? data.blockIds.map(String).filter(Boolean)
            : [];

        if (
            !state
            || state.source !== source
            || state.zone !== String(data.zone || '')
            || !sameIdSet(state.visibleIds, submitted)
        ) {
            return;
        }

        if (!applyPreviewSubsetOrder(state.list, submitted)) {
            return;
        }

        broadcastZoneOrder(state.list);
    }


    async function finishPreviewDrag(source, data, cancelled)
    {
        const state = previewDragState;

        if (
            !state
            || state.source !== source
            || state.zone !== String(data.zone || '')
        ) {
            rejectPreviewDrag(data.zone);
            return;
        }

        previewDragState = null;
        state.list.classList.remove('is-dragging');

        if (cancelled) {
            restoreOrder(state.list, state.originalIds);
            syncArrowButtons(state.list);
            broadcastZoneOrder(state.list);
            broadcastHighlight('');
            sendPreviewSaveResult(state, true);
            return;
        }

        const submitted = Array.isArray(data.blockIds)
            ? data.blockIds.map(String).filter(Boolean)
            : [];

        if (
            !sameIdSet(state.visibleIds, submitted)
            || !applyPreviewSubsetOrder(state.list, submitted)
        ) {
            restoreOrder(state.list, state.originalIds);
            syncArrowButtons(state.list);
            broadcastZoneOrder(state.list);
            broadcastHighlight('');
            sendPreviewSaveResult(state, false);
            return;
        }

        const nextIds = orderIds(state.list);

        if (sameOrder(state.originalIds, nextIds)) {
            broadcastHighlight('');
            sendPreviewSaveResult(state, true);
            return;
        }

        setSaving(state.list, true);

        try {
            const result = await requestSave(
                state.zone,
                nextIds
            );

            showMessage(
                result.message || 'Порядок блоків збережено.',
                false
            );
            broadcastZoneOrder(state.list);
            sendPreviewSaveResult(state, true);
        } catch (error) {
            restoreOrder(state.list, state.originalIds);
            syncArrowButtons(state.list);
            broadcastZoneOrder(state.list);
            showMessage(
                error && error.message
                    ? error.message
                    : 'Не вдалося зберегти порядок блоків.',
                true
            );
            sendPreviewSaveResult(state, false);
        } finally {
            setSaving(state.list, false);
            syncArrowButtons(state.list);
            broadcastHighlight('');
        }
    }


    function syncArrowButtons(list)
    {
        const items = blocks(list);

        items.forEach(function (item, index) {
            const up = item.querySelector(
                '[data-home-builder-move="up"]'
            );
            const down = item.querySelector(
                '[data-home-builder-move="down"]'
            );

            if (up) {
                up.disabled = index === 0;
            }

            if (down) {
                down.disabled = index === items.length - 1;
            }
        });
    }


    function showMessage(message, isError)
    {
        const text = String(message || '');

        if (
            window.AnabelkaNotify
            && typeof window.AnabelkaNotify.show === 'function'
        ) {
            window.AnabelkaNotify.show(
                isError ? 'error' : 'success',
                text
            );
            return;
        }

        if (!messageElement) {
            return;
        }

        window.clearTimeout(messageTimer);
        messageElement.textContent = text;
        messageElement.classList.toggle('is-error', Boolean(isError));
        messageElement.classList.toggle('is-success', !isError);
        messageElement.hidden = false;

        messageTimer = window.setTimeout(function () {
            messageElement.hidden = true;
        }, isError ? 4800 : 2800);
    }


    function setSaving(list, saving)
    {
        const zone = list.closest('[data-home-builder-zone]');

        list.classList.toggle('is-saving', saving);

        if (zone) {
            if (saving) {
                zone.setAttribute('aria-busy', 'true');
            } else {
                zone.removeAttribute('aria-busy');
            }
        }

        list.querySelectorAll(
            '[data-home-builder-drag-handle]'
        ).forEach(function (handle) {
            handle.disabled = saving;
        });
    }


    function autoScroll(clientY)
    {
        const edge = 72;
        const viewportHeight = window.innerHeight || 0;

        if (clientY < edge) {
            window.scrollBy(0, -Math.max(6, (edge - clientY) / 3));
        } else if (clientY > viewportHeight - edge) {
            window.scrollBy(
                0,
                Math.max(6, (clientY - (viewportHeight - edge)) / 3)
            );
        }
    }


    function startDrag(event)
    {
        if (activeDrag || previewDragState) {
            return;
        }

        if (event.pointerType === 'mouse' && event.button !== 0) {
            return;
        }

        const handle = event.currentTarget;
        const item = handle.closest('[data-block-id]');
        const list = handle.closest('[data-home-builder-list]');
        const zone = handle.closest('[data-home-builder-zone]');

        if (!item || !list || !zone || list.classList.contains('is-saving')) {
            return;
        }

        const zoneName = String(
            zone.dataset.homeBuilderZone || ''
        );

        if (!zoneName) {
            return;
        }

        event.preventDefault();

        setHomeBlockCollapsed(item, true);

        activeDrag = {
            pointerId: event.pointerId,
            handle: handle,
            item: item,
            list: list,
            zone: zoneName,
            originalIds: orderIds(list)
        };

        item.classList.add('is-dragging');
        list.classList.add('is-dragging');
        document.body.classList.add('home-builder-dragging');
        selectBuilderCard(item.dataset.blockId, false);
        broadcastHighlight(item.dataset.blockId);

        try {
            handle.setPointerCapture(event.pointerId);
        } catch (error) {
            // Pointer capture is an enhancement; document listeners still work.
        }
    }


    function moveDrag(event)
    {
        const state = activeDrag;

        if (!state || event.pointerId !== state.pointerId) {
            return;
        }

        event.preventDefault();
        autoScroll(event.clientY);

        const pointed = document.elementFromPoint(
            event.clientX,
            event.clientY
        );
        const target = pointed && pointed.closest
            ? pointed.closest('[data-block-id]')
            : null;

        if (
            !target
            || target === state.item
            || target.parentElement !== state.list
        ) {
            return;
        }

        const rect = target.getBoundingClientRect();
        const before = event.clientY < rect.top + (rect.height / 2);

        if (before) {
            state.list.insertBefore(state.item, target);
        } else {
            state.list.insertBefore(
                state.item,
                target.nextElementSibling
            );
        }

        syncArrowButtons(state.list);
        broadcastZoneOrder(state.list);
    }


    async function requestSave(zone, ids)
    {
        const form = new FormData();

        form.append('_csrf', String(root.dataset.csrf || ''));
        form.append('zone', zone);
        ids.forEach(function (id) {
            form.append('block_ids[]', id);
        });

        const response = await fetch(reorderUrl, {
            method: 'POST',
            body: form,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        const raw = await response.text();
        let result;

        try {
            result = JSON.parse(raw);
        } catch (error) {
            throw new Error(
                response.ok
                    ? 'Сервер повернув некоректну відповідь.'
                    : 'Помилка сервера. Перевірте журнал KSWEB.'
            );
        }

        if (!response.ok || !result.success) {
            throw new Error(
                result.message || 'Не вдалося зберегти порядок блоків.'
            );
        }

        return result;
    }


    async function finishDrag(event, cancelled)
    {
        const state = activeDrag;

        if (!state || event.pointerId !== state.pointerId) {
            return;
        }

        activeDrag = null;

        try {
            if (
                state.handle.hasPointerCapture
                && state.handle.hasPointerCapture(state.pointerId)
            ) {
                state.handle.releasePointerCapture(state.pointerId);
            }
        } catch (error) {
            // Ignore browsers that release capture automatically.
        }

        state.item.classList.remove('is-dragging');
        state.list.classList.remove('is-dragging');
        document.body.classList.remove('home-builder-dragging');

        if (cancelled) {
            restoreOrder(state.list, state.originalIds);
            syncArrowButtons(state.list);
            broadcastZoneOrder(state.list);
            broadcastHighlight('');
            return;
        }

        const nextIds = orderIds(state.list);

        if (sameOrder(state.originalIds, nextIds)) {
            syncArrowButtons(state.list);
            broadcastHighlight('');
            return;
        }

        setSaving(state.list, true);

        try {
            const result = await requestSave(
                state.zone,
                nextIds
            );

            showMessage(
                result.message || 'Порядок блоків збережено.',
                false
            );
        } catch (error) {
            restoreOrder(state.list, state.originalIds);
            broadcastZoneOrder(state.list);
            showMessage(
                error && error.message
                    ? error.message
                    : 'Не вдалося зберегти порядок блоків.',
                true
            );
        } finally {
            setSaving(state.list, false);
            syncArrowButtons(state.list);
            broadcastHighlight('');
        }
    }


    root.querySelectorAll(
        '[data-home-builder-list]'
    ).forEach(function (list) {
        syncArrowButtons(list);
    });

    root.querySelectorAll(
        '[data-home-builder-editor-toggle]'
    ).forEach(function (button) {
        button.addEventListener('click', function () {
            const item = button.closest('[data-block-id]');

            if (
                !item
                || activeDrag
                || previewDragState
            ) {
                return;
            }

            const shouldOpen = item.classList.contains(
                'is-collapsed'
            );

            collapseAllHomeBlocks();

            if (shouldOpen) {
                setHomeBlockCollapsed(item, false);
            }
        });
    });

    root.querySelectorAll(
        '[data-home-builder-drag-handle]'
    ).forEach(function (handle) {
        handle.addEventListener('pointerdown', startDrag);
    });

    root.querySelectorAll('[data-block-id]').forEach(function (item) {
        item.addEventListener('mouseenter', function () {
            selectBuilderCard(item.dataset.blockId, false);
            broadcastHighlight(item.dataset.blockId);
        });
        item.addEventListener('mouseleave', function () {
            if (!activeDrag) {
                broadcastHighlight('');
            }
        });
        item.addEventListener('focusin', function () {
            selectBuilderCard(item.dataset.blockId, false);
            broadcastHighlight(item.dataset.blockId);
        });
        item.addEventListener('focusout', function () {
            if (!activeDrag) {
                broadcastHighlight('');
            }
        });
    });

    root.querySelectorAll(
        '[data-home-builder-preview-tab]'
    ).forEach(function (button) {
        button.addEventListener('click', function () {
            activatePreview(button.dataset.homeBuilderPreviewTab);
        });
    });

    previewFrames.forEach(function (frame) {
        frame.addEventListener('load', function () {
            const card = frame.closest(
                '[data-home-builder-preview-card]'
            );

            if (card) {
                card.classList.remove('is-loading');
            }

            broadcastAllOrders();

            if (selectedBlockId) {
                broadcastHighlight(selectedBlockId);
            }
        });
    });

    window.addEventListener('message', function (event) {
        if (event.origin !== window.location.origin) {
            return;
        }

        const sourceIsPreview = previewFrames.some(function (frame) {
            return frame.contentWindow === event.source;
        });

        if (!sourceIsPreview) {
            return;
        }

        const data = event.data || {};

        if (data.builder !== 'home') {
            return;
        }

        if (data.type === 'anabelka-builder-preview-ready') {
            broadcastAllOrders();
            return;
        }

        if (data.type === 'anabelka-builder-preview-drag-start') {
            beginPreviewDrag(event.source, data);
            return;
        }

        if (data.type === 'anabelka-builder-preview-order') {
            handlePreviewOrder(event.source, data);
            return;
        }

        if (data.type === 'anabelka-builder-preview-drop') {
            finishPreviewDrag(event.source, data, false);
            return;
        }

        if (data.type === 'anabelka-builder-preview-cancel') {
            finishPreviewDrag(event.source, data, true);
            return;
        }

        if (data.type === 'anabelka-builder-preview-select') {
            const blockId = String(data.blockId || '');

            if (blockId) {
                selectBuilderCard(blockId, true);
                broadcastHighlight(blockId);
            }
        }
    });

    document.addEventListener('pointermove', moveDrag, {
        passive: false
    });
    document.addEventListener('pointerup', function (event) {
        finishDrag(event, false);
    });
    document.addEventListener('pointercancel', function (event) {
        finishDrag(event, true);
    });

    let resizeTimer = 0;
    window.addEventListener('resize', function () {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(resizePreviews, 60);
    });

    window.requestAnimationFrame(function () {
        collapseAllHomeBlocks();
        activatePreview('mobile');
        resizePreviews();
    });
}());
