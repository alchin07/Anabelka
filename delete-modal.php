<div
    id="delivery-delete-modal"
    class="delivery-modal"
    hidden
>

    <div
        class="delivery-modal-backdrop"
        data-close-delete-modal
    ></div>


    <div
        class="delivery-modal-window"
        role="dialog"
        aria-modal="true"
        aria-labelledby="delivery-delete-title"
    >

        <div class="delivery-modal-head">

            <h3
                id="delivery-delete-title"
            >
                Удаление
            </h3>


            <button
                type="button"
                class="delivery-modal-close"
                data-close-delete-modal
                title="Закрыть"
            >
                ×
            </button>

        </div>


        <p
            id="delivery-delete-text"
            style="
                margin: 0 0 12px;
                line-height: 1.5;
            "
        >
            Удалить этот элемент?
        </p>


        <p
            id="delivery-delete-warning"
            style="
                display: none;

                margin: 0 0 18px;
                padding: 10px 12px;

                border-radius: 10px;

                background:
                    var(--primary-light-color);

                color:
                    var(--text-color);

                font-size: 13px;
                line-height: 1.45;
            "
        >
            Вместе с ним будут удалены
            вложенные элементы.
        </p>


        <form
            id="delivery-delete-form"
            action="/Anabelka/admin/delivery/delete"
            method="POST"
        >

            <input
                type="hidden"
                name="type"
                id="delete-type"
            >

            <input
                type="hidden"
                name="id"
                id="delete-id"
            >


            <div class="delivery-modal-actions">

                <button
                    type="button"
                    class="modal-button-secondary"
                    data-close-delete-modal
                >
                    Отмена
                </button>


                <button
                    type="submit"
                    class="modal-button-primary"
                >
                    Удалить
                </button>

            </div>

        </form>

    </div>

</div>