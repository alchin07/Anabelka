// @ts-nocheck

/*
 * ========================================
 * ЗГОРТАННЯ ДЕРЕВА DELIVERY
 * ========================================
 *
 * Файл відповідає тільки за:
 * - hidden у дочірнього контейнера;
 * - aria-expanded;
 * - поворот стрілки;
 * - початково згорнутий стан дерева.
 *
 * Після кожного нового завантаження всі рівні згорнуті.
 * Виняток — батьківські рівні цілі, відкритої з центру перекладів.
 */

(function () {
    'use strict';

    function clearLegacyStoredState()
    {
        try {
            sessionStorage.removeItem(
                'delivery-collapsed-items'
            );
        } catch (error) {
            /* Недоступне сховище не повинно ламати дерево. */
        }
    }


    function getAnchorTarget()
    {
        const hash = String(window.location.hash || '');
        let target = null;

        if (hash && hash !== '#') {
            try {
                target = document.getElementById(
                    decodeURIComponent(hash.slice(1))
                );
            } catch (error) {
                target = null;
            }
        }

        if (target) {
            return target;
        }

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


    function setCollapsedState(
        button,
        children,
        isCollapsed
    ) {
        children.hidden =
            isCollapsed;

        button.setAttribute(
            'aria-expanded',
            isCollapsed
                ? 'false'
                : 'true'
        );

        button.classList.toggle(
            'is-collapsed',
            isCollapsed
        );
    }


    function initializeLevel(
        buttonSelector,
        dataName,
        childrenAttribute
    ) {
        const anchorTarget =
            getAnchorTarget();

        document
            .querySelectorAll(buttonSelector)
            .forEach(
                (button) => {

                    const id =
                        button.dataset[dataName];

                    if (!id) {
                        return;
                    }

                    const children =
                        document.querySelector(
                            '['
                            + childrenAttribute
                            + '="'
                            + id
                            + '"]'
                        );

                    if (!children) {
                        return;
                    }

                    const containsClassTarget =
                        children.querySelector(
                            '.delivery-translation-target'
                        ) !== null;

                    const containsAnchorTarget =
                        !!anchorTarget
                        && children.contains(anchorTarget);

                    const containsTranslationTarget =
                        containsClassTarget
                        || containsAnchorTarget;

                    const shouldCollapse =
                        !containsTranslationTarget;

                    setCollapsedState(
                        button,
                        children,
                        shouldCollapse
                    );
                }
            );
    }


    clearLegacyStoredState();

    initializeLevel(
        '[data-collapse-method]',
        'collapseMethod',
        'data-method-children'
    );

    initializeLevel(
        '[data-collapse-service]',
        'collapseService',
        'data-service-children'
    );


    document.addEventListener(
        'click',
        function (event)
        {
            const methodButton =
                event.target.closest(
                    '[data-collapse-method]'
                );

            if (methodButton) {
                const id =
                    methodButton.dataset.collapseMethod;

                const children =
                    document.querySelector(
                        '[data-method-children="'
                        + id
                        + '"]'
                    );

                if (!children) {
                    return;
                }

                const isCollapsed =
                    !children.hidden;

                setCollapsedState(
                    methodButton,
                    children,
                    isCollapsed
                );

                return;
            }


            const serviceButton =
                event.target.closest(
                    '[data-collapse-service]'
                );

            if (!serviceButton) {
                return;
            }

            const id =
                serviceButton.dataset.collapseService;

            const children =
                document.querySelector(
                    '[data-service-children="'
                    + id
                    + '"]'
                );

            if (!children) {
                return;
            }

            const isCollapsed =
                !children.hidden;

            setCollapsedState(
                serviceButton,
                children,
                isCollapsed
            );

        }
    );
})();
