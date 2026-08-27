/*
 * ========================================
 * ДОБАВЛЕНИЕ СЛУЖБЫ ДОСТАВКИ
 * ========================================
 */

const addServiceModal =
    document.getElementById(
        'delivery-add-service-modal'
    );

const addServiceForm =
    document.getElementById(
        'delivery-add-service-form'
    );

const addServiceButtons =
    document.querySelectorAll(
        '.add-delivery-service'
    );

const addServiceMethodId =
    document.getElementById(
        'add-service-method-id'
    );

const addServiceName =
    document.getElementById(
        'add-service-name'
    );


/*
 * Открытие окна.
 */
addServiceButtons.forEach(
    (button) => {

        button.addEventListener(
            'click',
            function ()
            {
                if (
                    !addServiceModal
                    ||
                    !addServiceForm
                    ||
                    !addServiceMethodId
                ) {
                    return;
                }


                addServiceForm.reset();


                addServiceMethodId.value =
                    this.dataset.methodId
                    ?? '';


                addServiceModal.hidden =
                    false;


                if (addServiceName) {

                    setTimeout(
                        () => {

                            addServiceName.focus();

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
        '[data-close-add-service-modal]'
    )
    .forEach(
        (button) => {

            button.addEventListener(
                'click',
                function ()
                {
                    if (addServiceModal) {

                        addServiceModal.hidden =
                            true;
                    }
                }
            );

        }
    );




/*
 * Создание службы доставки.
 */
if (
    addServiceForm
    &&
    addServiceModal
) {

    addServiceForm.addEventListener(
        'submit',
        async function (event)
        {
            event.preventDefault();


            const formData =
                new FormData(
                    addServiceForm
                );


            try {

                const response =
                    await fetch(
                        addServiceForm.action,
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
                        'Не удалось добавить службу доставки.'
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
                        'Служба доставки не была создана.'
                    );
                }


                addServiceModal.hidden =
                    true;


                window.showMessage(
                    'Служба доставки добавлена'
                );


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
                        : 'Не удалось добавить службу доставки.'
                );
            }

        }
    );
}