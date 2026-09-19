(function () {
    'use strict';

    const select = document.getElementById('product-edit-category');
    const dataElement = document.getElementById('category-thumbnail-data');
    const csrfField = document.getElementById('category-thumbnail-csrf');

    if (!select || !dataElement || !csrfField) {
        return;
    }

    let categories = {};

    try {
        categories = JSON.parse(dataElement.textContent || '{}');
    } catch (error) {
        categories = {};
    }

    function categoryState(categoryId)
    {
        const key = String(categoryId || '');

        if (!categories[key] || typeof categories[key] !== 'object') {
            categories[key] = {
                name: '',
                image: '',
                thumbnail_image: '',
                candidates: []
            };
        }

        return categories[key];
    }


    function closeEditors(except)
    {
        document.querySelectorAll(
            '.product-category-thumbnail-editor'
        ).forEach(function (editor) {
            if (editor !== except) {
                editor.remove();
            }
        });
    }


    function setStatus(editor, message, isError)
    {
        let status = editor.querySelector(
            '.product-category-thumbnail-status'
        );

        if (!status) {
            status = document.createElement('span');
            status.className = 'product-category-thumbnail-status';
            editor.appendChild(status);
        }

        status.textContent = String(message || '');
        status.classList.toggle('is-error', Boolean(isError));
        status.classList.toggle('is-success', !isError);
    }


    function updateThumbnailNode(node, path)
    {
        if (!node) {
            return;
        }

        path = String(path || '').trim();
        node.replaceChildren();
        node.classList.toggle('is-empty', path === '');

        if (path === '') {
            return;
        }

        const image = document.createElement('img');

        image.src = path;
        image.alt = '';
        image.loading = 'lazy';
        image.addEventListener('error', function () {
            image.remove();
            node.classList.add('is-empty');
        });
        node.appendChild(image);
    }


    function markChoice(editor, selectedPath)
    {
        selectedPath = String(selectedPath || '').trim();
        const auto = editor.querySelector(
            '.product-category-thumbnail-auto'
        );

        if (auto) {
            auto.classList.toggle(
                'is-selected',
                selectedPath === ''
            );
        }

        editor.querySelectorAll(
            '[data-category-thumbnail-choice]'
        ).forEach(function (button) {
            button.classList.toggle(
                'is-selected',
                String(button.dataset.categoryThumbnailChoice || '')
                    === selectedPath
            );
        });
    }


    async function saveThumbnail(context, editor, path)
    {
        const categoryId = Number(
            context.option ? context.option.value : 0
        );

        if (categoryId <= 0) {
            setStatus(editor, 'Категорію не знайдено.', true);
            return;
        }

        const payload = new FormData();

        payload.append('_csrf', csrfField.value);
        payload.append('category_id', String(categoryId));
        payload.append('image', String(path || ''));

        editor.classList.add('is-saving');

        try {
            const response = await fetch(
                '/Anabelka/admin/categories/thumbnail',
                {
                    method: 'POST',
                    body: payload,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                }
            );
            const text = await response.text();
            let result = {};

            try {
                result = JSON.parse(text);
            } catch (error) {
                throw new Error(
                    response.ok
                        ? 'Сервер повернув некоректну відповідь.'
                        : 'Не вдалося зберегти мініатюру.'
                );
            }

            if (!response.ok || !result.success) {
                throw new Error(
                    result.message || 'Не вдалося зберегти мініатюру.'
                );
            }

            const stored = result.thumbnail || {};
            const state = categoryState(categoryId);
            const manualImage = String(stored.image || '').trim();
            const thumbnailImage = String(
                stored.thumbnail_image || ''
            ).trim();

            state.image = manualImage;
            state.thumbnail_image = thumbnailImage;
            context.option.dataset.anabelkaThumbnail = thumbnailImage;

            updateThumbnailNode(
                context.button.querySelector(
                    '[data-anabelka-thumbnail-control]'
                ),
                thumbnailImage
            );
            markChoice(editor, manualImage);

            if (
                window.AnabelkaSelect
                && typeof window.AnabelkaSelect.sync === 'function'
            ) {
                window.AnabelkaSelect.sync(select);
            }

            setStatus(
                editor,
                manualImage === ''
                    ? 'Автоматичну мініатюру ввімкнено.'
                    : 'Мініатюру змінено.',
                false
            );
        } catch (error) {
            setStatus(
                editor,
                error.message || 'Не вдалося зберегти мініатюру.',
                true
            );
        } finally {
            editor.classList.remove('is-saving');
        }
    }


    function createEditor(context)
    {
        const categoryId = Number(
            context.option ? context.option.value : 0
        );
        const state = categoryState(categoryId);
        const candidates = Array.isArray(state.candidates)
            ? state.candidates
            : [];
        const editor = document.createElement('div');
        const head = document.createElement('div');
        const headCopy = document.createElement('div');
        const title = document.createElement('strong');
        const hint = document.createElement('span');
        const close = document.createElement('button');
        const auto = document.createElement('button');
        const grid = document.createElement('div');

        editor.className = 'product-category-thumbnail-editor';
        editor.setAttribute('role', 'group');
        editor.setAttribute(
            'aria-label',
            'Редагування мініатюри категорії'
        );

        head.className = 'product-category-thumbnail-editor-head';
        title.textContent = 'Мініатюра · '
            + String(state.name || context.option.textContent || '').trim();
        hint.textContent = 'Оберіть фото товару цієї категорії.';
        close.type = 'button';
        close.className = 'product-category-thumbnail-close';
        close.textContent = '×';
        close.setAttribute('aria-label', 'Закрити редагування мініатюри');
        close.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            editor.remove();
            context.button.focus();
        });

        headCopy.appendChild(title);
        headCopy.appendChild(hint);
        head.appendChild(headCopy);
        head.appendChild(close);

        auto.type = 'button';
        auto.className = 'product-category-thumbnail-auto';
        auto.textContent = 'Автоматично — останній товар із фото';
        auto.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            saveThumbnail(context, editor, '');
        });

        grid.className = 'product-category-thumbnail-grid';

        if (candidates.length === 0) {
            const empty = document.createElement('span');

            empty.className = 'product-category-thumbnail-empty';
            empty.textContent =
                'У товарів цієї категорії поки немає фотографій.';
            grid.appendChild(empty);
        } else {
            candidates.forEach(function (candidate) {
                const path = String(candidate.path || '').trim();

                if (path === '') {
                    return;
                }

                const button = document.createElement('button');
                const image = document.createElement('img');
                const label = document.createElement('span');

                button.type = 'button';
                button.className =
                    'product-category-thumbnail-choice';
                button.dataset.categoryThumbnailChoice = path;
                button.title = String(
                    candidate.product_name || 'Фото товару'
                );

                image.src = path;
                image.alt = '';
                image.loading = 'lazy';
                label.textContent = String(
                    candidate.product_name || 'Товар'
                );

                button.appendChild(image);
                button.appendChild(label);
                button.addEventListener('click', function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    saveThumbnail(context, editor, path);
                });
                grid.appendChild(button);
            });
        }

        editor.appendChild(head);
        editor.appendChild(auto);
        editor.appendChild(grid);
        markChoice(editor, String(state.image || '').trim());

        return editor;
    }


    select.addEventListener('anabelka:thumbnail-edit', function (event) {
        const context = event.detail || {};

        if (
            !context.button
            || !context.option
            || !context.list
            || Number(context.option.value || 0) <= 0
        ) {
            return;
        }

        const existing = context.button.nextElementSibling;

        if (
            existing
            && existing.classList.contains(
                'product-category-thumbnail-editor'
            )
        ) {
            existing.remove();
            return;
        }

        closeEditors();
        const editor = createEditor(context);

        context.button.insertAdjacentElement('afterend', editor);
        editor.scrollIntoView({
            behavior: 'smooth',
            block: 'nearest'
        });
    });


    document.addEventListener('click', function (event) {
        const editor = event.target.closest(
            '.product-category-thumbnail-editor'
        );
        const control = event.target.closest(
            '[data-anabelka-thumbnail-control]'
        );

        if (!editor && !control) {
            closeEditors();
        }
    });
}());
