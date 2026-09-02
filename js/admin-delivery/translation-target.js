/*
 * Перехід із центру перекладів до конкретного елемента Delivery.
 *
 * Файл підключається безпосередньо після collapse.js, тому дерево вже
 * побудоване, а збережений стан згортання відновлено.
 */
(function () {
    'use strict';

    const STORAGE_KEY = 'delivery-collapsed-items';

    function ensureStyles()
    {
        if (document.getElementById('delivery-translation-target-styles')) {
            return;
        }

        const style = document.createElement('style');
        style.id = 'delivery-translation-target-styles';
        style.textContent = [
            '.admin-tree-row.delivery-translation-target {',
            '  scroll-margin-top: 120px;',
            '}',
            '.admin-tree-row.delivery-translation-target > .admin-tree-item {',
            '  box-shadow: 0 0 0 4px var(--primary-color), 0 10px 28px rgba(138,43,226,.28) !important;',
            '}',
            '.admin-tree-row.delivery-translation-target > .admin-tree-item > .edit-button {',
            '  box-shadow: 0 0 0 3px var(--primary-color) !important;',
            '}'
        ].join('\n');

        document.head.appendChild(style);
    }

    function removeCollapsedKey(key)
    {
        try {
            const stored = JSON.parse(
                sessionStorage.getItem(STORAGE_KEY) || '[]'
            );

            if (!Array.isArray(stored)) {
                return;
            }

            const next = stored.filter(function (item) {
                return item !== key;
            });

            sessionStorage.setItem(
                STORAGE_KEY,
                JSON.stringify(next)
            );
        } catch (error) {
            /* Хранилище не должно мешать переходу к элементу. */
        }
    }

    function openContainer(container)
    {
        if (!container) {
            return;
        }

        container.hidden = false;

        let button = null;
        let storageKey = '';

        if (container.dataset.methodChildren) {
            const id = container.dataset.methodChildren;
            button = document.querySelector(
                '[data-collapse-method="' + id + '"]'
            );
            storageKey = 'method:' + id;
        } else if (container.dataset.serviceChildren) {
            const id = container.dataset.serviceChildren;
            button = document.querySelector(
                '[data-collapse-service="' + id + '"]'
            );
            storageKey = 'service:' + id;
        }

        if (button) {
            button.setAttribute('aria-expanded', 'true');
            button.classList.remove('is-collapsed');
        }

        if (storageKey) {
            removeCollapsedKey(storageKey);
        }
    }

    function expandParents(row)
    {
        const containers = [];
        let current = row;

        while (current) {
            const container = current.closest('.admin-tree-children');

            if (!container) {
                break;
            }

            containers.push(container);
            current = container.parentElement;
        }

        containers.reverse().forEach(openContainer);
    }

    function findTarget(type, id)
    {
        if (type === 'option_input') {
            type = 'option';
        }

        const editButton = document.querySelector(
            '.edit-button[data-type="' + type + '"]'
            + '[data-id="' + id + '"]'
        );

        return editButton
            ? editButton.closest('.admin-tree-row')
            : null;
    }

    function init()
    {
        const params = new URLSearchParams(window.location.search);
        const id = String(params.get('highlight') || '').trim();
        const type = String(params.get('highlight_type') || '').trim();

        if (!/^\d+$/.test(id)) {
            return;
        }

        if (!['method', 'service', 'option', 'option_input'].includes(type)) {
            return;
        }

        const target = findTarget(type, id);

        if (!target) {
            return;
        }

        expandParents(target);
        ensureStyles();

        document
            .querySelectorAll('.delivery-translation-target')
            .forEach(function (row) {
                row.classList.remove('delivery-translation-target');
            });

        target.classList.add('delivery-translation-target');

        window.setTimeout(function () {
            target.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
        }, 100);
    }

    init();
})();
