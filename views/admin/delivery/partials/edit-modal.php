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