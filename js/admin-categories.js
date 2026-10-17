(function () {
    'use strict';

    const dataElement = document.getElementById('category-manager-data');
    let payload = {categories: [], departments: []};

    try {
        payload = JSON.parse(dataElement ? dataElement.textContent : '{}');
    } catch (error) {
        payload = {categories: [], departments: []};
    }

    const categories = Array.isArray(payload.categories)
        ? payload.categories
        : [];
    const departments = Array.isArray(payload.departments)
        ? payload.departments
        : [];
    const categoryById = new Map();
    const departmentById = new Map();
    let lastTrigger = null;
    const formErrors = new WeakMap();
    let errorSequence = 0;

    categories.forEach(function (category) {
        categoryById.set(Number(category.id || 0), category);
    });
    departments.forEach(function (department) {
        departmentById.set(Number(department.id || 0), department);
    });

    function showMessage(message, isError)
    {
        if (isError && window.AnabelkaNotify && typeof window.AnabelkaNotify.error === 'function') {
            return window.AnabelkaNotify.error(message);
        }

        if (window.AnabelkaNotify && typeof window.AnabelkaNotify.show === 'function') {
            return window.AnabelkaNotify.show(isError ? 'error' : 'success', message);
        }

        if (window.AdminFlashMessage && typeof window.AdminFlashMessage.show === 'function') {
            return window.AdminFlashMessage.show(message, isError);
        }

        return false;
    }


    function storeSuccessMessage(message)
    {
        if (window.AnabelkaNotify && typeof window.AnabelkaNotify.store === 'function') {
            return window.AnabelkaNotify.store('success', message || 'Збережено.');
        }

        if (window.AdminFlashMessage && typeof window.AdminFlashMessage.storeSuccess === 'function') {
            return window.AdminFlashMessage.storeSuccess(message);
        }

        return false;
    }


    function openModal(modal, trigger, focusField)
    {
        closeModals(false);
        modal.querySelectorAll('form').forEach(clearFormErrors);
        lastTrigger = trigger || null;
        modal.hidden = false;
        document.body.style.overflow = 'hidden';

        if (
            window.AnabelkaAdminBack
            && typeof window.AnabelkaAdminBack.syncNow === 'function'
        ) {
            window.AnabelkaAdminBack.syncNow();
        }

        if (modal.id === 'category-edit-modal') {
            window.AnabelkaAIEditingContext = 'category-edit';
        }

        document.dispatchEvent(new CustomEvent(
            'anabelka:ai-context-change'
        ));

        if (
            window.AnabelkaAITranslation
            && typeof window.AnabelkaAITranslation.refreshVisibility
                === 'function'
        ) {
            window.AnabelkaAITranslation.refreshVisibility();
        }

        window.setTimeout(function () {
            if (focusField && typeof focusField.focus === 'function') {
                focusField.focus();
            }
        }, 30);
    }


    function closeModals(restoreFocus)
    {
        document.querySelectorAll('.category-modal').forEach(function (modal) {
            modal.hidden = true;
        });
        document.body.style.overflow = '';

        if (
            window.AnabelkaAdminBack
            && typeof window.AnabelkaAdminBack.syncNow === 'function'
        ) {
            window.AnabelkaAdminBack.syncNow();
        }

        if (window.AnabelkaAIEditingContext === 'category-edit') {
            window.AnabelkaAIEditingContext = '';
        }

        document.dispatchEvent(new CustomEvent(
            'anabelka:ai-context-change'
        ));

        if (
            window.AnabelkaAITranslation
            && typeof window.AnabelkaAITranslation.refreshVisibility
                === 'function'
        ) {
            window.AnabelkaAITranslation.refreshVisibility();
        }

        if (restoreFocus !== false && lastTrigger) {
            lastTrigger.focus();
        }
    }


    document.querySelectorAll('[data-category-close]').forEach(function (item) {
        item.addEventListener('click', function () {
            closeModals(true);
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeModals(true);
        }
    });

    if (
        window.AnabelkaAdminBack
        && typeof window.AnabelkaAdminBack.register === 'function'
    ) {
        window.AnabelkaAdminBack.register({
            key: 'category-modal',
            priority: 50,
            isActive: function () {
                return Array.from(
                    document.querySelectorAll('.category-modal')
                ).some(function (modal) {
                    return !modal.hidden;
                });
            },
            close: function () {
                closeModals(true);
            }
        });
    }


    function csrfToken()
    {
        const field = document.querySelector(
            '.category-modal input[name="_csrf"]'
        );

        return field ? field.value : '';
    }


    function responseFailureMessage(response)
    {
        if (response.status === 401) {
            return 'Сеанс завершено. Увійдіть знову та повторіть дію.';
        }
        if (response.status === 403) {
            return 'Доступ заборонено. Оновіть сторінку та перевірте права доступу.';
        }
        if (response.status === 429) {
            return 'Забагато запитів. Спробуйте трохи пізніше.';
        }
        if (response.status >= 500) {
            return 'Помилка сервера. Перевірте журнал KSWEB.';
        }
        return 'Операцію не виконано. Оновіть сторінку та спробуйте ще раз.';
    }


    async function request(url, body)
    {
        let response;
        let text;

        try {
            response = await fetch(url, {
                method: 'POST',
                body: body,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            text = await response.text();
        } catch (error) {
            // Browser-specific network failures must not appear as raw
            // "Failed to fetch" messages in administrator notifications.
            throw new Error(
                'Немає зв’язку із сервером. Перевірте з’єднання та спробуйте ще раз.'
            );
        }

        let result;

        try {
            result = JSON.parse(text);
        } catch (error) {
            throw new Error(
                response.ok
                    ? 'Сервер повернув некоректну відповідь.'
                    : responseFailureMessage(response)
            );
        }

        if (!result || typeof result !== 'object' || Array.isArray(result)) {
            throw new Error(
                response.ok
                    ? 'Сервер повернув некоректну відповідь.'
                    : responseFailureMessage(response)
            );
        }

        // HTTP status wins, even if a proxy sends an inconsistent JSON body.
        if (!response.ok) {
            const serverMessage = result.success === false
                && typeof result.message === 'string'
                ? result.message.trim() : '';
            throw new Error(serverMessage || responseFailureMessage(response));
        }

        if (result.success === false) {
            throw new Error(
                typeof result.message === 'string' && result.message.trim()
                    ? result.message.trim()
                    : 'Операцію не виконано.'
            );
        }

        if (result.success !== true) {
            throw new Error('Сервер повернув некоректну відповідь.');
        }

        // Never render a malformed message as "[object Object]".
        result.message = typeof result.message === 'string'
            ? result.message.trim() : '';

        return result;
    }


    function validateThumbnailFile(file)
    {
        if (!(file instanceof File)) {
            return 'Фото не вибрано.';
        }

        if (file.size > 8388608) {
            return 'Фото має бути не більше 8 МБ.';
        }

        if (!/^image\/(jpeg|png|webp)$/i.test(file.type || '')) {
            return 'Підтримуються JPG, PNG та WebP.';
        }

        return '';
    }


    async function uploadCategoryThumbnail(categoryId, file)
    {
        const data = new FormData();

        data.append('_csrf', csrfToken());
        data.append('category_id', String(categoryId));
        data.append('image', '');
        data.append('thumbnail_file', file, file.name);

        const result = await request(
            '/Anabelka/admin/categories/thumbnail',
            data
        );

        return result.thumbnail || {};
    }


    function fieldErrorMessage(field)
    {
        // willValidate also excludes hidden/read-only fields and disabled fieldsets.
        if (!field.willValidate || !field.validity) {
            return '';
        }

        const validity = field.validity;
        const value = typeof field.value === 'string' ? field.value : '';
        if (validity.valueMissing || (field.required && field.type === 'text' && !value.trim())) {
            if (field.name === 'name') {
                return 'Будь ласка, заповніть назву категорії';
            }
            return field.tagName === 'SELECT'
                ? 'Будь ласка, оберіть підрозділ.'
                : 'Будь ласка, заповніть це поле.';
        }

        // Browsers can leave tooLong false for values assigned by JS (including AI).
        // Keep the existing HTML maxlength semantics without changing FormData.
        if (validity.tooLong || (field.maxLength >= 0 && value.length > field.maxLength)) {
            return 'Введіть не більше ' + field.maxLength + ' символів.';
        }
        if (validity.tooShort) {
            return 'Введіть щонайменше ' + field.minLength + ' символів.';
        }
        if (validity.customError) {
            return field.validationMessage;
        }
        return validity.valid ? '' : 'Перевірте значення цього поля.';
    }


    function fieldControl(field)
    {
        const wrapper = field.closest('.anabelka-select');
        return wrapper ? wrapper.querySelector('.anabelka-select-trigger') || field : field;
    }


    function clearFieldError(form, field)
    {
        const errors = formErrors.get(form);
        const entry = errors && errors.get(field);
        if (!entry) {
            return;
        }
        entry.controls.forEach(function (control) {
            const describedBy = (control.node.getAttribute('aria-describedby') || '')
                .split(/\s+/).filter(function (id) { return id && id !== entry.message.id; });
            if (describedBy.length) {
                control.node.setAttribute('aria-describedby', describedBy.join(' '));
            } else {
                control.node.removeAttribute('aria-describedby');
            }
            if (control.invalid === null) {
                control.node.removeAttribute('aria-invalid');
            } else {
                control.node.setAttribute('aria-invalid', control.invalid);
            }
        });
        field.classList.remove('is-invalid');
        entry.message.remove();
        errors.delete(field);
    }


    function clearFormErrors(form)
    {
        const errors = formErrors.get(form);
        if (errors) {
            Array.from(errors.keys()).forEach(function (field) { clearFieldError(form, field); });
        }
    }


    function showFieldError(form, field, message)
    {
        const errors = formErrors.get(form);
        let entry = errors.get(field);
        if (!entry) {
            const error = document.createElement('p');
            do {
                errorSequence += 1;
                error.id = form.id + '-error-' + errorSequence;
            } while (document.getElementById(error.id));
            error.className = 'category-field-error anabelka-notify-inline is-validation';
            const control = fieldControl(field);
            const controls = (control === field ? [field] : [field, control]).map(function (node) {
                return {node: node, invalid: node.getAttribute('aria-invalid')};
            });
            entry = {message: error, controls: controls};
            errors.set(field, entry);
            // Described text is announced with focus; no second live-region announcement.
            error.textContent = message;
            // Form fields have feedback immediately below their own control.
            // A custom-invalid submit button must not become a flex item
            // beside the form actions; its feedback follows the action row.
            const anchor = field.closest('.category-modal-actions')
                || field.closest('.anabelka-select') || field;
            anchor.insertAdjacentElement('afterend', error);
            field.classList.add('is-invalid');
            controls.forEach(function (item) {
                const ids = (item.node.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
                ids.push(error.id);
                item.node.setAttribute('aria-describedby', ids.join(' '));
                item.node.setAttribute('aria-invalid', 'true');
            });
        } else if (entry.message.textContent !== message) {
            // An unchanged error stays untouched on repeated submits (including Enter).
            entry.message.textContent = message;
        }
    }


    function validateForm(form)
    {
        let firstInvalid = null;
        Array.from(form.elements).forEach(function (field) {
            const message = fieldErrorMessage(field);
            if (message) {
                showFieldError(form, field, message);
                firstInvalid = firstInvalid || field;
            } else {
                clearFieldError(form, field);
            }
        });
        if (firstInvalid) {
            const control = fieldControl(firstInvalid);
            if (control === firstInvalid && window.AdminUiFocusPolicy
                && typeof window.AdminUiFocusPolicy.focusRequestedField === 'function') {
                window.AdminUiFocusPolicy.focusRequestedField(control);
            } else {
                control.focus({preventScroll: true});
            }
            const entry = formErrors.get(form).get(firstInvalid);
            const group = firstInvalid.closest('.category-form-group') || entry.message;
            group.scrollIntoView({block: 'nearest', inline: 'nearest', behavior: 'instant'});
        }
        return !firstInvalid;
    }


    function refreshFieldError(form, field)
    {
        const errors = formErrors.get(form);
        if (!errors || !errors.has(field)) {
            return;
        }
        const message = fieldErrorMessage(field);
        if (message) {
            showFieldError(form, field, message);
        } else {
            clearFieldError(form, field);
        }
    }


    function bindCategoryForm(form, submitButton, returnUrl)
    {
        formErrors.set(form, new Map());
        form.addEventListener('submit', submitAndReload(form, submitButton, returnUrl));
        form.addEventListener('reset', function () { clearFormErrors(form); });
        function refreshError(event) {
            refreshFieldError(form, event.target);
        }
        form.addEventListener('input', refreshError);
        form.addEventListener('change', refreshError);
        // Only opt out after all handlers are installed; raw HTML retains native validation.
        form.noValidate = true;
    }


    function submitAndReload(form, submitButton, returnUrl)
    {
        let pending = false;
        return async function (event) {
            event.preventDefault();
            if (pending || !validateForm(form)) {
                return;
            }
            pending = true;

            if (submitButton) {
                submitButton.disabled = true;
            }

            try {
                const result = await request(form.action, new FormData(form));
                storeSuccessMessage(result.message || 'Збережено.');
                closeModals(false);

                if (returnUrl) {
                    window.location.replace(returnUrl);
                } else {
                    window.location.reload();
                }
            } catch (error) {
                pending = false;
                showMessage(error.message || 'Операцію не виконано.', true);

                if (submitButton) {
                    submitButton.disabled = false;
                }
            }
        };
    }


    function setCategoryTranslationWorkflow(section, source, status)
    {
        if (!section) {
            return;
        }

        const sourceField = section.querySelector(
            '.category-translation-source'
        );
        const sourceLabel = section.querySelector(
            '.category-translation-origin'
        );
        const statusField = section.querySelector(
            '.category-translation-status select'
        );
        const normalizedSource = source === 'ai' ? 'ai' : 'manual';
        const validStatuses = ['draft', 'review', 'approved', 'outdated'];
        const normalizedStatus = validStatuses.includes(status)
            ? status
            : 'approved';

        if (sourceField) {
            sourceField.value = normalizedSource;
        }
        if (sourceLabel) {
            sourceLabel.textContent = normalizedSource === 'ai'
                ? 'Створено ШІ'
                : 'Ручний переклад';
        }
        if (statusField) {
            statusField.value = normalizedStatus;

            if (
                window.AnabelkaSelect
                && typeof window.AnabelkaSelect.sync === 'function'
            ) {
                window.AnabelkaSelect.sync(statusField);
            }
        }
        section.dataset.translationStatus = normalizedStatus;
        // AI writes values directly, then calls this existing workflow hook.
        // Refresh guidance without an input event that would mark AI text manual.
        section.querySelectorAll(
            '.category-translation-name, .category-translation-description'
        ).forEach(function (field) { refreshFieldError(field.form, field); });
    }

    window.setCategoryTranslationWorkflow = setCategoryTranslationWorkflow;


    const editModal = document.getElementById('category-edit-modal');
    const editForm = document.getElementById('category-edit-form');
    const editId = document.getElementById('category-edit-id');
    const editName = document.getElementById('category-edit-name');
    const editDescription = document.getElementById(
        'category-edit-description'
    );
    const editActive = document.getElementById('category-edit-active');
    const editAdult = document.getElementById('category-edit-adult');
    const editImage = document.getElementById('category-edit-image');
    const thumbnailOptions = document.querySelector(
        '[data-category-thumbnail-options]'
    );
    const thumbnailPreview = document.querySelector(
        '[data-category-thumbnail-preview]'
    );
    const thumbnailPreviewImage = document.querySelector(
        '[data-category-thumbnail-preview-image]'
    );
    const thumbnailEmpty = document.querySelector(
        '[data-category-thumbnail-empty]'
    );
    const thumbnailMode = document.querySelector(
        '[data-category-thumbnail-mode]'
    );
    const thumbnailAuto = document.querySelector(
        '[data-category-thumbnail-auto]'
    );
    const thumbnailUpload = document.getElementById(
        'category-edit-thumbnail-upload'
    );
    let thumbnailFallback = '';

    function setThumbnailPreview(path, mode)
    {
        path = String(path || '').trim();

        if (thumbnailPreviewImage) {
            if (path !== '') {
                thumbnailPreviewImage.src = path;
                thumbnailPreviewImage.hidden = false;
            } else {
                thumbnailPreviewImage.removeAttribute('src');
                thumbnailPreviewImage.hidden = true;
            }
        }

        if (thumbnailEmpty) {
            thumbnailEmpty.hidden = path !== '';
        }

        if (thumbnailPreview) {
            thumbnailPreview.classList.toggle('is-empty', path === '');
        }

        if (thumbnailMode) {
            thumbnailMode.textContent = mode || (
                path !== '' ? 'Мініатюра обрана' : 'Фото немає'
            );
        }
    }

    function markThumbnailSelection(path)
    {
        if (!thumbnailOptions) {
            return;
        }

        thumbnailOptions.querySelectorAll(
            '[data-category-thumbnail-path]'
        ).forEach(function (button) {
            button.classList.toggle(
                'is-selected',
                String(button.dataset.categoryThumbnailPath || '') === path
            );
        });
    }

    function chooseThumbnail(path)
    {
        path = String(path || '').trim();

        if (editImage) {
            editImage.value = path;
        }

        markThumbnailSelection(path);
        setThumbnailPreview(
            path || thumbnailFallback,
            path !== ''
                ? 'Обрана вручну'
                : (thumbnailFallback !== ''
                    ? 'Автоматично з товару'
                    : 'Фото немає')
        );
    }

    function appendThumbnailChoice(path, labelText, titleText)
    {
        path = String(path || '').trim();

        if (!thumbnailOptions || path === '') {
            return;
        }

        const button = document.createElement('button');
        const image = document.createElement('img');
        const label = document.createElement('span');

        button.type = 'button';
        button.className = 'category-thumbnail-choice';
        button.dataset.categoryThumbnailPath = path;
        button.title = String(titleText || labelText || 'Фото');

        image.src = path;
        image.alt = '';
        image.loading = 'lazy';
        label.textContent = String(labelText || 'Фото');

        button.appendChild(image);
        button.appendChild(label);
        button.addEventListener('click', function () {
            chooseThumbnail(path);
        });
        thumbnailOptions.appendChild(button);
    }


    function renderThumbnailEditor(category)
    {
        thumbnailFallback = String(
            category.thumbnail_image || ''
        ).trim();
        const current = String(category.image || '').trim();
        const candidates = Array.isArray(category.thumbnail_candidates)
            ? category.thumbnail_candidates
            : [];
        const candidatePaths = new Set();

        if (thumbnailOptions) {
            thumbnailOptions.replaceChildren();

            candidates.forEach(function (candidate) {
                const path = String(candidate.path || '').trim();

                if (path === '' || candidatePaths.has(path)) {
                    return;
                }

                candidatePaths.add(path);
                appendThumbnailChoice(
                    path,
                    String(candidate.product_name || 'Товар'),
                    String(candidate.product_name || 'Фото товару')
                );
            });

            if (current !== '' && !candidatePaths.has(current)) {
                appendThumbnailChoice(
                    current,
                    'Завантажене фото',
                    'Поточна завантажена мініатюра'
                );
            }

            if (thumbnailOptions.children.length === 0) {
                const empty = document.createElement('span');

                empty.className = 'category-thumbnail-options-empty';
                empty.textContent =
                    'У товарів цієї категорії поки немає фотографій.';
                thumbnailOptions.appendChild(empty);
            }
        }

        chooseThumbnail(current);
    }

    if (thumbnailAuto) {
        thumbnailAuto.addEventListener('click', function () {
            chooseThumbnail('');
        });
    }

    if (thumbnailUpload) {
        thumbnailUpload.addEventListener('change', async function () {
            const file = thumbnailUpload.files
                ? thumbnailUpload.files[0]
                : null;

            if (!file) {
                return;
            }

            const categoryId = Number(editId ? editId.value : 0);

            if (categoryId <= 0) {
                showMessage(
                    'Завантаження мініатюри зараз недоступне.',
                    true
                );
                thumbnailUpload.value = '';
                return;
            }

            const validationError = validateThumbnailFile(file);

            if (validationError !== '') {
                showMessage(validationError, true);
                thumbnailUpload.value = '';
                return;
            }

            thumbnailUpload.disabled = true;

            try {
                const stored = await uploadCategoryThumbnail(
                    categoryId,
                    file
                );
                const category = categoryById.get(categoryId);

                if (category) {
                    category.image = String(stored.image || '');
                    category.thumbnail_image = String(
                        stored.thumbnail_image || ''
                    );
                    renderThumbnailEditor(category);
                }

                showMessage(
                    'Фото оброблено до 320×320 і встановлено як мініатюру.',
                    false
                );
            } catch (error) {
                showMessage(
                    error.message || 'Не вдалося завантажити мініатюру.',
                    true
                );
            } finally {
                thumbnailUpload.disabled = false;
                thumbnailUpload.value = '';
            }
        });
    }

    if (thumbnailPreviewImage) {
        thumbnailPreviewImage.addEventListener('error', function () {
            thumbnailPreviewImage.hidden = true;

            if (thumbnailEmpty) {
                thumbnailEmpty.hidden = false;
            }
            if (thumbnailPreview) {
                thumbnailPreview.classList.add('is-empty');
            }
        });
    }

    function translationFocusField(categoryId)
    {
        const params = new URLSearchParams(window.location.search);
        const requestedId = Number(params.get('highlight') || 0);
        const languageCode = String(
            params.get('focus_language') || ''
        ).trim().toLowerCase();

        if (requestedId !== categoryId || !languageCode) {
            return editName;
        }

        const section = document.querySelector(
            '[data-category-language="' + languageCode + '"]'
        );

        if (!section) {
            return editName;
        }

        section.classList.add('is-translation-focus');
        return section.querySelector('.category-translation-name')
            || section.querySelector('.category-translation-description')
            || editName;
    }

    document.querySelectorAll('[data-category-edit]').forEach(function (button) {
        button.addEventListener('click', function () {
            const categoryId = Number(button.dataset.categoryEdit || 0);
            const category = categoryById.get(categoryId);

            if (!category) {
                showMessage('Категорію не знайдено у списку.', true);
                return;
            }

            editId.value = String(categoryId);
            editName.value = String(category.name || '');
            editDescription.value = String(category.description || '');
            editActive.checked = Number(category.is_active || 0) === 1;
            editAdult.checked = Number(category.is_adult || 0) === 1;
            renderThumbnailEditor(category);
            const translations = category.translations || {};

            document.querySelectorAll('[data-category-language]').forEach(
                function (section) {
                    section.classList.remove('is-translation-focus');
                    const code = String(
                        section.dataset.categoryLanguage || ''
                    );
                    const translation = translations[code] || {};
                    const name = section.querySelector(
                        '.category-translation-name'
                    );
                    const description = section.querySelector(
                        '.category-translation-description'
                    );

                    if (name) {
                        name.value = String(translation.name || '');
                    }
                    if (description) {
                        description.value = String(
                            translation.description || ''
                        );
                    }

                    setCategoryTranslationWorkflow(
                        section,
                        translation.source || 'manual',
                        translation.status || (
                            translation.name || translation.description
                                ? 'approved'
                                : 'draft'
                        )
                    );
                }
            );

            openModal(
                editModal,
                button,
                translationFocusField(categoryId)
            );
        });
    });

    document.querySelectorAll(
        '.category-translation-name, .category-translation-description'
    ).forEach(function (field) {
        field.addEventListener('input', function () {
            const section = field.closest('[data-category-language]');
            const status = section
                ? section.querySelector('.category-translation-status select')
                : null;
            const name = section
                ? section.querySelector('.category-translation-name')
                : null;
            const description = section
                ? section.querySelector('.category-translation-description')
                : null;
            const hasContent = Boolean(
                (name && name.value.trim())
                || (description && description.value.trim())
            );

            setCategoryTranslationWorkflow(
                section,
                'manual',
                status && status.value === 'draft' && hasContent
                    ? 'approved'
                    : (status ? status.value : 'approved')
            );
        });
    });

    const editParams = new URLSearchParams(window.location.search);
    const editReturnUrl = /^\d+$/.test(editParams.get('highlight') || '')
        && String(editParams.get('focus_language') || '').trim() !== ''
        ? '/Anabelka/admin/translations/missing?section=categories'
        : '';

    if (editForm) {
        bindCategoryForm(
            editForm,
            editForm.querySelector('[type="submit"]'),
            editReturnUrl
        );
    }


    const createModal = document.getElementById('category-create-modal');
    const createForm = document.getElementById('category-create-form');
    const createParent = document.getElementById('category-create-parent');
    const createDepartment = document.getElementById(
        'category-create-department'
    );
    const createName = document.getElementById('category-create-name');
    const createContext = document.getElementById('category-create-context');

    document.querySelectorAll('[data-category-create]').forEach(function (button) {
        button.addEventListener('click', function () {
            const parentId = Number(button.dataset.parentId || 0);
            const departmentId = Number(button.dataset.departmentId || 0);
            const parent = categoryById.get(parentId);
            const department = departmentById.get(departmentId);

            createForm.reset();
            createParent.value = parentId > 0 ? String(parentId) : '';
            createDepartment.value = String(departmentId);
            createDepartment.disabled = parentId > 0;
            createContext.textContent = parent
                ? 'Батьківська категорія: «' + String(parent.name || '') + '». Підрозділ буде успадковано.'
                : 'Коренева категорія підрозділу «' + String(
                    department ? department.name : ''
                ) + '».';

            openModal(createModal, button, createName);
        });
    });

    if (createForm) {
        bindCategoryForm(
            createForm,
            createForm.querySelector('[type="submit"]')
        );
    }


    function descendantIds(categoryId)
    {
        const result = new Set([categoryId]);
        const queue = [categoryId];

        while (queue.length) {
            const parentId = queue.shift();

            categories.forEach(function (category) {
                const id = Number(category.id || 0);

                if (
                    Number(category.parent_id || 0) === parentId
                    && !result.has(id)
                ) {
                    result.add(id);
                    queue.push(id);
                }
            });
        }

        return result;
    }


    function categoryDepth(category)
    {
        let depth = 0;
        let current = category;
        const seen = new Set();

        while (
            current
            && Number(current.parent_id || 0) > 0
            && !seen.has(Number(current.id || 0))
        ) {
            seen.add(Number(current.id || 0));
            depth += 1;
            current = categoryById.get(
                Number(current.parent_id || 0)
            );
        }

        return depth;
    }


    function orderedMoveCandidates(excluded)
    {
        const children = new Map();
        const rootsByDepartment = new Map();
        const result = [];
        const visited = new Set();

        categories.forEach(function (category) {
            const id = Number(category.id || 0);

            if (id <= 0 || excluded.has(id)) {
                return;
            }

            const parentId = Number(category.parent_id || 0);
            const departmentId = Number(category.department_id || 0);

            if (parentId > 0 && !excluded.has(parentId)) {
                if (!children.has(parentId)) {
                    children.set(parentId, []);
                }

                children.get(parentId).push(category);
            } else {
                if (!rootsByDepartment.has(departmentId)) {
                    rootsByDepartment.set(departmentId, []);
                }

                rootsByDepartment.get(departmentId).push(category);
            }
        });

        function visit(category, depth)
        {
            const id = Number(category.id || 0);

            if (visited.has(id)) {
                return;
            }

            visited.add(id);
            result.push({
                category: category,
                depth: depth
            });

            (children.get(id) || []).forEach(function (child) {
                visit(child, depth + 1);
            });
        }

        departments.forEach(function (department) {
            const departmentId = Number(department.id || 0);

            (rootsByDepartment.get(departmentId) || []).forEach(
                function (category) {
                    visit(category, 0);
                }
            );
        });

        categories.forEach(function (category) {
            const id = Number(category.id || 0);

            if (
                id > 0
                && !excluded.has(id)
                && !visited.has(id)
            ) {
                visit(category, categoryDepth(category));
            }
        });

        return result;
    }


    function categoryPath(category)
    {
        const names = [];
        const seen = new Set();
        let current = category;

        while (current && !seen.has(Number(current.id || 0))) {
            seen.add(Number(current.id || 0));
            names.unshift(String(current.name || ''));
            current = categoryById.get(Number(current.parent_id || 0));
        }

        const department = departmentById.get(
            Number(category.department_id || 0)
        );

        return (department ? String(department.name || '') + ' / ' : '')
            + names.join(' / ');
    }


    const moveModal = document.getElementById('category-move-modal');
    const moveForm = document.getElementById('category-move-form');
    const moveId = document.getElementById('category-move-id');
    const moveParent = document.getElementById('category-move-parent');
    const moveDepartment = document.getElementById(
        'category-move-department'
    );
    const moveContext = document.getElementById('category-move-context');

    function syncMoveDepartment()
    {
        const parent = categoryById.get(Number(moveParent.value || 0));

        if (parent) {
            moveDepartment.value = String(parent.department_id || '');
            moveDepartment.disabled = true;
        } else {
            moveDepartment.disabled = false;
        }
    }

    if (moveParent) {
        moveParent.addEventListener('change', syncMoveDepartment);
    }

    document.querySelectorAll('[data-category-move]').forEach(function (button) {
        button.addEventListener('click', function () {
            const categoryId = Number(button.dataset.categoryMove || 0);
            const category = categoryById.get(categoryId);

            if (!category) {
                showMessage('Категорію не знайдено у списку.', true);
                return;
            }

            const excluded = descendantIds(categoryId);
            moveParent.innerHTML = '';
            const rootOption = document.createElement('option');
            rootOption.value = '';
            rootOption.textContent = 'Без батьківської категорії (корінь)';
            moveParent.appendChild(rootOption);

            orderedMoveCandidates(excluded).forEach(function (row) {
                const candidate = row.category;
                const candidateId = Number(candidate.id || 0);
                const option = document.createElement('option');

                option.value = String(candidateId);
                option.textContent = String(candidate.name || '');
                option.dataset.anabelkaRich = '1';
                option.dataset.anabelkaLabel =
                    String(candidate.name || '');
                option.dataset.anabelkaSubtitle =
                    categoryPath(candidate);
                option.dataset.anabelkaThumbnail =
                    String(candidate.thumbnail_image || '');
                option.dataset.anabelkaDepth =
                    String(row.depth || 0);
                moveParent.appendChild(option);
            });

            moveId.value = String(categoryId);
            moveParent.value = category.parent_id
                ? String(category.parent_id)
                : '';

            if (
                window.AnabelkaSelect
                && typeof window.AnabelkaSelect.refresh === 'function'
            ) {
                window.AnabelkaSelect.refresh(moveParent);
            }

            moveDepartment.value = String(category.department_id || '');
            moveContext.textContent = 'Переміщується «'
                + String(category.name || '')
                + '» разом з усіма нащадками.';
            syncMoveDepartment();
            openModal(moveModal, button, moveParent);
        });
    });

    if (moveForm) {
        bindCategoryForm(
            moveForm,
            moveForm.querySelector('[type="submit"]')
        );
    }



    const deleteModal = document.getElementById('category-delete-modal');
    const deleteForm = document.getElementById('category-delete-form');
    const deleteId = document.getElementById('category-delete-id');
    const deleteContext = document.getElementById('category-delete-context');

    document.querySelectorAll('[data-category-delete]').forEach(function (button) {
        button.addEventListener('click', function () {
            const categoryId = Number(button.dataset.categoryDelete || 0);
            const category = categoryById.get(categoryId);

            if (!category) {
                return;
            }

            deleteId.value = String(categoryId);
            deleteContext.textContent = 'Видалити категорію «'
                + String(category.name || '')
                + '»? Цю дію не можна скасувати.';
            openModal(deleteModal, button, null);
        });
    });

    if (deleteForm) {
        bindCategoryForm(
            deleteForm,
            deleteForm.querySelector('[type="submit"]')
        );
    }


    async function simpleAction(button, url, values)
    {
        const data = new FormData();
        data.append('_csrf', csrfToken());

        Object.keys(values).forEach(function (key) {
            data.append(key, String(values[key]));
        });

        button.disabled = true;

        try {
            const result = await request(url, data);
            storeSuccessMessage(result.message || 'Збережено.');
            window.location.reload();
        } catch (error) {
            button.disabled = false;
            showMessage(error.message || 'Операцію не виконано.', true);
        }
    }

    document.querySelectorAll('[data-category-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            simpleAction(button, '/Anabelka/admin/categories/toggle', {
                category_id: button.dataset.categoryId || '',
                field: button.dataset.categoryToggle || '',
                value: button.dataset.categoryValue || '0'
            });
        });
    });

    document.querySelectorAll('[data-category-reorder]').forEach(function (button) {
        button.addEventListener('click', function () {
            simpleAction(button, '/Anabelka/admin/categories/move', {
                category_id: button.dataset.categoryId || '',
                direction: button.dataset.categoryReorder || ''
            });
        });
    });


    const collapsedStorageKey = 'category-collapsed-items';

    function loadCollapsed()
    {
        try {
            const ids = JSON.parse(
                window.sessionStorage.getItem(collapsedStorageKey) || '[]'
            );
            return new Set(Array.isArray(ids) ? ids.map(String) : []);
        } catch (error) {
            return new Set();
        }
    }

    function saveCollapsed(ids)
    {
        try {
            window.sessionStorage.setItem(
                collapsedStorageKey,
                JSON.stringify(Array.from(ids))
            );
        } catch (error) {
            // Storage can be disabled; collapsing still works for this page.
        }
    }

    const collapsed = loadCollapsed();

    document.querySelectorAll('[data-category-collapse]').forEach(
        function (button) {
            const id = String(button.dataset.categoryCollapse || '');
            const children = document.querySelector(
                '[data-category-children="' + id + '"]'
            );

            function render()
            {
                const isCollapsed = collapsed.has(id);
                button.setAttribute(
                    'aria-expanded',
                    isCollapsed ? 'false' : 'true'
                );
                button.textContent = isCollapsed ? '›' : '⌄';
                button.title = isCollapsed ? 'Розгорнути гілку' : 'Згорнути гілку';

                if (children) {
                    children.hidden = isCollapsed;
                }
            }

            button.addEventListener('click', function () {
                if (collapsed.has(id)) {
                    collapsed.delete(id);
                } else {
                    collapsed.add(id);
                }

                saveCollapsed(collapsed);
                render();
            });

            render();
        }
    );
})();
