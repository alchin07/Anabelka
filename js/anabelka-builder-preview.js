(function () {
    'use strict';

    const root = document.body;
    const builder = root
        ? String(root.dataset.anabelkaBuilderPreview || '')
        : '';

    if (!builder || window.parent === window) {
        return;
    }

    const allowedOrigin = window.location.origin;

    function blockNodes(zone)
    {
        return Array.from(document.querySelectorAll(
            '[data-anabelka-builder-zone="' + zone + '"]'
        ));
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

    function selectFromPreview(event)
    {
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

        window.parent.postMessage({
            type: 'anabelka-builder-preview-select',
            builder: builder,
            blockId: blockId
        }, allowedOrigin);
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
        }
    });

    document.addEventListener('click', selectFromPreview, true);
    document.addEventListener('submit', function (event) {
        event.preventDefault();
        event.stopPropagation();
    }, true);

    window.parent.postMessage({
        type: 'anabelka-builder-preview-ready',
        builder: builder
    }, allowedOrigin);
}());
