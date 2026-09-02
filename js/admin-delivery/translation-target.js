/*
 * Перехід із центру перекладів до конкретного елемента Delivery.
 *
 * Основна ціль визначається стандартним URL-якорем #delivery-... .
 * JavaScript лише розкриває батьківські рівні, додає запасний клас
 * підсвічування та прокручує картку до центру екрана.
 */
(function () {
    'use strict';

    function getQueryTarget()
    {
        const params = new URLSearchParams(window.location.search);
        const id = String(params.get('highlight') || '').trim();
        let type = String(params.get('highlight_type') || '').trim();

        if (!/^\d+$/.test(id)) {
            return null;
        }

        if (type === 'option_input') {
            type = 'option';
        }

        if (!['method', 'service', 'option'].includes(type)) {
            return null;
        }

        return document.querySelector(
            '[data-delivery-target-type="' + type + '"]'
            + '[data-delivery-target-id="' + id + '"]'
        );
    }


    function getTarget()
    {
        const hash = String(window.location.hash || '');

        if (!hash || hash === '#') {
            return getQueryTarget()
                || document.querySelector('.delivery-translation-target');
        }

        try {
            return document.getElementById(
                decodeURIComponent(hash.slice(1))
            ) || getQueryTarget();
        } catch (error) {
            return getQueryTarget();
        }
    }


    function openParents(row)
    {
        let current = row;

        while (current) {
            const container = current.closest('.admin-tree-children');

            if (!container) {
                break;
            }

            container.hidden = false;

            let button = null;

            if (container.dataset.methodChildren) {
                button = document.querySelector(
                    '[data-collapse-method="'
                    + container.dataset.methodChildren
                    + '"]'
                );
            } else if (container.dataset.serviceChildren) {
                button = document.querySelector(
                    '[data-collapse-service="'
                    + container.dataset.serviceChildren
                    + '"]'
                );
            }

            if (button) {
                button.setAttribute('aria-expanded', 'true');
                button.classList.remove('is-collapsed');
            }

            current = container.parentElement;
        }
    }


    function init()
    {
        const target = getTarget();

        if (!target) {
            document
                .querySelectorAll('.delivery-translation-target')
                .forEach(function (item) {
                    item.classList.remove('delivery-translation-target');
                });

            return;
        }

        const row = target.classList.contains('admin-tree-row')
            ? target
            : target.closest('.admin-tree-row');

        if (!row) {
            return;
        }

        openParents(row);

        document
            .querySelectorAll('.delivery-translation-target')
            .forEach(function (item) {
                item.classList.remove('delivery-translation-target');
            });

        row.classList.add('delivery-translation-target');

        window.setTimeout(function () {
            row.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
        }, 120);
    }


    init();

    window.addEventListener('hashchange', init);
})();
