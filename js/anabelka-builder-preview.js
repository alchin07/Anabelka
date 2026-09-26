(function () {
    'use strict';

    const root = document.body;
    const builder = root
        ? String(root.dataset.anabelkaBuilderPreview || '')
        : '';

    if (!builder || window.parent === window || !window.PointerEvent) {
        return;
    }

    const allowedOrigin = window.location.origin;
    let activeDrag = null;
    let savePending = false;
    let suppressClickUntil = 0;

    function blockNodes(zone)
    {
        return Array.from(document.querySelectorAll(
            '[data-anabelka-builder-zone="' + zone + '"]'
        ));
    }


    function orderIds(zone)
    {
        return blockNodes(zone).map(function (node) {
            return String(
                node.dataset.anabelkaBuilderBlockId || ''
            );
        }).filter(Boolean);
    }


    function sameOrder(left, right)
    {
        return left.length === right.length
            && left.every(function (value, index) {
                return String(value) === String(right[index]);
            });
    }


    function visualNode(wrapper)
    {
        return wrapper && wrapper.firstElementChild
            ? wrapper.firstElementChild
            : null;
    }


    function send(type, payload)
    {
        window.parent.postMessage(
            Object.assign({
                type: type,
                builder: builder
            }, payload || {}),
            allowedOrigin
        );
    }


    function applyOrder(zone, blockIds)
    {
        const nodes = blockNodes(zone);

        if (!nodes.length || !Array.isArray(blockIds)) {
            return;
        }

        const byId = new Map();

        nodes.forEach(function (node) {
            byId.set(
                String(node.dataset.anabelkaBuilderBlockId || ''),
                node
            );
        });

        blockIds.forEach(function (id) {
            const node = byId.get(String(id));

            if (node && node.parentNode) {
                node.parentNode.appendChild(node);
            }
        });
    }


    function highlight(blockId)
    {
        const id = String(blockId || '');

        document.querySelectorAll(
            '[data-anabelka-builder-block-id]'
        ).forEach(function (node) {
            node.classList.toggle(
                'is-builder-highlighted',
                id !== ''
                    && String(
                        node.dataset.anabelkaBuilderBlockId || ''
                    ) === id
            );
        });
    }


    function setSavePending(value)
    {
        savePending = Boolean(value);
        document.body.classList.toggle(
            'is-builder-preview-saving',
            savePending
        );

        document.querySelectorAll(
            '[data-anabelka-preview-drag-handle]'
        ).forEach(function (handle) {
            handle.disabled = savePending;
        });
    }


    function restoreOrder(zone, ids)
    {
        applyOrder(zone, ids);
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
                Math.max(
                    6,
                    (clientY - (viewportHeight - edge)) / 3
                )
            );
        }
    }


    function startDrag(event)
    {
        if (activeDrag || savePending) {
            return;
        }

        if (event.pointerType === 'mouse' && event.button !== 0) {
            return;
        }

        const handle = event.currentTarget;
        const wrapper = handle.closest(
            '[data-anabelka-builder-block-id]'
        );

        if (!wrapper) {
            return;
        }

        const zone = String(
            wrapper.dataset.anabelkaBuilderZone || ''
        );
        const blockId = String(
            wrapper.dataset.anabelkaBuilderBlockId || ''
        );

        if (!zone || !blockId) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        activeDrag = {
            pointerId: event.pointerId,
            handle: handle,
            wrapper: wrapper,
            zone: zone,
            blockId: blockId,
            originalIds: orderIds(zone),
            moved: false
        };

        wrapper.classList.add('is-builder-dragging');
        document.body.classList.add('is-builder-preview-dragging');
        highlight(blockId);

        try {
            handle.setPointerCapture(event.pointerId);
        } catch (error) {
            // Document-level pointer listeners remain available.
        }

        send('anabelka-builder-preview-drag-start', {
            zone: zone,
            blockId: blockId,
            blockIds: activeDrag.originalIds
        });
    }


    function moveDrag(event)
    {
        const state = activeDrag;

        if (!state || event.pointerId !== state.pointerId) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        autoScroll(event.clientY);

        const parent = state.wrapper.parentNode;

        if (!parent) {
            return;
        }

        const beforeIds = orderIds(state.zone);
        const siblings = blockNodes(state.zone).filter(
            function (node) {
                return node !== state.wrapper
                    && node.parentNode === parent;
            }
        );
        let insertBefore = null;

        for (const sibling of siblings) {
            const siblingVisual = visualNode(sibling);

            if (!siblingVisual) {
                continue;
            }

            const rect = siblingVisual.getBoundingClientRect();
            const midpoint = rect.top + (rect.height / 2);

            if (event.clientY < midpoint) {
                insertBefore = sibling;
                break;
            }
        }

        if (insertBefore) {
            parent.insertBefore(
                state.wrapper,
                insertBefore
            );
        } else {
            parent.appendChild(state.wrapper);
        }

        const nextIds = orderIds(state.zone);

        if (sameOrder(beforeIds, nextIds)) {
            return;
        }

        state.moved = true;

        send('anabelka-builder-preview-order', {
            zone: state.zone,
            blockId: state.blockId,
            blockIds: nextIds
        });
    }


    function finishDrag(event, cancelled)
    {
        const state = activeDrag;

        if (!state || event.pointerId !== state.pointerId) {
            return;
        }

        activeDrag = null;
        suppressClickUntil = Date.now() + 350;

        try {
            if (
                state.handle.hasPointerCapture
                && state.handle.hasPointerCapture(state.pointerId)
            ) {
                state.handle.releasePointerCapture(state.pointerId);
            }
        } catch (error) {
            // Some browsers release pointer capture automatically.
        }

        state.wrapper.classList.remove('is-builder-dragging');
        document.body.classList.remove('is-builder-preview-dragging');

        if (cancelled) {
            restoreOrder(state.zone, state.originalIds);
            send('anabelka-builder-preview-cancel', {
                zone: state.zone,
                blockId: state.blockId,
                blockIds: state.originalIds
            });
            highlight('');
            return;
        }

        const nextIds = orderIds(state.zone);

        if (!state.moved || sameOrder(state.originalIds, nextIds)) {
            send('anabelka-builder-preview-cancel', {
                zone: state.zone,
                blockId: state.blockId,
                blockIds: state.originalIds
            });
            highlight('');
            return;
        }

        setSavePending(true);

        send('anabelka-builder-preview-drop', {
            zone: state.zone,
            blockId: state.blockId,
            blockIds: nextIds
        });
    }


    function selectFromPreview(event)
    {
        if (
            activeDrag
            || savePending
            || Date.now() < suppressClickUntil
        ) {
            event.preventDefault();
            event.stopPropagation();
            return;
        }

        const block = event.target && event.target.closest
            ? event.target.closest(
                '[data-anabelka-builder-block-id]'
            )
            : null;

        event.preventDefault();
        event.stopPropagation();

        if (!block) {
            return;
        }

        const blockId = String(
            block.dataset.anabelkaBuilderBlockId || ''
        );

        if (!blockId) {
            return;
        }

        highlight(blockId);

        send('anabelka-builder-preview-select', {
            blockId: blockId
        });
    }


    function installDragHandles()
    {
        document.querySelectorAll(
            '[data-anabelka-builder-block-id]'
        ).forEach(function (wrapper) {
            const target = visualNode(wrapper);

            if (!target) {
                return;
            }

            target.classList.add(
                'anabelka-builder-preview-drag-target'
            );

            const host = target.tagName === 'DETAILS'
                ? (target.querySelector('summary') || target)
                : target;

            if (
                host.querySelector(
                    ':scope > [data-anabelka-preview-drag-handle]'
                )
            ) {
                return;
            }

            const handle = document.createElement('button');
            handle.type = 'button';
            handle.className =
                'anabelka-builder-preview-drag-handle';
            handle.dataset.anabelkaPreviewDragHandle = '1';
            handle.setAttribute(
                'aria-label',
                'Перетягнути блок'
            );
            handle.setAttribute(
                'title',
                'Утримуйте та перетягуйте блок'
            );
            handle.innerHTML = '<span aria-hidden="true">⠿</span>';
            handle.addEventListener('pointerdown', startDrag);
            host.appendChild(handle);
        });
    }


    window.addEventListener('message', function (event) {
        if (
            event.origin !== allowedOrigin
            || event.source !== window.parent
        ) {
            return;
        }

        const data = event.data || {};

        if (data.builder !== builder) {
            return;
        }

        if (data.type === 'anabelka-builder-order') {
            applyOrder(
                String(data.zone || ''),
                Array.isArray(data.blockIds)
                    ? data.blockIds
                    : []
            );
            return;
        }

        if (data.type === 'anabelka-builder-highlight') {
            highlight(data.blockId);
            return;
        }

        if (data.type === 'anabelka-builder-preview-save-result') {
            setSavePending(false);

            if (Array.isArray(data.blockIds)) {
                applyOrder(
                    String(data.zone || ''),
                    data.blockIds
                );
            }

            highlight('');
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
    document.addEventListener('click', selectFromPreview, true);
    document.addEventListener('submit', function (event) {
        event.preventDefault();
        event.stopPropagation();
    }, true);

    installDragHandles();

    send('anabelka-builder-preview-ready');
}());
