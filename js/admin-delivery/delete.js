/*
 * ========================================
 * УДАЛЕНИЕ DELIVERY
 * ========================================
 *
 * После структурного изменения страница
 * перезагружается. PHP заново строит дерево,
 * поэтому не остаются старые wrapper/кнопки "+".
 */

(function () {
    'use strict';

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


    document
        .querySelectorAll('.delete-button')
        .forEach(
            (button) => {
                button.addEventListener(
                    'click',
                    function ()
                    {
                        if (
                            !deleteModal
                            || !deleteType
                            || !deleteId
                            || !deleteText
                            || !deleteWarning
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

                        deleteType.value = type;
                        deleteId.value = id;

                        deleteText.textContent =
                            'Удалить «'
                            + name
                            + '»?';

                        deleteWarning.style.display =
                            (
                                type === 'method'
                                || type === 'service'
                            )
                                ? 'block'
                                : 'none';

                        deleteModal.hidden = false;
                    }
                );
            }
        );


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
                            deleteModal.hidden = true;
                        }
                    }
                );
            }
        );


    if (
        !deleteForm
        || !deleteModal
        || !deleteType
        || !deleteId
    ) {
        return;
    }


    deleteForm.addEventListener(
        'submit',
        async function (event)
        {
            event.preventDefault();

            const formData =
                new FormData(deleteForm);

            try {
                const response =
                    await fetch(
                        deleteForm.action,
                        {
                            method: 'POST',
                            body: formData,
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
                        || 'Не удалось удалить.'
                    );
                }

                deleteModal.hidden = true;

                window.showMessage('Удалено');

                setTimeout(
                    () => window.location.reload(),
                    300
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
})();
