<!DOCTYPE html>
<html lang="ru">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Оформление заказа — Анабелька
    </title>

    <link
        rel="stylesheet"
        href="/Anabelka/css/style.css?v=8"
    >

    <link
        rel="stylesheet"
        href="/Anabelka/css/catalog.css?v=4"
    >

</head>

<body>

    <?php

    $pageTitle =
        'Оформление заказа';

    require __DIR__
        . '/../partials/header.php';

    ?>


    <main class="catalog">

        <section
            class="product-card"
            style="
                max-width: 600px;
                margin: 0 auto;
                padding: 20px;
            "
        >

            <h2>
                Данные покупателя
            </h2>


            <form
                action="/Anabelka/checkout"
                method="POST"
            >

                <div style="margin-bottom: 15px;">

                    <label>
    Имя
    <span
        style="
            color: var(--primary-color);
            font-weight: bold;
        "
    >*</span>
                    </label>

                    <input
                        type="text"
                        name="customer_name"
                        required
                        value="<?= htmlspecialchars(
                            $_SESSION['user_name']
                            ?? ''
                        ) ?>"
                        style="
                            width: 100%;
                            box-sizing: border-box;
                            padding: 12px;
                            margin-top: 6px;
                            border:
                                1px solid
                                var(--border-color);
                            border-radius: 10px;
                        "
                    >

                </div>


                <div style="margin-bottom: 15px;">

                    <label>
    Email
    <span
        style="
            color: var(--primary-color);
            font-weight: bold;
        "
    >*</span>
                    </label>

                    <input
                        type="email"
                        name="customer_email"
                        required
                        style="
                            width: 100%;
                            box-sizing: border-box;
                            padding: 12px;
                            margin-top: 6px;
                            border:
                                1px solid
                                var(--border-color);
                            border-radius: 10px;
                        "
                    >

                </div>


                <div style="margin-bottom: 15px;">

                    <label>
                        Телефон
                    </label>

                    <input
                        type="tel"
                        name="customer_phone"
                        style="
                            width: 100%;
                            box-sizing: border-box;
                            padding: 12px;
                            margin-top: 6px;
                            border:
                                1px solid
                                var(--border-color);
                            border-radius: 10px;
                        "
                    >

                </div>

                <div<div
    class="delivery-section"
    style="
        margin-bottom: 20px;
    "
>

    <div
    style="
        margin-bottom: 8px;
        font-weight: 600;
    "
>
    Способ доставки
    <span
        style="
            color: var(--primary-color);
        "
    >*</span>
    </div>


    <div
        class="delivery-options"
        style="
            overflow: hidden;

            border:
                1px solid
                var(--border-color);

            border-radius: 14px;

            background:
                var(--surface-color);
        "
    >

        <?php foreach (
            $deliveryMethods as $method
        ): ?>

            <label
                class="delivery-option"
                style="
                    display: flex;
                    align-items: center;
                    gap: 14px;

                    padding: 15px;

                    cursor: pointer;
                "
            >

                <input
                    type="radio"
                    name="delivery_method"
                    value="<?= htmlspecialchars(
                        $method['slug']
                    ) ?>"
                    required
                    style="
                        width: 20px;
                        height: 20px;

                        accent-color:
                            var(--primary-color);
                    "
                >


                <span
                    style="
                        display: flex;
                        flex-direction: column;
                        gap: 3px;
                    "
                >

                    <strong>
                        <?= htmlspecialchars(
                            $method['name']
                        ) ?>
                    </strong>


                    <?php if (
                        !empty(
                            $method['description']
                        )
                    ): ?>

                        <small>
                            <?= htmlspecialchars(
                                $method['description']
                            ) ?>
                        </small>

                    <?php endif; ?>

                </span>

            </label>


            <?php if (
                !empty(
                    $method['services']
                )
            ): ?>

                <div
                    class="delivery-services"
                    data-method="<?= htmlspecialchars(
                        $method['slug']
                    ) ?>"
                    style="
                        display: none;

                        padding:
                            5px 15px
                            15px 49px;

                        background:
                            var(
                                --primary-light-color
                            );
                    "
                >

                    <div
    style="
        margin-bottom: 8px;
        font-size: 14px;
        font-weight: 600;
    "
>
    Выберите службу доставки

    <span
        style="
            color: var(--primary-color);
        "
    >*</span>
                    </div>


                    <?php foreach (
                        $method['services']
                        as $service
                    ): ?>

                        <label
                            style="
                                display: flex;
                                align-items: center;
                                gap: 10px;

                                padding: 8px 0;

                                cursor: pointer;
                            "
                        >

                            <input
                                type="radio"
                                name="delivery_service"
                                value="<?= htmlspecialchars(
                                    $service['slug']
                                ) ?>"
                                style="
                                    width: 18px;
                                    height: 18px;

                                    accent-color:
                                        var(
                                            --primary-color
                                        );
                                "
                            >


                            <span>
                                <?= htmlspecialchars(
                                    $service['name']
                                ) ?>
                            </span>

                        </label>
                        <?php if (
    !empty($service['options'])
): ?>

    <div
        class="delivery-service-options"
        data-service="<?= htmlspecialchars(
            $service['slug']
        ) ?>"
        style="
            display: none;

            margin-left: 28px;
            padding: 5px 0 8px 0;
        "
    >

        <?php foreach (
            $service['options']
            as $option
        ): ?>

            <label
                style="
                    display: flex;
                    align-items: center;
                    gap: 10px;

                    padding: 7px 0;

                    cursor: pointer;
                "
            >

                <input
                    type="radio"
                    name="delivery_service_option"
                    value="<?= htmlspecialchars(
                        $option['slug']
                    ) ?>"
                    style="
                        width: 17px;
                        height: 17px;

                        accent-color:
                            var(--primary-color);
                    "
                >

                <span>

                    <?= htmlspecialchars(
                        $option['name']
                    ) ?>

                    <?php if (
                        !empty(
                            $option['description']
                        )
                    ): ?>

                        <small
                            style="
                                display: block;
                                margin-top: 2px;
                                opacity: 0.7;
                            "
                        >
                            <?= htmlspecialchars(
                                $option['description']
                            ) ?>
                        </small>

                    <?php endif; ?>

                </span>

            </label>

        <?php endforeach; ?>

    </div>

<?php endif; ?>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>


            <?php if (
                $method
                !== end(
                    $deliveryMethods
                )
            ): ?>

                <div
                    style="
                        height: 1px;

                        background:
                            var(--border-color);

                        margin-left: 49px;
                    "
                ></div>

            <?php endif; ?>

        <?php endforeach; ?>

    </div>

                </div>


<div 
  id="delivery-country-group"
  style="margin-bottom: 15px;">

    <label>
        Страна
    </label>

    <input
        id="delivery-country"
        type="text"
        name="delivery_country"
        required
        style="
            width: 100%;
            box-sizing: border-box;
            padding: 12px;
            margin-top: 6px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
        "
    >

</div>


<div 
  id="delivery-city-group"
  style="margin-bottom: 15px;">

    <label>
        Город
    </label>

    <input
        id="delivery-city"
        type="text"
        name="delivery_city"
        required
        style="
            width: 100%;
            box-sizing: border-box;
            padding: 12px;
            margin-top: 6px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
        "
    >

</div>


<div 
  id="delivery-address-group"
  style="margin-bottom: 15px;">

    <label
    for="delivery-address"
    id="delivery-address-label"
>
    Адрес
    <span
        style="
            color: var(--primary-color);
            font-weight: bold;
        "
    >*</span>
    </label>

    <input
        id="delivery-address"
        type="text"
        name="delivery_address"
        placeholder="Введите адрес"
        required
        style="
            width: 100%;
            box-sizing: border-box;
            padding: 12px;
            margin-top: 6px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
        "
    >

</div>


<div 
  id="delivery-postcode-group"
  style="margin-bottom: 20px;">

    <label>
        Почтовый индекс
    </label>

    <input
        id="delivery-postcode"
        type="text"
        name="delivery_postcode"
        style="
            width: 100%;
            box-sizing: border-box;
            padding: 12px;
            margin-top: 6px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
        "
    >

</div>


                <div style="margin-bottom: 20px;">

                    <label>
                        Комментарий к заказу
                    </label>

                    <textarea
                        name="comment"
                        rows="4"
                        style="
                            width: 100%;
                            box-sizing: border-box;
                            padding: 12px;
                            margin-top: 6px;
                            border:
                                1px solid
                                var(--border-color);
                            border-radius: 10px;
                            resize: vertical;
                        "
                    ></textarea>

                </div>

                <p
    style="
        margin: 0 0 15px;
        font-size: 13px;
        opacity: 0.7;
    "
>
    <span
        style="
            color: var(--primary-color);
            font-weight: bold;
        "
    >*</span>
    — обязательные поля
                </p>


                <button
                    type="submit"
                    style="
                        width: 100%;
                        padding: 14px;
                        border: 0;
                        border-radius: 12px;
                        background:
                            var(--primary-color);
                        color: #fff;
                        font-size: 16px;
                        font-weight: bold;
                        cursor: pointer;
                    "
                >
                    Продолжить оформление
                </button>

            </form>

        </section>

    </main>
    <script>

    /*
     * ========================================
     * ЭЛЕМЕНТЫ ФОРМЫ
     * ========================================
     */

    const deliveryMethodRadios =
        document.querySelectorAll(
            'input[name="delivery_method"]'
        );

    const deliveryServiceGroups =
        document.querySelectorAll(
            '.delivery-services'
        );

    const deliveryOptions =
        document.querySelectorAll(
            '.delivery-option'
        );

    const deliveryServiceRadios =
        document.querySelectorAll(
            'input[name="delivery_service"]'
        );

    const deliveryServiceOptions =
        document.querySelectorAll(
            '.delivery-service-options'
        );


    /*
     * Поля адреса.
     */

    const countryInput =
        document.getElementById(
            'delivery-country'
        );

    const cityInput =
        document.getElementById(
            'delivery-city'
        );

    const addressInput =
        document.getElementById(
            'delivery-address'
        );

    const postcodeInput =
        document.getElementById(
            'delivery-postcode'
        );


    /*
     * Контейнеры полей адреса.
     */

    const countryGroup =
        document.getElementById(
            'delivery-country-group'
        );

    const cityGroup =
        document.getElementById(
            'delivery-city-group'
        );

    const addressGroup =
        document.getElementById(
            'delivery-address-group'
        );

    const postcodeGroup =
        document.getElementById(
            'delivery-postcode-group'
        );
    const addressLabel =
        document.getElementById(
            'delivery-address-label'
    );

    function updateAddressFieldByOption()
{
    let selectedOption =
        null;


    document
        .querySelectorAll(
            'input[name="delivery_service_option"]'
        )
        .forEach(
            (radio) => {

                if (radio.checked) {

                    selectedOption =
                        radio.value;

                }

            }
        );


    /*
     * Отделение
     */
    if (
        selectedOption
        === 'branch'
    ) {

        if (addressLabel) {

            addressLabel.innerHTML =
                'Отделение '
                + '<span style="'
                + 'color: var(--primary-color);'
                + 'font-weight: bold;'
                + '">*</span>';
        }


        if (addressInput) {

            addressInput.placeholder =
                'Номер или адрес отделения';
        }


        return;
    }


    /*
     * Почтомат
     */
    if (
        selectedOption
        === 'parcel_locker'
    ) {

        if (addressLabel) {

            addressLabel.innerHTML =
                'Почтомат '
                + '<span style="'
                + 'color: var(--primary-color);'
                + 'font-weight: bold;'
                + '">*</span>';
        }


        if (addressInput) {

            addressInput.placeholder =
                'Номер почтомата';
        }


        return;
    }


    /*
     * Адресная доставка
     */
    if (
        selectedOption
        === 'address'
    ) {

        if (addressLabel) {

            addressLabel.innerHTML =
                'Адрес доставки '
                + '<span style="'
                + 'color: var(--primary-color);'
                + 'font-weight: bold;'
                + '">*</span>';
        }


        if (addressInput) {

            addressInput.placeholder =
                'Улица, дом, квартира';
        }


        return;
    }


    /*
     * Обычное состояние.
     */
    if (addressLabel) {

        addressLabel.innerHTML =
            'Адрес '
            + '<span style="'
            + 'color: var(--primary-color);'
            + 'font-weight: bold;'
            + '">*</span>';
    }


    if (addressInput) {

        addressInput.placeholder =
            'Введите адрес';
    }
}
      
    /*
     * ========================================
     * ПОКАЗ / СКРЫТИЕ ПОЛЕЙ
     * ========================================
     */

    function setFieldState(
        input,
        group,
        visible,
        required
    ) {

        if (!input || !group) {
            return;
        }


        group.style.display =
            visible
                ? ''
                : 'none';


        input.required =
            required;


        if (!visible) {

            input.value =
                '';

        }
    }


    /*
     * ========================================
     * ПОДСВЕТКА СПОСОБА ДОСТАВКИ
     * ========================================
     */

    function updateDeliveryStyle()
    {

        deliveryOptions.forEach(
            (option) => {

                const radio =
                    option.querySelector(
                        'input[name="delivery_method"]'
                    );


                if (!radio) {
                    return;
                }


                if (radio.checked) {

                    option.style.background =
                        'var(--primary-light-color)';

                    option.style.color =
                        'var(--primary-color)';

                } else {

                    option.style.background =
                        '';

                    option.style.color =
                        '';

                }

            }
        );
    }


    /*
     * ========================================
     * ВТОРОЙ УРОВЕНЬ:
     * СЛУЖБЫ ДОСТАВКИ
     * ========================================
     */

    function updateDeliveryServices(
        selectedMethod
    ) {

        deliveryServiceGroups.forEach(
            (group) => {

                const method =
                    group.dataset.method;


                const serviceRadios =
                    group.querySelectorAll(
                        'input[name="delivery_service"]'
                    );


                if (
                    selectedMethod
                    === method
                ) {

                    group.style.display =
                        'block';


                    serviceRadios.forEach(
                        (radio) => {

                            radio.required =
                                true;

                        }
                    );

                } else {

                    group.style.display =
                        'none';


                    serviceRadios.forEach(
                        (radio) => {

                            radio.required =
                                false;

                            radio.checked =
                                false;

                        }
                    );
                }

            }
        );
    }


    /*
     * ========================================
     * ТРЕТИЙ УРОВЕНЬ:
     * ВАРИАНТ ПОЛУЧЕНИЯ
     * ========================================
     */

    function updateDeliveryServiceOptions()
    {

        let selectedService =
            null;


        deliveryServiceRadios.forEach(
            (radio) => {

                if (radio.checked) {

                    selectedService =
                        radio.value;

                }

            }
        );

      document
    .querySelectorAll(
        'input[name="delivery_service_option"]'
    )
    .forEach(
        (radio) => {

            radio.addEventListener(
                'change',
                updateAddressFieldByOption
            );

        }
    );


        deliveryServiceOptions.forEach(
            (group) => {

                const service =
                    group.dataset.service;


                const optionRadios =
                    group.querySelectorAll(
                        'input[name="delivery_service_option"]'
                    );


                if (
                    selectedService
                    === service
                ) {

                    group.style.display =
                        'block';


                    optionRadios.forEach(
                        (radio) => {

                            radio.required =
                                true;

                        }
                    );

                } else {

                    group.style.display =
                        'none';


                    optionRadios.forEach(
                        (radio) => {

                            radio.required =
                                false;

                            radio.checked =
                                false;

                        }
                    );
                }

            }
        );
    }


    /*
     * ========================================
     * ПОЛЯ АДРЕСА
     * ========================================
     */

    function updateDeliveryFields(
        selectedMethod
    ) {

        /*
         * Курьер.
         */

        if (
            selectedMethod
            === 'courier'
        ) {

            setFieldState(
                countryInput,
                countryGroup,
                true,
                true
            );

            setFieldState(
                cityInput,
                cityGroup,
                true,
                true
            );

            setFieldState(
                addressInput,
                addressGroup,
                true,
                true
            );

            setFieldState(
                postcodeInput,
                postcodeGroup,
                true,
                false
            );

            return;
        }


        /*
         * Самовывоз.
         */

        if (
            selectedMethod
            === 'pickup'
        ) {

            setFieldState(
                countryInput,
                countryGroup,
                false,
                false
            );

            setFieldState(
                cityInput,
                cityGroup,
                false,
                false
            );

            setFieldState(
                addressInput,
                addressGroup,
                false,
                false
            );

            setFieldState(
                postcodeInput,
                postcodeGroup,
                false,
                false
            );

            return;
        }


        /*
         * Почтовая доставка.
         */

        if (
            selectedMethod
            === 'post'
        ) {

            setFieldState(
                countryInput,
                countryGroup,
                true,
                true
            );

            setFieldState(
                cityInput,
                cityGroup,
                true,
                true
            );

            setFieldState(
                addressInput,
                addressGroup,
                true,
                true
            );

            setFieldState(
                postcodeInput,
                postcodeGroup,
                true,
                false
            );

            return;
        }


        /*
         * Способ ещё не выбран.
         */

        setFieldState(
            countryInput,
            countryGroup,
            true,
            false
        );

        setFieldState(
            cityInput,
            cityGroup,
            true,
            false
        );

        setFieldState(
            addressInput,
            addressGroup,
            true,
            false
        );

        setFieldState(
            postcodeInput,
            postcodeGroup,
            true,
            false
        );
    }


    /*
     * ========================================
     * ОБЩЕЕ ОБНОВЛЕНИЕ ФОРМЫ
     * ========================================
     */

    function updateDeliveryForm()
    {

        let selectedMethod =
            null;


        deliveryMethodRadios.forEach(
            (radio) => {

                if (radio.checked) {

                    selectedMethod =
                        radio.value;

                }

            }
        );


        updateDeliveryStyle();


        updateDeliveryServices(
            selectedMethod
        );


        updateDeliveryFields(
            selectedMethod
        );


        /*
         * Если основной способ
         * изменился, обновляем также
         * третий уровень.
         */

        updateDeliveryServiceOptions();
    }


    /*
     * ========================================
     * СОБЫТИЯ
     * ========================================
     */

    deliveryMethodRadios.forEach(
        (radio) => {

            radio.addEventListener(
                'change',
                updateDeliveryForm
            );

        }
    );


    deliveryServiceRadios.forEach(
        (radio) => {

            radio.addEventListener(
                'change',
                updateDeliveryServiceOptions
            );

        }
    );


    /*
     * ========================================
     * ПЕРВИЧНАЯ НАСТРОЙКА
     * ========================================
     */

    updateDeliveryForm();
    updateAddressFieldByOption();

    </script>

</body>

</html>