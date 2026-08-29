/*
 * ========================================
 * ДОПОЛНИТЕЛЬНОЕ ПОЛЕ ОПЦИИ ДОСТАВКИ
 * ========================================
 *
 * Настройка берётся из admin/delivery.
 * Если у выбранной опции включён запрос данных,
 * поле показывается прямо под этой опцией.
 */

(function () {
    'use strict';

    function init()
    {
        const addressInput =
            document.getElementById(
                'delivery-address'
            );

        const addressGroup =
            document.getElementById(
                'delivery-address-group'
            );

        const form =
            document.querySelector(
                'form[action="/Anabelka/checkout"]'
            );

        if (!form) {
            return;
        }


        /*
         * ========================================
         * ГЕОМЕТРИЯ УРОВНЕЙ ДОСТАВКИ
         * ========================================
         *
         * В checkout.php остались старые inline-
         * отступы второго и третьего уровня.
         * Здесь принудительно перекрываем их,
         * чтобы все радиокнопки начинались
         * по одной вертикали.
         */
        document
            .querySelectorAll(
                '.delivery-services'
            )
            .forEach(
                (group) => {
                    group.style.setProperty(
                        'padding',
                        '14px 15px 15px',
                        'important'
                    );

                    group.style.setProperty(
                        'background',
                        '#efe3fa',
                        'important'
                    );

                    group.style.setProperty(
                        'border-top',
                        '1px solid var(--border-color)',
                        'important'
                    );
                }
            );

        document
            .querySelectorAll(
                '.delivery-service-options'
            )
            .forEach(
                (group) => {
                    group.style.setProperty(
                        'margin',
                        '8px -15px 0',
                        'important'
                    );

                    group.style.setProperty(
                        'padding',
                        '12px 15px',
                        'important'
                    );

                    group.style.setProperty(
                        'background',
                        '#e6d4f8',
                        'important'
                    );

                    group.style.setProperty(
                        'border-top',
                        '1px solid var(--border-color)',
                        'important'
                    );

                    group.style.setProperty(
                        'border-radius',
                        '0',
                        'important'
                    );
                }
            );


        /*
         * Создаём одно общее поле.
         * При выборе опции переносим его прямо
         * под соответствующую строку опции.
         */
        const extraGroup =
            document.createElement('div');

        extraGroup.id =
            'delivery-option-customer-input-group';

        extraGroup.hidden = true;

        extraGroup.style.setProperty(
            'width',
            '100%',
            'important'
        );

        extraGroup.style.setProperty(
            'box-sizing',
            'border-box',
            'important'
        );

        extraGroup.style.setProperty(
            'margin',
            '8px 0 12px',
            'important'
        );

        extraGroup.style.padding =
            '12px';

        extraGroup.style.border =
            '1px solid var(--border-color)';

        extraGroup.style.borderRadius =
            '10px';

        extraGroup.style.background =
            'var(--surface-color)';


        const extraLabel =
            document.createElement('label');

        extraLabel.setAttribute(
            'for',
            'delivery-option-customer-input'
        );

        extraLabel.style.display = 'block';
        extraLabel.style.marginBottom = '6px';
        extraLabel.style.fontWeight = '600';


        const extraInput =
            document.createElement('input');

        extraInput.id =
            'delivery-option-customer-input';

        extraInput.type = 'text';
        extraInput.name = 'delivery_option_input';
        extraInput.disabled = true;
        extraInput.autocomplete = 'off';

        extraInput.style.width = '100%';
        extraInput.style.boxSizing = 'border-box';
        extraInput.style.padding = '12px';
        extraInput.style.border =
            '1px solid var(--border-color)';
        extraInput.style.borderRadius = '10px';

        extraGroup.appendChild(extraLabel);
        extraGroup.appendChild(extraInput);

        form.appendChild(extraGroup);


        let requestNumber = 0;
        let activeOptionValue = '';


        function renderExtraLabel(text)
        {
            extraLabel.textContent = text;

            const requiredMark =
                document.createElement('span');

            requiredMark.textContent = ' *';
            requiredMark.style.color =
                'var(--primary-color)';
            requiredMark.style.fontWeight = 'bold';

            extraLabel.appendChild(requiredMark);
        }


        function restoreAddressField()
        {
            if (!addressInput || !addressGroup) {
                return;
            }

            addressInput.disabled = false;

            const selectedMethod =
                document.querySelector(
                    'input[name="delivery_method"]:checked'
                );

            const method =
                selectedMethod
                    ? selectedMethod.value
                    : '';

            if (method === 'pickup') {
                addressGroup.style.display = 'none';
                addressInput.required = false;
                return;
            }

            addressGroup.style.display = '';

            if (
                method === 'courier'
                || method === 'post'
            ) {
                addressInput.required = true;
            } else {
                addressInput.required = false;
            }
        }


        function hideExtraField(clearValue)
        {
            extraGroup.hidden = true;
            extraInput.required = false;
            extraInput.disabled = true;

            if (clearValue) {
                extraInput.value = '';
            }

            restoreAddressField();
        }


        function showExtraField(
            optionRadio,
            result
        ) {
            const optionRow =
                optionRadio.closest('label');

            if (!optionRow) {
                hideExtraField(true);
                return;
            }

            const label =
                String(
                    result.field_label
                    || 'Укажите данные доставки'
                );

            renderExtraLabel(label);

            extraInput.placeholder =
                String(
                    result.placeholder
                    || ''
                );

            optionRow.insertAdjacentElement(
                'afterend',
                extraGroup
            );

            extraGroup.hidden = false;
            extraInput.disabled = false;
            extraInput.required = true;

            if (addressInput && addressGroup) {
                addressGroup.style.display = 'none';
                addressInput.required = false;
                addressInput.disabled = true;
            }
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

            const optionValue =
                option ? option.value : '';

            const optionChanged =
                optionValue !== activeOptionValue;

            activeOptionValue = optionValue;

            hideExtraField(optionChanged);

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

                showExtraField(
                    option,
                    result
                );

            } catch (error) {
                hideExtraField(false);
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
