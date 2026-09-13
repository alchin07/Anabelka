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

    categories.forEach(function (category) {
        categoryById.set(Number(category.id || 0), category);
    });
    departments.forEach(function (department) {
        departmentById.set(Number(department.id || 0), department);
    });

    function showMessage(message, isError)
    {
        const element = document.getElementById('site-message');

        if (!element) {
            return;
        }

        element.textContent = String(message || '');
        element.classList.toggle('is-error', Boolean(isError));
        element.classList.add('show');
        window.clearTimeout(window.categoryManagerMessageTimer);
        window.categoryManagerMessageTimer = window.setTimeout(function () {
            element.classList.remove('show');
        }, isError ? 4500 : 2400);
    }


    function openModal(modal, trigger, focusField)
    {
        closeModals(false);
        lastTrigger = trigger || null;
        modal.hidden = false;
        document.body.style.overflow = 'hidden';

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


    function csrfToken()
    {
        const field = document.querySelector(
            '.category-modal input[name="_csrf"]'
        );

        return field ? field.value : '';
    }


    async function request(url, body)
    {
        const response = await fetch(url, {
            method: 'POST',
            body: body,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        const text = await response.text();
        let result;

        try {
            result = JSON.parse(text);
        } catch (error) {
            throw new Error(
                response.ok
                    ? 'Сервер повернув некоректну відповідь.'
                    : 'Помилка сервера. Перевірте журнал KSWEB.'
            );
        }

        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Операцію не виконано.');
        }

        return result;
    }


    function submitAndReload(form, submitButton, returnUrl)
    {
        return async function (event) {
            event.preventDefault();

            if (submitButton) {
                submitButton.disabled = true;
            }

            try {
                const result = await request(form.action, new FormData(form));
                showMessage(result.message || 'Збережено.', false);
                closeModals(false);

                window.setTimeout(function () {
                    if (returnUrl) {
                        window.location.replace(returnUrl);
                    } else {
                        window.location.reload();
                    }
                }, 300);
            } catch (error) {
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
        }
        section.dataset.translationStatus = normalizedStatus;
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
        editForm.addEventListener(
            'submit',
            submitAndReload(
                editForm,
                editForm.querySelector('[type="submit"]'),
                editReturnUrl
            )
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
        createForm.addEventListener(
            'submit',
            submitAndReload(
                createForm,
                createForm.querySelector('[type="submit"]')
            )
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

            categories.forEach(function (candidate) {
                const candidateId = Number(candidate.id || 0);

                if (excluded.has(candidateId)) {
                    return;
                }

                const option = document.createElement('option');
                option.value = String(candidateId);
                option.textContent = categoryPath(candidate);
                moveParent.appendChild(option);
            });

            moveId.value = String(categoryId);
            moveParent.value = category.parent_id
                ? String(category.parent_id)
                : '';
            moveDepartment.value = String(category.department_id || '');
            moveContext.textContent = 'Переміщується «'
                + String(category.name || '')
                + '» разом з усіма нащадками.';
            syncMoveDepartment();
            openModal(moveModal, button, moveParent);
        });
    });

    if (moveForm) {
        moveForm.addEventListener(
            'submit',
            submitAndReload(
                moveForm,
                moveForm.querySelector('[type="submit"]')
            )
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
        deleteForm.addEventListener(
            'submit',
            submitAndReload(
                deleteForm,
                deleteForm.querySelector('[type="submit"]')
            )
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
            showMessage(result.message || 'Збережено.', false);
            window.setTimeout(function () {
                window.location.reload();
            }, 250);
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
