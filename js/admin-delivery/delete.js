/*
 * ========================================
 * УДАЛЕНИЕ
 * ========================================
 */

const deleteModal =
    document.getElementById(
        'delivery-delete-modal'
    );

const deleteForm =
    document.getElementById(
        'delivery-delete-form'
    );

const deleteType =
    document.getElementById(
        'delete-type'
    );

const deleteId =
    document.getElementById(
        'delete-id'
    );

const deleteText =
    document.getElementById(
        'delivery-delete-text'
    );

const deleteWarning =
    document.getElementById(
        'delivery-delete-warning'
    );


/*
 * Открытие окна удаления.
 */
document
    .querySelectorAll(
        '.delete-button'
    )
    .forEach(
        (button) => {

            button.addEventListener(
                'click',
                function ()
                {
                    if (
                        !deleteModal
                        ||
                        !deleteType
                        ||
                        !deleteId
                        ||
                        !deleteText
                        ||
                        !deleteWarning
                    ) {
                        return;
                    }


                    const type =
                        this.dataset.type
                        || '';

                    const id =
                        this.dataset.id
                        || '';

                    const name =
                        this.dataset.name
                        || 'элемент';


                    deleteType.value =
                        type;

                    deleteId.value =
                        id;


                    deleteText.textContent =
                        'Удалить «'
                        + name
                        + '»?';


                    /*
                     * Для способа доставки
                     * и службы показываем
                     * предупреждение о вложениях.
                     */
                    if (
                        type === 'method'
                        ||
                        type === 'service'
                    ) {

                        deleteWarning.style.display =
                            'block';

                    } else {

                        deleteWarning.style.display =
                            'none';
                    }


                    deleteModal.hidden =
                        false;
                }
            );

        }
    );


/*
 * Закрытие окна удаления.
 */
document
    .querySelectorAll(
        '[data-close-delete-modal]'
    )
    .forEach(
        (button) => {

            button.addEventListener(
                'click',
                function ()
                {
                    if (deleteModal) {

                        deleteModal.hidden =
                            true;
                    }
                }
            );

        }
    );


/*
 * Подтверждение удаления.
 */
if (
    deleteForm
    &&
    deleteModal
    &&
    deleteType
    &&
    deleteId
) {

    deleteForm.addEventListener(
        'submit',
        async function (event)
        {
            event.preventDefault();


            const formData =
                new FormData(
                    deleteForm
                );


            try {

                const response =
                    await fetch(
                        deleteForm.action,
                        {
                            method:
                                'POST',

                            body:
                                formData,

                            headers: {
                                'X-Requested-With':
                                    'XMLHttpRequest'
                            }
                        }
                    );


                const responseText =
                    await response.text();


                if (!response.ok) {

                    throw new Error(
                        responseText
                        ||
                        'Не удалось удалить.'
                    );
                }


                const type =
                    deleteType.value;

                const id =
                    deleteId.value;


                const selector =
                    '.delete-button'
                    + '[data-type="'
                    + type
                    + '"]'
                    + '[data-id="'
                    + id
                    + '"]';


                const deleteButton =
                    document.querySelector(
                        selector
                    );


                if (deleteButton) {

                    const row =
                        deleteButton.closest(
                            '.delivery-row'
                        );


                    /*
                     * Способ доставки удаляем
                     * вместе со всей карточкой.
                     */
                    if (
                        type === 'method'
                    ) {

                        const card =
                            deleteButton.closest(
                                '.delivery-card'
                            );

                        if (card) {
                            card.remove();
                        }

                    } else if (row) {

                        row.remove();
                    }
                }


                deleteModal.hidden =
                    true;


                window.showMessage(
                    'Удалено'
                );

            } catch (error) {

                window.showMessage(
                    error instanceof Error
                        ? error.message
                        : 'Не удалось удалить.'
                );
            }

        }
    );
}