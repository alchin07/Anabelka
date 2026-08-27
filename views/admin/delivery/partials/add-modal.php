<div
    id="delivery-add-modal"
    class="delivery-modal"
    hidden
>

    <div
        class="delivery-modal-backdrop"
        data-close-add-modal
    ></div>


    <div
        class="delivery-modal-window"
        role="dialog"
        aria-modal="true"
        aria-labelledby="delivery-add-title"
    >

        <div class="delivery-modal-head">

            <h3 id="delivery-add-title">
                Добавить способ доставки
            </h3>


            <button
                type="button"
                class="delivery-modal-close"
                data-close-add-modal
                title="Закрыть"
            >
                ×
            </button>

        </div>


        <form
            id="delivery-add-form"
            action="/Anabelka/admin/delivery/create-method"
            method="POST"
        >

            <div class="delivery-form-group">

                <label for="add-name">
                    Название
                    <span class="required-mark">
                        *
                    </span>
                </label>

                <input
                    type="text"
                    name="name"
                    id="add-name"
                    required
                >

            </div>


            <div class="delivery-form-group">
              

            </div>


            <div class="delivery-form-group">

                <label for="add-description">
                    Описание
                </label>

                <textarea
                    name="description"
                    id="add-description"
                    rows="4"
                ></textarea>

            </div>

            <div class="delivery-modal-actions">

                <button
                    type="button"
                    class="modal-button-secondary"
                    data-close-add-modal
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