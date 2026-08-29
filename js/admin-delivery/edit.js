/*
 * ========================================
 * РЕДАКТИРОВАНИЕ DELIVERY
 * ========================================
 */

(function () {
    'use strict';

    const editModal =
        document.getElementById(
            'delivery-edit-modal'
        );

    const editForm =
        document.getElementById(
            'delivery-edit-form'
        );

    const editType =
        document.getElementById(
            'edit-type'
        );

    const editId =
        document.getElementById(
            'edit-id'
        );

    const editName =
        document.getElementById(
            'edit-name'
        );

    const editDescription =
        document.getElementById(
            'edit-description'
        );


    document
        .querySelectorAll('.edit-button')
        .forEach(
            (button) => {
                button.addEventListener(
                    'click',
                    function ()
                    {
                        if (
                            !editModal
                            || !editType
                            || !editId
                            || !editName
                            || !editDescription
                        ) {
                            return;
                        }

                        editType.value =
                            this.dataset.type
                            || '';

                        editId.value =
                            this.dataset.id
                            || '';

                        editName.value =
                            this.dataset.name
                            || '';

                        editDescription.value =
                            this.dataset.description
                            || '';

                        editModal.hidden = false;

                        setTimeout(
                            () => {
                                editName.focus();
                                editName.select();
                            },
                            50
                        );
                    }
                );
            }
        );


    document
        .querySelectorAll(
            '[data-close-modal]'
        )
        .forEach(
            (button) => {
                button.addEventListener(
                    'click',
                    function ()
                    {
                        if (editModal) {
                            editModal.hidden = true;
                        }
                    }
                );
            }
        );


    if (
        !editForm
        || !editModal
        || !editType
        || !editId
        || !editName
        || !editDescription
    ) {
        return;
    }


    editForm.addEventListener(
        'submit',
        async function (event)
        {
            event.preventDefault();

            const formData =
                new FormData(editForm);

            try {
                const response =
                    await fetch(
                        editForm.action,
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
                        || 'Не удалось сохранить изменения.'
                    );
                }

                const selector =
                    '.edit-button'
                    + '[data-type="'
                    + editType.value
                    + '"]'
                    + '[data-id="'
                    + editId.value
                    + '"]';

                const editButton =
                    document.querySelector(selector);

                if (editButton) {
                    const row =
                        editButton.closest(
                            '.delivery-row'
                        );

                    if (row) {
                        const nameElement =
                            row.querySelector(
                                '.delivery-name'
                            );

                        if (nameElement) {
                            /*
                             * Название выводим ровно таким,
                             * каким его ввёл пользователь.
                             * Никаких точек/маркеров для option.
                             */
                            nameElement.textContent =
                                editName.value;
                        }

                        const textContainer =
                            row.querySelector(
                                '.admin-tree-text'
                            );

                        let descriptionElement =
                            row.querySelector(
                                '.delivery-description'
                            );

                        const description =
                            editDescription.value.trim();

                        if (description !== '') {
                            if (
                                !descriptionElement
                                && textContainer
                            ) {
                                descriptionElement =
                                    document.createElement(
                                        'span'
                                    );

                                descriptionElement.className =
                                    'delivery-description';

                                textContainer.appendChild(
                                    descriptionElement
                                );
                            }

                            if (descriptionElement) {
                                descriptionElement.textContent =
                                    editDescription.value;
                            }

                        } else if (descriptionElement) {
                            descriptionElement.remove();
                        }

                        const deleteButton =
                            row.querySelector(
                                '.delete-button'
                            );

                        if (deleteButton) {
                            deleteButton.dataset.name =
                                editName.value;
                        }
                    }

                    editButton.dataset.name =
                        editName.value;

                    editButton.dataset.description =
                        editDescription.value;
                }

                editModal.hidden = true;

                window.showMessage(
                    'Изменения сохранены'
                );

            } catch (error) {
                window.showMessage(
                    error instanceof Error
                        ? error.message
                        : 'Не удалось сохранить изменения.'
                );
            }
        }
    );
})();
