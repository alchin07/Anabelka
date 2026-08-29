/*
 * ========================================
 * ДОБАВЛЕНИЕ ОПЦИИ ДОСТАВКИ
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

const addOptionButtons =
    document.querySelectorAll(
        '.add-delivery-option'
    );

const addOptionServiceId =
    document.getElementById(
        'add-option-service-id'
    );

const addOptionName =
    document.getElementById(
        'add-option-name'
    );

const addOptionCustomerInput =
    document.getElementById(
        'add-option-customer-input'
    );

const addOptionCustomerInputSettings =
    document.getElementById(
        'add-option-customer-input-settings'
    );

const addOptionCustomerInputLabel =
    document.getElementById(
        'add-option-customer-input-label'
    );

const addOptionCustomerInputPlaceholder =
    document.getElementById(
        'add-option-customer-input-placeholder'
    );


function updateAddOptionCustomerInputVisibility()
{
    if (!addOptionCustomerInputSettings) {
        return;
    }

    addOptionCustomerInputSettings.hidden =
        !(
            addOptionCustomerInput
            && addOptionCustomerInput.checked
        );
}


if (addOptionCustomerInput) {
    addOptionCustomerInput.addEventListener(
        'change',
        updateAddOptionCustomerInputVisibility
    );
}


/*
 * Открытие окна.
 */
addOptionButtons.forEach(
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


                addOptionForm.reset();
                updateAddOptionCustomerInputVisibility();


                addOptionServiceId.value =
                    this.dataset.serviceId
                    ?? '';


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


                let settingsSaved = true;

                if (
                    typeof window.saveDeliveryOptionInput
                    === 'function'
                ) {
                    try {
                        await window.saveDeliveryOptionInput(
                            result.id,
                            Boolean(
                                addOptionCustomerInput
                                && addOptionCustomerInput.checked
                            ),
                            addOptionCustomerInputLabel
                                ? addOptionCustomerInputLabel.value
                                : '',
                            addOptionCustomerInputPlaceholder
                                ? addOptionCustomerInputPlaceholder.value
                                : ''
                        );
                    } catch (error) {
                        settingsSaved = false;
                    }
                }


                addOptionModal.hidden =
                    true;


                window.showMessage(
                    settingsSaved
                        ? 'Опция доставки добавлена'
                        : 'Опция добавлена, но настройка поля не сохранена'
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
                        : 'Не удалось добавить опцию доставки.'
                );
            }

        }
    );
}