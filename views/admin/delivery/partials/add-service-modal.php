<div
    id="delivery-add-service-modal"
    class="delivery-modal"
    hidden
>

    <div
        class="delivery-modal-backdrop"
        data-close-add-service-modal
    ></div>


    <div
        class="delivery-modal-window"
        role="dialog"
        aria-modal="true"
        aria-labelledby="delivery-add-service-title"
    >

        <div class="delivery-modal-head">

            <h3 id="delivery-add-service-title">
                Добавить службу доставки
            </h3>


            <button
                type="button"
                class="delivery-modal-close"
                data-close-add-service-modal
                title="Закрыть"
            >
                ×
            </button>

        </div>


        <form
            id="delivery-add-service-form"
            action="/Anabelka/admin/delivery/create-service"
            method="POST"
        >

            <input
                type="hidden"
                name="delivery_method_id"
                id="add-service-method-id"
            >


            <div class="delivery-form-group">

                <label for="add-service-name">
                    Название
                    <span class="required-mark">
                        *
                    </span>
                </label>

                <input
                    type="text"
                    name="name"
                    id="add-service-name"
                    required
                >

            </div>


            


            <div class="delivery-form-group">

                <label for="add-service-description">
                    Описание
                </label>

                <textarea
                    name="description"
                    id="add-service-description"
                    rows="4"
                ></textarea>

            </div>


            <div class="delivery-modal-actions">

                <button
                    type="button"
                    class="modal-button-secondary"
                    data-close-add-service-modal
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