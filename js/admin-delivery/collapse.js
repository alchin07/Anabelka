// @ts-nocheck

/*
 * ========================================
 * СВОРАЧИВАНИЕ ДЕРЕВА DELIVERY
 * ========================================
 */

const deliveryCollapseStorageKey =
    'delivery-collapsed-items';


function getCollapsedItems()
{
    try {

        return JSON.parse(
            sessionStorage.getItem(
                deliveryCollapseStorageKey
            )
        ) || [];

    } catch (error) {

        return [];
    }
}


function saveCollapsedItems(items)
{
    sessionStorage.setItem(
        deliveryCollapseStorageKey,
        JSON.stringify(
            items
        )
    );
}


/*
 * Устанавливаем состояние
 * раскрытия / сворачивания.
 */
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


/*
 * Кнопка "+"
 * добавления службы.
 *
 * Показываем её только
 * у раскрытого способа доставки.
 */
/*
 * Показываем строку с кнопкой "+"
 * только у раскрытого способа доставки.
 */
/*
 * Показываем строку с кнопкой "+"
 * только у раскрытого способа доставки.
 */
function setMethodAddButtonState(
    methodId,
    isCollapsed
) {
    const collapseButton =
        document.querySelector(
            '[data-collapse-method="'
            + methodId
            + '"]'
        );


    if (!collapseButton) {
        return;
    }


    const deliveryCard =
        collapseButton.closest(
            '.delivery-card'
        );


    if (!deliveryCard) {
        return;
    }


    const addRow =
        deliveryCard.querySelector(
            ':scope > .admin-tree-row:not(.no-add)'
        );


    if (!addRow) {
        return;
    }


    addRow.style.display =
        isCollapsed
            ? 'none'
            : '';
}

/*
 * Показываем строку с кнопкой "+"
 * только у раскрытой службы доставки.
 */
function setServiceAddButtonState(
    serviceId,
    isCollapsed
) {
    const collapseButton =
        document.querySelector(
            '[data-collapse-service="'
            + serviceId
            + '"]'
        );


    if (!collapseButton) {
        return;
    }


    const serviceCard =
        collapseButton.closest(
            '.delivery-service'
        );


    if (!serviceCard) {
        return;
    }


    const addRow =
        serviceCard.querySelector(
            ':scope > .admin-tree-row:not(.no-add)'
        );


    if (!addRow) {
        return;
    }


    addRow.style.display =
        isCollapsed
            ? 'none'
            : '';
}


/*
 * ========================================
 * ВОССТАНОВЛЕНИЕ СОСТОЯНИЯ
 * ========================================
 */

const collapsedItems =
    getCollapsedItems();


/*
 * Способы доставки.
 */
document
    .querySelectorAll(
        '[data-collapse-method]'
    )
    .forEach(
        (button) => {

            const id =
                button.dataset.collapseMethod;


            const children =
                document.querySelector(
                    '[data-method-children="'
                    + id
                    + '"]'
                );


            if (!children) {
                return;
            }


            const key =
                'method:' + id;


            const isCollapsed =
                collapsedItems.includes(
                    key
                );


            setCollapsedState(
                button,
                children,
                isCollapsed
            );


            setMethodAddButtonState(
                id,
                isCollapsed
            );
        }
    );


/*
 * Службы доставки.
 */
document
    .querySelectorAll(
        '[data-collapse-service]'
    )
    .forEach(
        (button) => {

            const id =
                button.dataset.collapseService;


            const children =
                document.querySelector(
                    '[data-service-children="'
                    + id
                    + '"]'
                );


            if (!children) {
                return;
            }


            const key =
                'service:' + id;


            const isCollapsed =
                collapsedItems.includes(
                    key
                );


            setCollapsedState(
                button,
                children,
                isCollapsed
            );

            setServiceAddButtonState(
              id,
              isCollapsed
            );
        }
    );


/*
 * ========================================
 * НАЖАТИЕ НА КНОПКУ
 * ========================================
 */

document.addEventListener(
    'click',
    function (event)
    {
        /*
         * ------------------------------
         * СПОСОБ ДОСТАВКИ
         * ------------------------------
         */

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


            const key =
                'method:' + id;


            const items =
                getCollapsedItems();


            const isCollapsed =
                !children.hidden;


            if (isCollapsed) {

                if (!items.includes(key)) {

                    items.push(
                        key
                    );
                }

            } else {

                const index =
                    items.indexOf(
                        key
                    );


                if (index !== -1) {

                    items.splice(
                        index,
                        1
                    );
                }
            }


            saveCollapsedItems(
                items
            );


            setCollapsedState(
                methodButton,
                children,
                isCollapsed
            );


            setMethodAddButtonState(
                id,
                isCollapsed
            );


            return;
        }


        /*
         * ------------------------------
         * СЛУЖБА ДОСТАВКИ
         * ------------------------------
         */

        const serviceButton =
            event.target.closest(
                '[data-collapse-service]'
            );


        if (serviceButton) {

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


            const key =
                'service:' + id;


            const items =
                getCollapsedItems();


            const isCollapsed =
                !children.hidden;


            if (isCollapsed) {

                if (!items.includes(key)) {

                    items.push(
                        key
                    );
                }

            } else {

                const index =
                    items.indexOf(
                        key
                    );


                if (index !== -1) {

                    items.splice(
                        index,
                        1
                    );
                }
            }


            saveCollapsedItems(
                items
            );


            setCollapsedState(
                serviceButton,
                children,
                isCollapsed
            );

            setServiceAddButtonState(
              id,
              isCollapsed
            );
        }
    }
);