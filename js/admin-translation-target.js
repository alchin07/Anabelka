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

            if (
                button
                && (
                    container.hidden
                    || button.getAttribute('aria-expanded') !== 'true'
                )
            ) {
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

        /*
         * Основний ідентифікатор — кнопка редагування.
         * Вона вже має точні data-type/data-id на всіх рівнях Delivery.
         */
        let button = document.querySelector(
            '.edit-button[data-type="' + type + '"]'
            + '[data-id="' + id + '"]'
        );

        /*
         * Запасний варіант для старої розмітки.
         */
        if (!button) {
            button = document.querySelector(
                '[data-move-type="' + type + '"]'
                + '[data-move-id="' + id + '"]'
            );
        }

        return button ? button.closest('.admin-tree-row') : null;
    }


    function resolveTarget()
    {
        const params = new URLSearchParams(window.location.search);
        const id = String(params.get('highlight') || '').trim();

        if (!/^\d+$/.test(id)) {
            return null;
        }

        const path = window.location.pathname.replace(/\/$/, '');

        if (path === '/Anabelka/admin/products') {
            return findProductTarget(id);
        }

        if (path === '/Anabelka/admin/delivery') {
            const type = String(
                params.get('highlight_type') || ''
            ).trim();

            if (!['method', 'service', 'option', 'option_input'].includes(type)) {
                return null;
            }

            const target = findDeliveryTarget(type, id);

            if (target) {
                expandDeliveryParents(target);
            }

            return target;
        }

        return null;
    }


    function highlightTarget(attempt)
    {
        const target = resolveTarget();

        if (!target) {
            if (attempt < 5) {
                window.setTimeout(function () {
                    highlightTarget(attempt + 1);
                }, 120);
            }
            return;
        }

        document
            .querySelectorAll('.is-translation-target')
            .forEach(function (element) {
                element.classList.remove('is-translation-target');
            });

        target.classList.add('is-translation-target');

        window.setTimeout(function () {
            target.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
        }, 180);
    }


    function init()
    {
        highlightTarget(0);
    }


    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
