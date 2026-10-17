/**
 * Уведомления раздела доставки через единое ядро AnabelkaNotify.
 * При смене страницы сообщение показывается один раз после загрузки.
 *
 * @param {string} text
 * @param {'success'|'error'|'warning'|'info'} type
 * @param {boolean} afterReload
 * @returns {boolean}
 */
window.showMessage = function (text, type = 'info', afterReload = false)
{
    const notify = window.AnabelkaNotify;
    if (!notify || typeof notify.show !== 'function') {
        return false;
    }

    const level = ['success', 'error', 'warning', 'info'].includes(type)
        ? type : 'info';

    if (afterReload && typeof notify.store === 'function'
        && notify.store(level, text)) {
        return true;
    }

    return notify.show(level, text);
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
        placeholder,
        translations
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

        formData.append(
            'translations',
            JSON.stringify(
                translations || {}
            )
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
