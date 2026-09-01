/*
 * Перехід із центру перекладів до конкретного елемента адмінки.
 *
 * Після переходу потрібна картка прокручується в центр екрана
 * та підсвічується. Для Delivery батьківські рівні автоматично
 * розгортаються, якщо ціль знаходиться всередині згорнутої гілки.
 */
(function () {
    'use strict';

    function expandDeliveryParents(row)
    {
        let current = row;
        const containers = [];

        while (current) {
            const container = current.closest('.admin-tree-children');

            if (!container) {
                break;
            }

            containers.push(container);
            current = container.parentElement;
        }

        containers.reverse().forEach(function (container) {
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

            if (button && button.getAttribute('aria-expanded') !== 'true') {
                button.click();
            }
        });
    }


    function findProductTarget(id)
    {
        const button = document.querySelector(
            '.product-edit-button[data-product-id="' + id + '"]'
        );

        return button ? button.closest('.product-admin-row') : null;
    }


    function findDeliveryTarget(type, id)
    {
        if (type === 'option_input') {
            type = 'option';
        }

        const moveButton = document.querySelector(
            '[data-move-type="' + type + '"]'
            + '[data-move-id="' + id + '"]'
        );

        return moveButton ? moveButton.closest('.admin-tree-row') : null;
    }


    function highlightTarget()
    {
        const params = new URLSearchParams(window.location.search);
        const id = String(params.get('highlight') || '').trim();

        if (!/^\d+$/.test(id)) {
            return;
        }

        const path = window.location.pathname.replace(/\/$/, '');
        let target = null;

        if (path === '/Anabelka/admin/products') {
            target = findProductTarget(id);
        } else if (path === '/Anabelka/admin/delivery') {
            const type = String(
                params.get('highlight_type') || ''
            ).trim();

            if (!['method', 'service', 'option', 'option_input'].includes(type)) {
                return;
            }

            target = findDeliveryTarget(type, id);

            if (target) {
                expandDeliveryParents(target);
            }
        }

        if (!target) {
            return;
        }

        target.classList.add('is-translation-target');

        window.setTimeout(function () {
            target.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
        }, 180);
    }


    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', highlightTarget);
    } else {
        highlightTarget();
    }
})();
