<div
    id="delivery-add-option-modal"
    class="delivery-modal"
    hidden
>

    <div
        class="delivery-modal-backdrop"
        data-close-add-option-modal
    ></div>


    <div
        class="delivery-modal-window"
        role="dialog"
        aria-modal="true"
        aria-labelledby="delivery-add-option-title"
    >

        <div class="delivery-modal-head">

            <h3 id="delivery-add-option-title">
                Добавить опцию доставки
            </h3>


            <button
                type="button"
                class="delivery-modal-close"
                data-close-add-option-modal
                title="Закрыть"
            >
                ×
            </button>

        </div>


        <form
            id="delivery-add-option-form"
            action="/Anabelka/admin/delivery/create-option"
            method="POST"
        >

            <input
                type="hidden"
                name="delivery_service_id"
                id="add-option-service-id"
            >


            <div class="delivery-form-group">

                <label for="add-option-name">
                    Название
                    <span class="required-mark">
                        *
                    </span>
                </label>

                <input
                    type="text"
                    name="name"
                    id="add-option-name"
                    required
                >

            </div>


            <div class="delivery-form-group">

                <label for="add-option-description">
                    Описание
                </label>

                <textarea
                    name="description"
                    id="add-option-description"
                    rows="4"
                ></textarea>

            </div>

            <div class="delivery-modal-actions">

                <button
                    type="button"
                    class="modal-button-secondary"
                    data-close-add-option-modal
                >
                    Отмена
                </button>


                <button
                    type="submit"
                    class="modal-button-primary"
                >
                    Добавить
                </button>

            </div>

        </form>

    </div>

</div>