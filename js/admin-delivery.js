/*
 * Сообщение пользователю.
 */
/**
 * @param {string} text
 */
function showMessage(text)
{
    const message =
        document.getElementById(
            'site-message'
        );

    if (!message) {
        return;
    }


    message.textContent =
        text;

    message.classList.add(
        'show'
    );


    clearTimeout(
        window.siteMessageTimer
    );


    window.siteMessageTimer =
        setTimeout(
            () => {

                message.classList.remove(
                    'show'
                );

            },
            2200
        );
}


/*
 * AJAX-переключение статуса.
 *
 * Используется одновременно для:
 *
 * - способов доставки;
 * - служб доставки;
 * - вариантов получения.
 */
document
    .querySelectorAll(
        '.toggle-form'
    )
    .forEach(
        (form) => {

            form.addEventListener(
                'submit',
                async function (event)
                {
                    event.preventDefault();


                    const row =
                        form.closest(
                            '.delivery-row'
                        );


                    if (!row) {
                        return;
                    }


                    const status =
                        row.querySelector(
                            '.row-status'
                        );


                    const input =
                        form.querySelector(
                            'input[name="is_active"]'
                        );


                    if (!status || !input) {
                        return;
                    }


                    const formData =
                        new FormData(
                            form
                        );


                    try {

                        const response =
                            await fetch(
                                form.action,
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


                        if (!response.ok) {

                            throw new Error(
                                'Не удалось изменить статус.'
                            );
                        }


                        const isActive =
                            formData.get(
                                'is_active'
                            ) === '1';


                        /*
                         * Меняем значок статуса.
                         */
                        status.textContent =
                            isActive
                                ? '✓'
                                : '×';


                        status.classList.toggle(
                            'active',
                            isActive
                        );


                        status.classList.toggle(
                            'inactive',
                            !isActive
                        );


                        status.title =
                            isActive
                                ? 'Включено'
                                : 'Выключено';


                        /*
                         * Следующее нажатие должно
                         * выполнить обратное действие.
                         */
                        input.value =
                            isActive
                                ? '0'
                                : '1';


                        const button =
                            form.querySelector(
                                '.icon-button'
                            );


                        if (button) {

                            button.title =
                                isActive
                                    ? 'Выключить'
                                    : 'Включить';
                        }


                        /*
                         * Название уровня берём
                         * из data-name формы.
                         */
                        const itemName =
                            form.dataset.name
                            || 'Элемент';


                        showMessage(
                            itemName
                            +
                            (
                                isActive
                                    ? ' включён'
                                    : ' выключен'
                            )
                        );

                    } catch (error) {

                        showMessage(
                            error.message
                        );
                    }

                }
            );

        }
    );
  /*
 * ========================================
 * РЕДАКТИРОВАНИЕ
 * ========================================
 */

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

const editSortOrder =
    document.getElementById(
        'edit-sort-order'
    );


/*
 * Открыть окно редактирования.
 */
document
    .querySelectorAll(
        '.edit-button'
    )
    .forEach(
        (button) => {

            button.addEventListener(
                'click',
                function ()
                {
                    if (!editModal) {
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

                    editSortOrder.value =
                        this.dataset.sortOrder
                        || 0;


                    editModal.hidden =
                        false;


                    /*
                     * Сразу ставим курсор
                     * в название.
                     */
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


/*
 * Закрыть окно.
 */
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

                        editModal.hidden =
                            true;
                    }
                }
            );

        }
    );


/*
 * Сохранить изменения.
 */
if (editForm) {

    editForm.addEventListener(
        'submit',
        async function (event)
        {
            event.preventDefault();


            const formData =
                new FormData(
                    editForm
                );


            try {

                const response =
                    await fetch(
                        editForm.action,
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
                        'Не удалось сохранить изменения.'
                    );
                }


                /*
                 * Находим кнопку,
                 * которую редактировали.
                 */
                const selector =
                    '.edit-button'
                    + '[data-type="'
                    + editType.value
                    + '"]'
                    + '[data-id="'
                    + editId.value
                    + '"]';


                const editButton =
                    document.querySelector(
                        selector
                    );


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
                             * Для option оставляем
                             * точку перед названием.
                             */
                            if (
                                editType.value
                                === 'option'
                            ) {

                                nameElement.textContent =
                                    '• '
                                    + editName.value;

                            } else {

                                nameElement.textContent =
                                    editName.value;
                            }
                        }


                        const descriptionElement =
                            row.querySelector(
                                '.delivery-description'
                            );


                        if (descriptionElement) {

                            descriptionElement.textContent =
                                editDescription.value;

                        }
                    }


                    /*
                     * Обновляем data-*,
                     * чтобы повторное открытие
                     * показывало новые значения.
                     */
                    editButton.dataset.name =
                        editName.value;

                    editButton.dataset.description =
                        editDescription.value;

                    editButton.dataset.sortOrder =
                        editSortOrder.value;
                }


                editModal.hidden =
                    true;


                showMessage(
                    'Изменения сохранены'
                );

            } catch (error) {

                showMessage(
                    error.message
                );
            }

        }
    );
}

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
                    if (!deleteModal) {
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
                     * У способа доставки
                     * или службы могут быть
                     * вложенные элементы.
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
if (deleteForm) {

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
                     * Если удаляется способ,
                     * удаляем всю карточку.
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


                showMessage(
                    'Удалено'
                );

            } catch (error) {

                showMessage(
                    error.message
                );
            }

        }
    );
}