(function () {
    'use strict';

    const selects = Array.from(
        document.querySelectorAll(
            'select[data-category-thumbnail-select]'
        )
    );
    const dataElement =
        document.getElementById('category-thumbnail-data')
        || document.getElementById('category-manager-data');
    const csrfField =
        document.getElementById('category-thumbnail-csrf')
        || document.querySelector(
            '.category-modal input[name="_csrf"]'
        );

    if (selects.length === 0 || !dataElement || !csrfField) {
        return;
    }

    let categories = {};

    try {
        const raw = JSON.parse(dataElement.textContent || '{}');

        if (Array.isArray(raw.categories)) {
            raw.categories.forEach(function (category) {
                const id = String(category.id || '');

                if (id === '') {
                    return;
                }

                categories[id] = {
                    name: String(category.name || ''),
                    image: String(category.image || ''),
                    thumbnail_image: String(
                        category.thumbnail_image || ''
                    ),
                    candidates: Array.isArray(
                        category.thumbnail_candidates
                    ) ? category.thumbnail_candidates : []
                };
            });
        } else if (raw && typeof raw === 'object') {
            categories = raw;
        }
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


    function updateAllOptions(categoryId, thumbnailImage)
    {
        const value = String(categoryId || '');

        selects.forEach(function (select) {
            const option = Array.from(select.options).find(
                function (item) {
                    return String(item.value || '') === value;
                }
            );

            if (option) {
                option.dataset.anabelkaThumbnail = thumbnailImage;
            }

            if (
                window.AnabelkaSelect
                && typeof window.AnabelkaSelect.sync === 'function'
            ) {
                window.AnabelkaSelect.sync(select);
            }
        });
    }


    async function saveThumbnail(context, editor, path, file)
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

        if (file instanceof File) {
            payload.append('thumbnail_file', file, file.name);
        }

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

            if (file instanceof File && manualImage !== '') {
                const grid = editor.querySelector(
                    '.product-category-thumbnail-grid'
                );
                const existing = grid
                    ? Array.from(
                        grid.querySelectorAll(
                            '[data-category-thumbnail-choice]'
                        )
                    ).find(function (button) {
                        return String(
                            button.dataset.categoryThumbnailChoice || ''
                        ) === manualImage;
                    })
                    : null;

                if (grid && !existing) {
                    const empty = grid.querySelector(
                        '.product-category-thumbnail-empty'
                    );

                    if (empty) {
                        empty.remove();
                    }

                    grid.insertBefore(
                        createChoice(
                            manualImage,
                            'Завантажене фото',
                            'Поточна завантажена мініатюра',
                            context,
                            editor
                        ),
                        grid.firstChild
                    );
                }
            }

            updateAllOptions(categoryId, thumbnailImage);
            updateThumbnailNode(
                context.button.querySelector(
                    '[data-anabelka-thumbnail-control]'
                ),
                thumbnailImage
            );
            markChoice(editor, manualImage);

            document.dispatchEvent(new CustomEvent(
                'anabelka:category-thumbnail-updated',
                {
                    detail: {
                        categoryId: categoryId,
                        image: manualImage,
                        thumbnailImage: thumbnailImage
                    }
                }
            ));

            setStatus(
                editor,
                file instanceof File
                    ? 'Фото оброблено до 320×320 і збережено.'
                    : (
                        manualImage === ''
                            ? 'Автоматичну мініатюру ввімкнено.'
                            : 'Мініатюру змінено.'
                    ),
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


    function createChoice(path, labelText, titleText, context, editor)
    {
        const button = document.createElement('button');
        const image = document.createElement('img');
        const label = document.createElement('span');

        button.type = 'button';
        button.className = 'product-category-thumbnail-choice';
        button.dataset.categoryThumbnailChoice = path;
        button.title = String(titleText || labelText || 'Фото');

        image.src = path;
        image.alt = '';
        image.loading = 'lazy';
        label.textContent = String(labelText || 'Фото');

        button.appendChild(image);
        button.appendChild(label);
        button.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            saveThumbnail(context, editor, path);
        });

        return button;
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
        const upload = document.createElement('label');
        const uploadInput = document.createElement('input');
        const uploadText = document.createElement('span');
        const auto = document.createElement('button');
        const grid = document.createElement('div');
        const candidatePaths = new Set();

        editor.className = 'product-category-thumbnail-editor';
        editor.setAttribute('role', 'group');
        editor.setAttribute(
            'aria-label',
            'Редагування мініатюри категорії'
        );

        head.className = 'product-category-thumbnail-editor-head';
        title.textContent = 'Мініатюра · '
            + String(state.name || context.option.textContent || '').trim();
        hint.textContent =
            'Фото товару або власне фото з телефона / комп’ютера.';
        close.type = 'button';
        close.className = 'product-category-thumbnail-close';
        close.textContent = '×';
        close.setAttribute(
            'aria-label',
            'Закрити редагування мініатюри'
        );
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

        upload.className = 'product-category-thumbnail-upload';
        uploadInput.type = 'file';
        uploadInput.accept = 'image/jpeg,image/png,image/webp';
        uploadText.textContent = '＋ Завантажити фото';
        upload.appendChild(uploadInput);
        upload.appendChild(uploadText);

        uploadInput.addEventListener('change', function () {
            const file = uploadInput.files
                ? uploadInput.files[0]
                : null;

            if (!file) {
                return;
            }

            if (file.size > 8388608) {
                setStatus(
                    editor,
                    'Фото має бути не більше 8 МБ.',
                    true
                );
                uploadInput.value = '';
                return;
            }

            if (!/^image\/(jpeg|png|webp)$/i.test(file.type || '')) {
                setStatus(
                    editor,
                    'Підтримуються JPG, PNG та WebP.',
                    true
                );
                uploadInput.value = '';
                return;
            }

            saveThumbnail(context, editor, '', file);
            uploadInput.value = '';
        });

        auto.type = 'button';
        auto.className = 'product-category-thumbnail-auto';
        auto.textContent = 'Автоматично — останній товар із фото';
        auto.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            saveThumbnail(context, editor, '');
        });

        grid.className = 'product-category-thumbnail-grid';

        candidates.forEach(function (candidate) {
            const path = String(candidate.path || '').trim();

            if (path === '' || candidatePaths.has(path)) {
                return;
            }

            candidatePaths.add(path);
            grid.appendChild(createChoice(
                path,
                String(candidate.product_name || 'Товар'),
                String(candidate.product_name || 'Фото товару'),
                context,
                editor
            ));
        });

        const currentManual = String(state.image || '').trim();

        if (
            currentManual !== ''
            && !candidatePaths.has(currentManual)
        ) {
            grid.insertBefore(
                createChoice(
                    currentManual,
                    'Завантажене фото',
                    'Поточна завантажена мініатюра',
                    context,
                    editor
                ),
                grid.firstChild
            );
        }

        if (grid.children.length === 0) {
            const empty = document.createElement('span');

            empty.className = 'product-category-thumbnail-empty';
            empty.textContent =
                'У товарів цієї категорії поки немає фотографій.';
            grid.appendChild(empty);
        }

        editor.appendChild(head);
        editor.appendChild(upload);
        editor.appendChild(auto);
        editor.appendChild(grid);
        markChoice(editor, currentManual);

        return editor;
    }


    document.addEventListener(
        'anabelka:thumbnail-edit',
        function (event) {
            const select = event.target.closest(
                'select[data-category-thumbnail-select]'
            );
            const context = event.detail || {};

            if (
                !select
                || !context.button
                || !context.option
                || !context.list
                || Number(context.option.value || 0) <= 0
            ) {
                return;
            }

            context.select = select;
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
        }
    );


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
