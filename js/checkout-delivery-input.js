/*
 * ========================================
 * ДОПОЛНИТЕЛЬНОЕ ПОЛЕ ОПЦИИ ДОСТАВКИ
 * ========================================
 *
 * Настройка берётся из admin/delivery.
 * Используем существующее поле delivery_address,
 * поэтому введённое значение уже сохраняется
 * вместе с заказом.
 */

(function () {
    'use strict';

    function init()
    {
        const addressInput =
            document.getElementById(
                'delivery-address'
            );

        const addressLabel =
            document.getElementById(
                'delivery-address-label'
            );

        if (!addressInput || !addressLabel) {
            return;
        }

        const defaultLabel = 'Адрес';
        const defaultPlaceholder =
            addressInput.getAttribute('placeholder')
            || 'Введите адрес';

        let requestNumber = 0;


        function renderLabel(text)
        {
            addressLabel.textContent = text;

            const requiredMark =
                document.createElement('span');

            requiredMark.textContent = '*';
            requiredMark.style.color =
                'var(--primary-color)';
            requiredMark.style.fontWeight =
                'bold';
            requiredMark.style.marginLeft =
                '4px';

            addressLabel.appendChild(
                requiredMark
            );
        }


        function restoreDefault()
        {
            renderLabel(defaultLabel);
            addressInput.placeholder =
                defaultPlaceholder;
        }


        async function updateFromSelection()
        {
            const method =
                document.querySelector(
                    'input[name="delivery_method"]:checked'
                );

            const service =
                document.querySelector(
                    'input[name="delivery_service"]:checked'
                );

            const option =
                document.querySelector(
                    'input[name="delivery_service_option"]:checked'
                );

            const currentRequest =
                ++requestNumber;

            restoreDefault();

            if (!method || !service || !option) {
                return;
            }

            const query =
                new URLSearchParams({
                    method: method.value,
                    service: service.value,
                    option: option.value
                });

            try {
                const response =
                    await fetch(
                        '/Anabelka/delivery/option-input?'
                        + query.toString(),
                        {
                            headers: {
                                'X-Requested-With':
                                    'XMLHttpRequest'
                            }
                        }
                    );

                if (
                    !response.ok
                    || currentRequest !== requestNumber
                ) {
                    return;
                }

                const result =
                    await response.json();

                if (
                    currentRequest !== requestNumber
                    || !result
                    || result.success !== true
                    || Number(result.is_enabled) !== 1
                ) {
                    return;
                }

                const label =
                    String(
                        result.field_label
                        || 'Укажите данные доставки'
                    );

                renderLabel(label);

                addressInput.placeholder =
                    String(
                        result.placeholder
                        || ''
                    );

            } catch (error) {
                /*
                 * При проблеме с запросом checkout
                 * остаётся полностью рабочим со
                 * стандартным полем адреса.
                 */
                restoreDefault();
            }
        }


        document.addEventListener(
            'change',
            function (event)
            {
                const target = event.target;

                if (!(target instanceof HTMLInputElement)) {
                    return;
                }

                if (
                    target.name === 'delivery_method'
                    || target.name === 'delivery_service'
                    || target.name === 'delivery_service_option'
                ) {
                    if (
                        target.name === 'delivery_service_option'
                    ) {
                        addressInput.value = '';
                    }

                    updateFromSelection();
                }
            }
        );


        updateFromSelection();
    }


    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            init
        );
    } else {
        init();
    }
})();
