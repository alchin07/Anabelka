<div
    id="delivery-edit-modal"
    class="delivery-modal"
    hidden
>

    <div
        class="delivery-modal-backdrop"
        data-close-modal
    ></div>


    <div
        class="delivery-modal-window"
        role="dialog"
        aria-modal="true"
        aria-labelledby="delivery-edit-title"
    >

        <div class="delivery-modal-head">

            <h3
                id="delivery-edit-title"
            >
                Редактирование
            </h3>


            <button
                type="button"
                class="delivery-modal-close"
                data-close-modal
                title="Закрыть"
            >
                ×
            </button>

        </div>


        <form
            id="delivery-edit-form"
            action="/Anabelka/admin/delivery/update"
            method="POST"
        >

            <input
                type="hidden"
                name="type"
                id="edit-type"
            >

            <input
                type="hidden"
                name="id"
                id="edit-id"
            >


            <div class="delivery-form-group">

                <label
                    for="edit-name"
                >
                    Название
                    <span class="required-mark">
                        *
                    </span>
                </label>

                <input
                    type="text"
                    name="name"
                    id="edit-name"
                    required
                >

            </div>


            <div class="delivery-form-group">

                <label
                    for="edit-description"
                >
                    Описание
                </label>

                <textarea
                    name="description"
                    id="edit-description"
                    rows="4"
                ></textarea>

            </div>


            <div
                id="edit-option-customer-input-section"
                hidden
            >

                <div class="delivery-form-group">

                    <label>
                        <input
                            type="checkbox"
                            id="edit-option-customer-input"
                        >
                        Запрашивать данные у покупателя
                    </label>

                    <small class="delivery-field-help">
                        Например: номер отделения или почтомата.
                    </small>

                </div>


                <div
                    id="edit-option-customer-input-settings"
                    hidden
                >

                    <div class="delivery-form-group">

                        <label for="edit-option-customer-input-label">
                            Подпись поля
                        </label>

                        <input
                            type="text"
                            id="edit-option-customer-input-label"
                            placeholder="Например: Номер отделения"
                        >

                    </div>


                    <div class="delivery-form-group">

                        <label for="edit-option-customer-input-placeholder">
                            Подсказка внутри поля
                        </label>

                        <input
                            type="text"
                            id="edit-option-customer-input-placeholder"
                            placeholder="Например: 12"
                        >

                    </div>

                </div>

            </div>


            <div class="delivery-modal-actions">

                <button
                    type="button"
                    class="modal-button-secondary"
                    data-close-modal
                >
                    Отмена
                </button>


                <button
                    type="submit"
                    class="modal-button-primary"
                >
                    Сохранить
                </button>

            </div>

        </form>

    </div>

</div>