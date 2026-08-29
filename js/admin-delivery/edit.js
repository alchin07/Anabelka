/*
 * ========================================
 * РЕДАКТИРОВАНИЕ DELIVERY
 * ========================================
 */

(function () {
    'use strict';

    const editModal =
        document.getElementById(
            'delivery-edit-modal'
        );

    const editForm =
        document.getElementById(
            'delivery-edit-form'
        );

    const editType =
        document.getElementById(
            'edit-type'
        );

    const editId =
        document.getElementById(
            'edit-id'
        );

    const editName =
        document.getElementById(
            'edit-name'
        );

    const editDescription =
        document.getElementById(
            'edit-description'
        );

    const translationSections =
        Array.from(
            document.querySelectorAll(
                '.delivery-translation-section'
            )
        );

    const optionInputSection =
        document.getElementById(
            'edit-option-customer-input-section'
        );

    const optionInputToggle =
        document.getElementById(
            'edit-option-customer-input'
        );

    const optionInputSettings =
        document.getElementById(
            'edit-option-customer-input-settings'
        );

    const optionInputLabel =
        document.getElementById(
            'edit-option-customer-input-label'
        );

    const optionInputPlaceholder =
        document.getElementById(
            'edit-option-customer-input-placeholder'
        );


    function updateOptionInputVisibility()
    {
        if (!optionInputSettings) {
            return;
        }

        optionInputSettings.hidden =
            !(
                optionInputToggle
                && optionInputToggle.checked
            );
    }


    function clearTranslationFields()
    {
        translationSections.forEach(
            (section) => {
                const nameInput =
                    section.querySelector(
                        '.delivery-translation-name'
                    );

                const descriptionInput =
                    section.querySelector(
                        '.delivery-translation-description'
                    );

                if (nameInput) {
                    nameInput.value = '';
                }

                if (descriptionInput) {
                    descriptionInput.value = '';
                }
            }
        );
    }


    async function loadTranslations(type, id)
    {
        clearTranslationFields();

        if (!type || !id) {
            return;
        }

        const url =
            '/Anabelka/admin/delivery/translations'
            + '?type=' + encodeURIComponent(type)
            + '&id=' + encodeURIComponent(id);

        const response =
            await fetch(
                url,
                {
                    headers: {
                        'X-Requested-With':
                            'XMLHttpRequest'
                    }
                }
            );

        const text =
            await response.text();

        if (!response.ok) {
            throw new Error(
                text || 'Не удалось загрузить переводы.'
            );
        }

        let data = {};

        try {
            data = JSON.parse(text);
        } catch (error) {
            throw new Error(
                'Сервер вернул некорректные данные переводов.'
            );
        }

        const translations =
            data.translations || {};

        translationSections.forEach(
            (section) => {
                const code =
                    section.dataset.languageCode || '';

                const translation =
                    translations[code] || {};

                const nameInput =
                    section.querySelector(
                        '.delivery-translation-name'
                    );

                const descriptionInput =
                    section.querySelector(
                        '.delivery-translation-description'
                    );

                if (nameInput) {
                    nameInput.value =
                        translation.name || '';
                }

                if (descriptionInput) {
                    descriptionInput.value =
                        translation.description || '';
                }
            }
        );
    }


    function collectTranslations()
    {
        const translations = {};

        translationSections.forEach(
            (section) => {
                const code =
                    section.dataset.languageCode || '';

                if (!code) {
                    return;
                }

                const nameInput =
                    section.querySelector(
                        '.delivery-translation-name'
                    );

                const descriptionInput =
                    section.querySelector(
                        '.delivery-translation-description'
                    );

                translations[code] = {
                    name:
                        nameInput
                            ? nameInput.value.trim()
                            : '',
                    description:
                        descriptionInput
                            ? descriptionInput.value.trim()
                            : ''
                };
            }
        );

        return translations;
    }


    async function saveTranslations(type, id)
    {
        if (!type || !id) {
            return;
        }

        const formData =
            new FormData();

        formData.set('type', type);
        formData.set('id', id);
        formData.set(
            'translations',
            JSON.stringify(
                collectTranslations()
            )
        );

        const response =
            await fetch(
                '/Anabelka/admin/delivery/translations',
                {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With':
                            'XMLHttpRequest'
                    }
                }
            );

        const text =
            await response.text();

        if (!response.ok) {
            throw new Error(
                text || 'Не удалось сохранить переводы.'
            );
        }
    }


    if (optionInputToggle) {
        optionInputToggle.addEventListener(
            'change',
            updateOptionInputVisibility
        );
    }


    document
        .querySelectorAll('.edit-button')
        .forEach(
            (button) => {
                button.addEventListener(
                    'click',
                    async function ()
                    {
                        if (
                            !editModal
                            || !editType
                            || !editId
                            || !editName
                            || !editDescription
                        ) {
                            return;
                        }

                        editType.value =
                            this.dataset.type
                            || '';

                        editId.value =
                            this.dataset.id
                            || '';

                        editName.value =
                            this.dataset.name
                            || '';

                        editDescription.value =
                            this.dataset.description
                            || '';

                        clearTranslationFields();

                        const isOption =
                            editType.value === 'option';

                        if (optionInputSection) {
                            optionInputSection.hidden =
                                !isOption;
                        }

                        if (isOption) {
                            const row =
                                this.closest(
                                    '.delivery-row'
                                );

                            const config =
                                row
                                    ? row.querySelector(
                                        '.delivery-option-input-config'
                                    )
                                    : null;

                            if (optionInputToggle) {
                                optionInputToggle.checked =
                                    Boolean(
                                        config
                                        && config.dataset.enabled === '1'
                                    );
                            }

                            if (optionInputLabel) {
                                optionInputLabel.value =
                                    config
                                        ? config.dataset.label || ''
                                        : '';
                            }

                            if (optionInputPlaceholder) {
                                optionInputPlaceholder.value =
                                    config
                                        ? config.dataset.placeholder || ''
                                        : '';
                            }

                            updateOptionInputVisibility();
                        }

                        editModal.hidden = false;

                        try {
                            await loadTranslations(
                                editType.value,
                                editId.value
                            );
                        } catch (error) {
                            window.showMessage(
                                error instanceof Error
                                    ? error.message
                                    : 'Не удалось загрузить переводы.'
                            );
                        }

                        setTimeout(
                            () => {
                                editName.focus();
                                editName.select();
                            },
                            50
                        );
                    }
                );
            }
        );


    document
        .querySelectorAll(
            '[data-close-modal]'
        )
        .forEach(
            (button) => {
                button.addEventListener(
                    'click',
                    function ()
                    {
                        if (editModal) {
                            editModal.hidden = true;
                        }
                    }
                );
            }
        );


    if (
        !editForm
        || !editModal
        || !editType
        || !editId
        || !editName
        || !editDescription
    ) {
        return;
    }


    editForm.addEventListener(
        'submit',
        async function (event)
        {
            event.preventDefault();

            const formData =
                new FormData(editForm);

            try {
                const response =
                    await fetch(
                        editForm.action,
                        {
                            method: 'POST',
                            body: formData,
                            headers: {
                                'X-Requested-With':
                                    'XMLHttpRequest'
                            }
                        }
                    );

                const responseText =
                    await response.text();

                if (!response.ok) {
                    throw new Error(
                        responseText
                        || 'Не удалось сохранить изменения.'
                    );
                }

                await saveTranslations(
                    editType.value,
                    editId.value
                );

                const selector =
                    '.edit-button'
                    + '[data-type="'
                    + editType.value
                    + '"]'
                    + '[data-id="'
                    + editId.value
                    + '"]';

                const editButton =
                    document.querySelector(selector);

                const row =
                    editButton
                        ? editButton.closest(
                            '.delivery-row'
                        )
                        : null;

                if (
                    editType.value === 'option'
                    && typeof window.saveDeliveryOptionInput
                        === 'function'
                ) {
                    await window.saveDeliveryOptionInput(
                        editId.value,
                        Boolean(
                            optionInputToggle
                            && optionInputToggle.checked
                        ),
                        optionInputLabel
                            ? optionInputLabel.value
                            : '',
                        optionInputPlaceholder
                            ? optionInputPlaceholder.value
                            : ''
                    );

                    const config =
                        row
                            ? row.querySelector(
                                '.delivery-option-input-config'
                            )
                            : null;

                    if (config) {
                        config.dataset.enabled =
                            optionInputToggle
                            && optionInputToggle.checked
                                ? '1'
                                : '0';

                        config.dataset.label =
                            optionInputLabel
                                ? optionInputLabel.value
                                : '';

                        config.dataset.placeholder =
                            optionInputPlaceholder
                                ? optionInputPlaceholder.value
                                : '';
                    }
                }

                if (editButton) {
                    if (row) {
                        const nameElement =
                            row.querySelector(
                                '.delivery-name'
                            );

                        if (nameElement) {
                            nameElement.textContent =
                                editName.value;
                        }

                        const textContainer =
                            row.querySelector(
                                '.admin-tree-text'
                            );

                        let descriptionElement =
                            row.querySelector(
                                '.delivery-description'
                            );

                        const description =
                            editDescription.value.trim();

                        if (description !== '') {
                            if (
                                !descriptionElement
                                && textContainer
                            ) {
                                descriptionElement =
                                    document.createElement(
                                        'span'
                                    );

                                descriptionElement.className =
                                    'delivery-description';

                                textContainer.appendChild(
                                    descriptionElement
                                );
                            }

                            if (descriptionElement) {
                                descriptionElement.textContent =
                                    editDescription.value;
                            }

                        } else if (descriptionElement) {
                            descriptionElement.remove();
                        }

                        const deleteButton =
                            row.querySelector(
                                '.delete-button'
                            );

                        if (deleteButton) {
                            deleteButton.dataset.name =
                                editName.value;
                        }
                    }

                    editButton.dataset.name =
                        editName.value;

                    editButton.dataset.description =
                        editDescription.value;
                }

                editModal.hidden = true;

                window.showMessage(
                    'Изменения и переводы сохранены'
                );

            } catch (error) {
                window.showMessage(
                    error instanceof Error
                        ? error.message
                        : 'Не удалось сохранить изменения.'
                );
            }
        }
    );
})();
