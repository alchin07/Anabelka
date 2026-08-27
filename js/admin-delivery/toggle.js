/*
 * ========================================
 * ВКЛЮЧЕНИЕ / ВЫКЛЮЧЕНИЕ
 * ========================================
 *
 * Один обработчик используется для:
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
                         * Значок состояния.
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
                         * Следующее нажатие
                         * выполняет обратное действие.
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