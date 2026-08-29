/**
 * Показать универсальное сообщение.
 *
 * @param {string} text
 */
window.showMessage =
    function (text)
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
    };


/**
 * Сохранить настройку дополнительного поля,
 * которое покупатель заполняет для опции доставки.
 */
window.saveDeliveryOptionInput =
    async function (
        optionId,
        isEnabled,
        fieldLabel,
        placeholder
    ) {
        const formData =
            new FormData();

        formData.append(
            'option_id',
            String(optionId)
        );

        formData.append(
            'is_enabled',
            isEnabled ? '1' : '0'
        );

        formData.append(
            'field_label',
            fieldLabel || ''
        );

        formData.append(
            'placeholder',
            placeholder || ''
        );

        const response =
            await fetch(
                '/Anabelka/admin/delivery/option-input',
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
                || 'Не удалось сохранить настройку поля доставки.'
            );
        }

        return responseText !== ''
            ? JSON.parse(responseText)
            : null;
    };