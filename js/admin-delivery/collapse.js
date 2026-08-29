// @ts-nocheck

/*
 * ========================================
 * СВОРАЧИВАНИЕ ДЕРЕВА DELIVERY
 * ========================================
 *
 * Этот файл отвечает ТОЛЬКО за:
 * - hidden у дочернего контейнера;
 * - aria-expanded;
 * - поворот стрелки;
 * - сохранение состояния в sessionStorage.
 *
 * Он не перемещает и не скрывает кнопки "+"
 * отдельно от дочернего уровня.
 */

(function () {
    'use strict';

    const storageKey =
        'delivery-collapsed-items';


    function getCollapsedItems()
    {
        try {
            const value =
                JSON.parse(
                    sessionStorage.getItem(
                        storageKey
                    )
                );

            return Array.isArray(value)
                ? value
                : [];

        } catch (error) {
            return [];
        }
    }


    function saveCollapsedItems(items)
    {
        try {
            sessionStorage.setItem(
                storageKey,
                JSON.stringify(items)
            );
        } catch (error) {
            /*
             * Недоступное хранилище не должно
             * ломать само раскрытие дерева.
             */
        }
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


    function setStoredState(
        key,
        isCollapsed
    ) {
        const items =
            getCollapsedItems();

        const index =
            items.indexOf(key);

        if (isCollapsed) {

            if (index === -1) {
                items.push(key);
            }

        } else if (index !== -1) {

            items.splice(
                index,
                1
            );
        }

        saveCollapsedItems(items);
    }


    function restoreLevel(
        buttonSelector,
        dataName,
        childrenAttribute,
        keyPrefix
    ) {
        const collapsedItems =
            getCollapsedItems();

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

                    setCollapsedState(
                        button,
                        children,
                        collapsedItems.includes(
                            keyPrefix + id
                        )
                    );
                }
            );
    }


    restoreLevel(
        '[data-collapse-method]',
        'collapseMethod',
        'data-method-children',
        'method:'
    );

    restoreLevel(
        '[data-collapse-service]',
        'collapseService',
        'data-service-children',
        'service:'
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

                setStoredState(
                    'method:' + id,
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

            setStoredState(
                'service:' + id,
                isCollapsed
            );
        }
    );
})();
