/*
 * Перехід із центру перекладів до конкретного елемента Delivery.
 *
 * Цільова картка вже позначена PHP-класом delivery-translation-target.
 * JavaScript лише гарантує розкриття батьків та прокручує до неї.
 */
(function () {
    'use strict';

    function openParents(target)
    {
        let current = target;

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
        const target = document.querySelector(
            '.delivery-translation-target'
        );

        if (!target) {
            return;
        }

        openParents(target);

        window.setTimeout(function () {
            target.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
        }, 120);
    }

    init();
})();
