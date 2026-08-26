/*
 * ========================================
 * ДОБАВЛЕНИЕ СПОСОБА ДОСТАВКИ
 * ========================================
 */

const addModal =
    document.getElementById(
        'delivery-add-modal'
    );

const addForm =
    document.getElementById(
        'delivery-add-form'
    );

const addButton =
    document.querySelector(
        '.add-delivery'
    );

const addName =
    document.getElementById(
        'add-name'
    );

/*
 * Открытие окна.
 */
if (
    addButton
    &&
    addModal
    &&
    addForm
) {

    addButton.addEventListener(
        'click',
        function ()
        {
            /*
             * Каждый раз начинаем
             * с чистой формы.
             */
            addForm.reset();


            addModal.hidden =
                false;


            if (addName) {

                setTimeout(
                    () => {

                        addName.focus();

                    },
                    50
                );
            }
        }
    );
}


/*
 * Закрытие окна.
 */
document
    .querySelectorAll(
        '[data-close-add-modal]'
    )
    .forEach(
        (button) => {

            button.addEventListener(
                'click',
                function ()
                {
                    if (addModal) {

                        addModal.hidden =
                            true;
                    }
                }
            );

        }
    );

/*
 * Создание способа доставки.
 */
if (
    addForm
    &&
    addModal
) {

    addForm.addEventListener(
        'submit',
        async function (event)
        {
            event.preventDefault();


            const formData =
                new FormData(
                    addForm
                );


            try {

                const response =
                    await fetch(
                        addForm.action,
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
                        'Не удалось добавить способ доставки.'
                    );
                }


                /*
                 * Проверяем, что сервер
                 * действительно вернул JSON.
                 */
                let result;

                try {

                    result =
                        JSON.parse(
                            responseText
                        );

                } catch (error) {

                    throw new Error(
                        'Сервер вернул некорректный ответ.'
                    );
                }


                if (
                    !result
                    ||
                    result.success !== true
                ) {

                    throw new Error(
                        'Способ доставки не был создан.'
                    );
                }


                addModal.hidden =
                    true;


                window.showMessage(
                    'Способ доставки добавлен'
                );


                /*
                 * Новую карточку строит PHP
                 * из существующих partial.
                 *
                 * Не дублируем эту разметку
                 * внутри JavaScript.
                 */
                /*
 * Новый способ после создания
 * начинаем в свёрнутом состоянии.
 */
try {

    const storageKey =
        'delivery-collapsed-items';

    const collapsedItems =
        JSON.parse(
            sessionStorage.getItem(
                storageKey
            )
        ) || [];


    const newMethodKey =
        'method:' + result.id;


    if (
        !collapsedItems.includes(
            newMethodKey
        )
    ) {

        collapsedItems.push(
            newMethodKey
        );
    }


    sessionStorage.setItem(
        storageKey,
        JSON.stringify(
            collapsedItems
        )
    );

} catch (error) {

    /*
     * Если хранилище недоступно,
     * добавление всё равно работает.
     */
}


setTimeout(
    () => {

        window.location.reload();

    },
    500
);

            } catch (error) {

                window.showMessage(
                    error instanceof Error
                        ? error.message
                        : 'Не удалось добавить способ доставки.'
                );
            }

        }
    );
}

/*
 * ========================================
 * ДОБАВЛЕНИЕ ОПЦИИ ДОСТАВКИ
 * ТРЕТИЙ УРОВЕНЬ
 * ========================================
 */

const addOptionModal =
    document.getElementById(
        'delivery-add-option-modal'
    );

const addOptionForm =
    document.getElementById(
        'delivery-add-option-form'
    );

const addOptionServiceId =
    document.getElementById(
        'add-option-service-id'
    );

const addOptionName =
    document.getElementById(
        'add-option-name'
    );


/*
 * Открытие окна добавления опции.
 */
document
    .querySelectorAll(
        '.add-delivery-option'
    )
    .forEach(
        (button) => {

            button.addEventListener(
                'click',
                function ()
                {
                    if (
                        !addOptionModal
                        ||
                        !addOptionForm
                        ||
                        !addOptionServiceId
                    ) {

                        return;
                    }


                    /*
                     * Очищаем предыдущие данные.
                     */
                    addOptionForm.reset();


                    /*
                     * Запоминаем службу,
                     * которой принадлежит
                     * новая опция.
                     */
                    addOptionServiceId.value =
                        button.dataset.serviceId;


                    addOptionModal.hidden =
                        false;


                    if (addOptionName) {

                        setTimeout(
                            () => {

                                addOptionName.focus();

                            },
                            50
                        );
                    }
                }
            );

        }
    );


/*
 * Закрытие окна.
 */
document
    .querySelectorAll(
        '[data-close-add-option-modal]'
    )
    .forEach(
        (button) => {

            button.addEventListener(
                'click',
                function ()
                {
                    if (addOptionModal) {

                        addOptionModal.hidden =
                            true;
                    }
                }
            );

        }
    );


/*
 * Создание опции доставки.
 */
if (
    addOptionForm
    &&
    addOptionModal
) {

    addOptionForm.addEventListener(
        'submit',
        async function (event)
        {
            event.preventDefault();


            const formData =
                new FormData(
                    addOptionForm
                );


            try {

                const response =
                    await fetch(
                        addOptionForm.action,
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
                        'Не удалось добавить опцию доставки.'
                    );
                }


                let result;

                try {

                    result =
                        JSON.parse(
                            responseText
                        );

                } catch (error) {

                    throw new Error(
                        'Сервер вернул некорректный ответ.'
                    );
                }


                if (
                    !result
                    ||
                    result.success !== true
                ) {

                    throw new Error(
                        'Опция доставки не была создана.'
                    );
                }


                addOptionModal.hidden =
                    true;


                window.showMessage(
                    'Опция доставки добавлена'
                );


                /*
                 * Перезагружаем страницу.
                 *
                 * Состояние дерева хранится
                 * отдельно в sessionStorage,
                 * поэтому не раскрываем
                 * уровни вручную.
                 */
                setTimeout(
                    () => {

                        window.location.reload();

                    },
                    500
                );

            } catch (error) {

                window.showMessage(
                    error instanceof Error
                        ? error.message
                        : 'Не удалось добавить опцию доставки.'
                );
            }

        }
    );
}