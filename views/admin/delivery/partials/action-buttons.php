<?php

/*
 * Налаштовуємо поля
 * залежно від рівня.
 */
switch ($actionType) {

    case 'method':

        $toggleUrl =
            '/Anabelka/admin/delivery/toggle-method';

        $idName =
            'method_id';

        break;


    case 'service':

        $toggleUrl =
            '/Anabelka/admin/delivery/toggle-service';

        $idName =
            'service_id';

        break;


    case 'option':

        $toggleUrl =
            '/Anabelka/admin/delivery/toggle-option';

        $idName =
            'option_id';

        break;


    default:

        return;
}


$isActive =
    !empty(
        $isActive
    );

?>


<form
    action="<?= htmlspecialchars(
        $toggleUrl
    ) ?>"
    method="POST"
    class="toggle-form"
    data-name="<?= htmlspecialchars(
        $actionType
    ) ?>"
    style="margin: 0;"
>

    <input
        type="hidden"
        name="<?= htmlspecialchars(
            $idName
        ) ?>"
        value="<?= (int) $itemId ?>"
    >

    <input
        type="hidden"
        name="is_active"
        value="<?= $isActive ? 0 : 1 ?>"
    >


    <button
        type="submit"
        class="icon-button"
        title="<?= $isActive
            ? 'Вимкнути'
            : 'Увімкнути' ?>"
    >

        <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
        >
            <path
                d="M12 2v10"
            ></path>

            <path
                d="
                    M6.4 5.6
                    a8 8 0 1 0
                    11.2 0
                "
            ></path>
        </svg>

    </button>

</form>


<button
    type="button"
    class="
        icon-button
        edit-button
    "

    data-type="<?= htmlspecialchars(
        $actionType
    ) ?>"

    data-id="<?= (int) $itemId ?>"

    data-name="<?= htmlspecialchars(
        $itemName ?? '',
        ENT_QUOTES,
        'UTF-8'
    ) ?>"

    data-description="<?= htmlspecialchars(
        $itemDescription ?? '',
        ENT_QUOTES,
        'UTF-8'
    ) ?>"

    title="Редагувати"
>

    <svg
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
    >
        <path
            d="
                M16.5 3.5
                a2.1 2.1 0 0 1
                3 3
                L8 18
                l-4 1
                1-4
                Z
            "
        ></path>
    </svg>

</button>


<button
    type="button"
    class="
        icon-button
        delete-button
    "
    data-type="<?= htmlspecialchars(
        $actionType
    ) ?>"
    data-id="<?= (int) $itemId ?>"
    data-name="<?= htmlspecialchars(
    $itemName ?? '',
    ENT_QUOTES,
    'UTF-8'
) ?>"
    title="Видалити"
>

    <svg
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
    >
        <path
            d="M3 6h18"
        ></path>

        <path
            d="M8 6V4h8v2"
        ></path>

        <path
            d="
                M19 6
                l-1 14
                H6
                L5 6
            "
        ></path>
    </svg>

</button>
