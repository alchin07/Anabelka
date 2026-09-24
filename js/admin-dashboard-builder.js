(function () {
    'use strict';

    if (!window.PointerEvent || !window.fetch) {
        return;
    }

    const root = document.querySelector(
        '[data-dashboard-builder-root]'
    );
    const blockList = root
        ? root.querySelector('[data-dashboard-builder-block-list]')
        : null;

    if (!root || !blockList) {
        return;
    }

    const blockEndpoint =
        '/Anabelka/admin/dashboard-builder/reorder-blocks';
    const linkEndpoint =
        '/Anabelka/admin/dashboard-builder/reorder-links';
    const messageElement = document.getElementById(
        'dashboard-builder-dnd-message'
    );

    let activeDrag = null;
    let saving = false;
    let messageTimer = 0;


    function setBlockCollapsed(block, collapsed)
    {
        if (!block) {
            return;
        }

        const isCollapsed = Boolean(collapsed);
        const button = block.querySelector(
            '[data-dashboard-builder-editor-toggle]'
        );

        block.classList.toggle(
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


    function collapseAllBlocks()
    {
        blockItems().forEach(function (block) {
            setBlockCollapsed(block, true);
        });
    }


    function blockItems()
    {
        return Array.from(
            blockList.querySelectorAll(
                ':scope > [data-dashboard-builder-block-id]'
            )
        );
    }


    function linkLists()
    {
        return Array.from(
            root.querySelectorAll(
                '[data-dashboard-builder-link-list]'
            )
        );
    }


    function linkItems(list)
    {
        return Array.from(
            list.querySelectorAll(
                ':scope > [data-dashboard-builder-link-id]'
            )
        );
    }


    function blockOrder()
    {
        return blockItems().map(function (block) {
            return String(
                block.dataset.dashboardBuilderBlockId || ''
            );
        }).filter(Boolean);
    }


    function layoutSnapshot()
    {
        return blockItems().map(function (block) {
            const blockId = String(
                block.dataset.dashboardBuilderBlockId || ''
            );
            const list = block.querySelector(
                '[data-dashboard-builder-link-list]'
            );

            return {
                block_id: blockId,
                link_ids: list
                    ? linkItems(list).map(function (item) {
                        return String(
                            item.dataset.dashboardBuilderLinkId || ''
                        );
                    }).filter(Boolean)
                    : []
            };
        });
    }


    function sameArray(left, right)
    {
        return left.length === right.length
            && left.every(function (value, index) {
                return String(value) === String(right[index]);
            });
    }


    function sameLayout(left, right)
    {
        if (left.length !== right.length) {
            return false;
        }

        return left.every(function (row, index) {
            const other = right[index] || {};

            return String(row.block_id || '')
                    === String(other.block_id || '')
                && sameArray(
                    Array.isArray(row.link_ids)
                        ? row.link_ids
                        : [],
                    Array.isArray(other.link_ids)
                        ? other.link_ids
                        : []
                );
        });
    }


    function syncEmptyState(list)
    {
        const placeholder = list.querySelector(
            ':scope > [data-dashboard-builder-links-empty]'
        );

        if (placeholder) {
            placeholder.hidden = linkItems(list).length > 0;
        }
    }


    function syncEmptyStates()
    {
        linkLists().forEach(syncEmptyState);
    }


    function restoreBlockOrder(ids)
    {
        const byId = new Map();

        blockItems().forEach(function (block) {
            byId.set(
                String(
                    block.dataset.dashboardBuilderBlockId || ''
                ),
                block
            );
        });

        ids.forEach(function (id) {
            const block = byId.get(String(id));

            if (block) {
                blockList.appendChild(block);
            }
        });
    }


    function restoreLinkLayout(layout)
    {
        const byId = new Map();

        root.querySelectorAll(
            '[data-dashboard-builder-link-id]'
        ).forEach(function (item) {
            byId.set(
                String(
                    item.dataset.dashboardBuilderLinkId || ''
                ),
                item
            );
        });

        layout.forEach(function (row) {
            const blockId = String(row.block_id || '');
            const list = linkLists().find(function (candidate) {
                return String(
                    candidate.dataset.dashboardBuilderBlockId || ''
                ) === blockId;
            });

            if (!list) {
                return;
            }

            (row.link_ids || []).forEach(function (linkId) {
                const item = byId.get(String(linkId));

                if (item) {
                    list.appendChild(item);
                }
            });
        });

        syncEmptyStates();
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
        messageElement.classList.toggle(
            'is-error',
            Boolean(isError)
        );
        messageElement.classList.toggle(
            'is-success',
            !isError
        );
        messageElement.hidden = false;

        messageTimer = window.setTimeout(function () {
            messageElement.hidden = true;
        }, isError ? 4800 : 2800);
    }


    function setSaving(value)
    {
        saving = Boolean(value);
        root.classList.toggle('is-saving', saving);

        if (saving) {
            root.setAttribute('aria-busy', 'true');
        } else {
            root.removeAttribute('aria-busy');
        }

        root.querySelectorAll(
            '[data-dashboard-builder-block-handle],'
            + '[data-dashboard-builder-link-handle]'
        ).forEach(function (handle) {
            handle.disabled = saving;
        });
    }


    function autoScroll(clientY)
    {
        const edge = 72;
        const viewportHeight = window.innerHeight || 0;

        if (clientY < edge) {
            window.scrollBy(
                0,
                -Math.max(6, (edge - clientY) / 3)
            );
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


    function blockForPoint(clientX, clientY)
    {
        const blocks = blockItems();
        let nearest = null;
        let nearestDistance = Infinity;

        blocks.forEach(function (block) {
            const rect = block.getBoundingClientRect();

            if (
                clientY >= rect.top
                && clientY <= rect.bottom
                && clientX >= rect.left - 24
                && clientX <= rect.right + 24
            ) {
                nearest = block;
                nearestDistance = 0;
                return;
            }

            const verticalDistance = clientY < rect.top
                ? rect.top - clientY
                : (
                    clientY > rect.bottom
                        ? clientY - rect.bottom
                        : 0
                );

            if (verticalDistance < nearestDistance) {
                nearest = block;
                nearestDistance = verticalDistance;
            }
        });

        return nearestDistance <= 90
            ? nearest
            : null;
    }


    async function requestJson(url, form)
    {
        const response = await fetch(url, {
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
                    : 'Сесія застаріла або сервер повернув помилку.'
            );
        }

        if (!response.ok || !result.success) {
            throw new Error(
                result.message
                    || 'Не вдалося зберегти новий порядок.'
            );
        }

        return result;
    }


    function requestBlockOrder(ids)
    {
        const form = new FormData();

        form.append(
            '_csrf',
            String(root.dataset.csrf || '')
        );

        ids.forEach(function (id) {
            form.append('block_ids[]', id);
        });

        return requestJson(blockEndpoint, form);
    }


    function requestLinkLayout(layout)
    {
        const form = new FormData();

        form.append(
            '_csrf',
            String(root.dataset.csrf || '')
        );
        form.append(
            'layout',
            JSON.stringify(layout)
        );

        return requestJson(linkEndpoint, form);
    }


    function beginDrag(event, kind)
    {
        if (activeDrag || saving) {
            return;
        }

        if (
            event.pointerType === 'mouse'
            && event.button !== 0
        ) {
            return;
        }

        const handle = event.currentTarget;
        const item = kind === 'block'
            ? handle.closest(
                '[data-dashboard-builder-block-id]'
            )
            : handle.closest(
                '[data-dashboard-builder-link-id]'
            );

        if (!item) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        if (kind === 'block') {
            setBlockCollapsed(item, true);
        }

        activeDrag = {
            kind: kind,
            pointerId: event.pointerId,
            handle: handle,
            item: item,
            originalBlocks: blockOrder(),
            originalLayout: layoutSnapshot()
        };

        item.classList.add('is-dragging');
        document.body.classList.add(
            'dashboard-builder-dragging'
        );

        try {
            handle.setPointerCapture(event.pointerId);
        } catch (error) {
            // Document listeners remain available without pointer capture.
        }
    }


    function moveBlockDrag(event, state)
    {
        const siblings = blockItems().filter(function (block) {
            return block !== state.item;
        });
        let insertBefore = null;

        for (const sibling of siblings) {
            const rect = sibling.getBoundingClientRect();
            const midpoint = rect.top + (rect.height / 2);

            if (event.clientY < midpoint) {
                insertBefore = sibling;
                break;
            }
        }

        const before = blockOrder();

        if (insertBefore) {
            blockList.insertBefore(
                state.item,
                insertBefore
            );
        } else {
            blockList.appendChild(state.item);
        }

        const after = blockOrder();

        if (!sameArray(before, after)) {
            state.moved = true;
        }
    }


    function clearDropTargets()
    {
        blockItems().forEach(function (block) {
            block.classList.remove(
                'is-link-drop-target'
            );
        });
    }


    function moveLinkDrag(event, state)
    {
        const targetBlock = blockForPoint(
            event.clientX,
            event.clientY
        );

        clearDropTargets();

        if (!targetBlock) {
            return;
        }

        const targetList = targetBlock.querySelector(
            '[data-dashboard-builder-link-list]'
        );

        if (!targetList) {
            return;
        }

        targetBlock.classList.add(
            'is-link-drop-target'
        );

        const before = layoutSnapshot();
        const siblings = linkItems(targetList).filter(
            function (item) {
                return item !== state.item;
            }
        );
        let insertBefore = null;

        for (const sibling of siblings) {
            const rect = sibling.getBoundingClientRect();
            const midpoint = rect.top + (rect.height / 2);

            if (event.clientY < midpoint) {
                insertBefore = sibling;
                break;
            }
        }

        if (insertBefore) {
            targetList.insertBefore(
                state.item,
                insertBefore
            );
        } else {
            targetList.appendChild(state.item);
        }

        syncEmptyStates();

        const after = layoutSnapshot();

        if (!sameLayout(before, after)) {
            state.moved = true;
        }
    }


    function moveDrag(event)
    {
        const state = activeDrag;

        if (
            !state
            || event.pointerId !== state.pointerId
        ) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        autoScroll(event.clientY);

        if (state.kind === 'block') {
            moveBlockDrag(event, state);
        } else {
            moveLinkDrag(event, state);
        }
    }


    async function finishDrag(event, cancelled)
    {
        const state = activeDrag;

        if (
            !state
            || event.pointerId !== state.pointerId
        ) {
            return;
        }

        activeDrag = null;

        try {
            if (
                state.handle.hasPointerCapture
                && state.handle.hasPointerCapture(
                    state.pointerId
                )
            ) {
                state.handle.releasePointerCapture(
                    state.pointerId
                );
            }
        } catch (error) {
            // Some browsers release capture automatically.
        }

        state.item.classList.remove('is-dragging');
        document.body.classList.remove(
            'dashboard-builder-dragging'
        );
        clearDropTargets();

        if (state.kind === 'block') {
            const nextIds = blockOrder();

            if (cancelled) {
                restoreBlockOrder(
                    state.originalBlocks
                );
                return;
            }

            if (
                !state.moved
                || sameArray(
                    state.originalBlocks,
                    nextIds
                )
            ) {
                return;
            }

            setSaving(true);

            try {
                const result = await requestBlockOrder(
                    nextIds
                );

                showMessage(
                    result.message
                        || 'Порядок блоків збережено.',
                    false
                );
            } catch (error) {
                restoreBlockOrder(
                    state.originalBlocks
                );
                showMessage(
                    error && error.message
                        ? error.message
                        : 'Не вдалося зберегти порядок блоків.',
                    true
                );
            } finally {
                setSaving(false);
            }

            return;
        }

        const nextLayout = layoutSnapshot();

        if (cancelled) {
            restoreLinkLayout(
                state.originalLayout
            );
            return;
        }

        if (
            !state.moved
            || sameLayout(
                state.originalLayout,
                nextLayout
            )
        ) {
            syncEmptyStates();
            return;
        }

        setSaving(true);

        try {
            const result = await requestLinkLayout(
                nextLayout
            );

            showMessage(
                result.message
                    || 'Розкладку ярликів збережено.',
                false
            );
        } catch (error) {
            restoreLinkLayout(
                state.originalLayout
            );
            showMessage(
                error && error.message
                    ? error.message
                    : 'Не вдалося зберегти розкладку ярликів.',
                true
            );
        } finally {
            setSaving(false);
            syncEmptyStates();
        }
    }


    root.querySelectorAll(
        '[data-dashboard-builder-editor-toggle]'
    ).forEach(function (button) {
        button.addEventListener('click', function () {
            const block = button.closest(
                '[data-dashboard-builder-block-id]'
            );

            if (!block || activeDrag || saving) {
                return;
            }

            setBlockCollapsed(
                block,
                !block.classList.contains('is-collapsed')
            );
        });
    });

    root.querySelectorAll(
        '[data-dashboard-builder-block-handle]'
    ).forEach(function (handle) {
        handle.addEventListener(
            'pointerdown',
            function (event) {
                beginDrag(event, 'block');
            }
        );
    });

    root.querySelectorAll(
        '[data-dashboard-builder-link-handle]'
    ).forEach(function (handle) {
        handle.addEventListener(
            'pointerdown',
            function (event) {
                beginDrag(event, 'link');
            }
        );
    });

    document.addEventListener(
        'pointermove',
        moveDrag,
        {passive: false}
    );
    document.addEventListener(
        'pointerup',
        function (event) {
            finishDrag(event, false);
        }
    );
    document.addEventListener(
        'pointercancel',
        function (event) {
            finishDrag(event, true);
        }
    );

    collapseAllBlocks();
    syncEmptyStates();
}());
