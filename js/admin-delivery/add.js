/*
 * ========================================
 * ДОБАВЛЕНИЕ СПОСОБА ДОСТАВКИ
 * ========================================
 *
 * Этот файл отвечает только за первый уровень.
 * Службы и опции имеют собственные файлы.
 */

(function () {
    'use strict';

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


    if (
        addButton
        && addModal
        && addForm
    ) {
        addButton.addEventListener(
            'click',
            function ()
            {
                addForm.reset();
                addModal.hidden = false;

                if (addName) {
                    setTimeout(
                        () => addName.focus(),
                        50
                    );
                }
            }
        );
    }


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
                            addModal.hidden = true;
                        }
                    }
                );
            }
        );


    if (
        !addForm
        || !addModal
    ) {
        return;
    }


    addForm.addEventListener(
        'submit',
        async function (event)
        {
            event.preventDefault();

            const formData =
                new FormData(addForm);

            try {
                const response =
                    await fetch(
                        addForm.action,
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
                        || 'Не удалось добавить способ доставки.'
                    );
                }

                let result;

                try {
                    result =
                        JSON.parse(responseText);
                } catch (error) {
                    throw new Error(
                        'Сервер вернул некорректный ответ.'
                    );
                }

                if (
                    !result
                    || result.success !== true
                ) {
                    throw new Error(
                        'Способ доставки не был создан.'
                    );
                }

                addModal.hidden = true;

                window.showMessage(
                    'Способ доставки добавлен'
                );

                /*
                 * Новый способ не должен раскрывать
                 * дерево целиком после перезагрузки.
                 */
                try {
                    const storageKey =
                        'delivery-collapsed-items';

                    const parsed =
                        JSON.parse(
                            sessionStorage.getItem(
                                storageKey
                            )
                        );

                    const collapsedItems =
                        Array.isArray(parsed)
                            ? parsed
                            : [];

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
                    /* Ничего: добавление уже выполнено. */
                }

                setTimeout(
                    () => window.location.reload(),
                    300
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
})();
