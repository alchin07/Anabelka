<?php

$deliveryEditLanguages = Language::active();

?>

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

            <h3 id="delivery-edit-title">
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


            <div
                style="
                    margin-bottom:16px;
                    padding:12px;
                    border:1px solid #eadcf7;
                    border-radius:12px;
                    background:#faf7ff;
                "
            >
                <strong>Українська · вихідна мова</strong>
            </div>


            <div class="delivery-form-group">

                <label for="edit-name">
                    Назва
                    <span class="required-mark">*</span>
                </label>

                <input
                    type="text"
                    name="name"
                    id="edit-name"
                    required
                >

            </div>


            <div class="delivery-form-group">

                <label for="edit-description">
                    Опис
                </label>

                <textarea
                    name="description"
                    id="edit-description"
                    rows="4"
                ></textarea>

            </div>


            <?php foreach ($deliveryEditLanguages as $language): ?>

                <?php
                $languageCode =
                    strtolower(
                        trim(
                            (string) ($language['code'] ?? '')
                        )
                    );

                if ($languageCode === Language::SOURCE_CODE) {
                    continue;
                }
                ?>

                <section
                    class="delivery-translation-section"
                    data-language-code="<?= htmlspecialchars($languageCode) ?>"
                    style="
                        margin-top:18px;
                        padding-top:16px;
                        border-top:1px solid #eadcf7;
                    "
                >
                    <div
                        style="
                            margin-bottom:12px;
                            font-weight:700;
                        "
                    >
                        <?= htmlspecialchars($language['name']) ?>
                        · <?= htmlspecialchars($language['short_name']) ?>
                    </div>

                    <div class="delivery-form-group">
                        <label>
                            Название / Name
                        </label>

                        <input
                            type="text"
                            class="delivery-translation-name"
                            autocomplete="off"
                        >
                    </div>

                    <div class="delivery-form-group">
                        <label>
                            Описание / Description
                        </label>

                        <textarea
                            class="delivery-translation-description"
                            rows="3"
                        ></textarea>
                    </div>
                </section>

            <?php endforeach; ?>


            <div
                id="edit-option-customer-input-section"
                hidden
            >

                <div class="delivery-form-group">

                    <label
                        style="display:flex;align-items:center;gap:8px;"
                    >
                        <input
                            type="checkbox"
                            id="edit-option-customer-input"
                            style="width:18px;height:18px;padding:0;margin:0;flex:0 0 auto;"
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

                    <div
                        style="
                            margin:14px 0 12px;
                            padding:10px 12px;
                            border:1px solid #eadcf7;
                            border-radius:10px;
                            background:#faf7ff;
                        "
                    >
                        <strong>Українська · поле для покупця</strong>
                    </div>

                    <div class="delivery-form-group">

                        <label for="edit-option-customer-input-label">
                            Підпис поля
                        </label>

                        <input
                            type="text"
                            id="edit-option-customer-input-label"
                            placeholder="Наприклад: Номер відділення"
                        >

                    </div>

                    <div class="delivery-form-group">

                        <label for="edit-option-customer-input-placeholder">
                            Підказка всередині поля
                        </label>

                        <input
                            type="text"
                            id="edit-option-customer-input-placeholder"
                            placeholder="Наприклад: 12"
                        >

                    </div>

                    <?php foreach ($deliveryEditLanguages as $language): ?>

                        <?php
                        $languageCode = strtolower(
                            trim((string) ($language['code'] ?? ''))
                        );

                        if ($languageCode === Language::SOURCE_CODE) {
                            continue;
                        }
                        ?>

                        <section
                            class="delivery-option-input-translation-section"
                            data-language-code="<?= htmlspecialchars($languageCode) ?>"
                            style="
                                margin-top:16px;
                                padding-top:14px;
                                border-top:1px solid #eadcf7;
                            "
                        >
                            <div style="margin-bottom:10px;font-weight:700;">
                                <?= htmlspecialchars($language['name']) ?>
                                · <?= htmlspecialchars($language['short_name']) ?>
                            </div>

                            <div class="delivery-form-group">
                                <label>Подпись поля / Label</label>
                                <input
                                    type="text"
                                    class="delivery-option-input-translation-label"
                                    autocomplete="off"
                                >
                            </div>

                            <div class="delivery-form-group">
                                <label>Подсказка / Placeholder</label>
                                <input
                                    type="text"
                                    class="delivery-option-input-translation-placeholder"
                                    autocomplete="off"
                                >
                            </div>
                        </section>

                    <?php endforeach; ?>

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
