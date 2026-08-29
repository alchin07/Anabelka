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


            <div class="delivery-form-group">

                <label>
                    <input
                        type="checkbox"
                        id="add-option-customer-input"
                    >
                    Запрашивать данные у покупателя
                </label>

                <small class="delivery-field-help">
                    Например: номер отделения или почтомата.
                </small>

            </div>


            <div
                id="add-option-customer-input-settings"
                hidden
            >

                <div class="delivery-form-group">

                    <label for="add-option-customer-input-label">
                        Подпись поля
                    </label>

                    <input
                        type="text"
                        id="add-option-customer-input-label"
                        placeholder="Например: Номер отделения"
                    >

                </div>


                <div class="delivery-form-group">

                    <label for="add-option-customer-input-placeholder">
                        Подсказка внутри поля
                    </label>

                    <input
                        type="text"
                        id="add-option-customer-input-placeholder"
                        placeholder="Например: 12"
                    >

                </div>

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