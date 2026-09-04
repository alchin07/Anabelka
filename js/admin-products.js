(function () {
    'use strict';

    const dataElement = document.getElementById('admin-products-data');
    const editor = document.getElementById('product-editor');
    const form = document.getElementById('product-editor-form');

    if (!dataElement || !editor || !form) {
        return;
    }

    let products = [];

    try {
        products = JSON.parse(dataElement.textContent || '[]');
    } catch (error) {
        products = [];
    }

    const productsById = new Map();

    products.forEach(function (product) {
        productsById.set(String(product.id || ''), product);
    });

    const fields = {
        id: document.getElementById('product-edit-id'),
        category: document.getElementById('product-edit-category'),
        name: document.getElementById('product-edit-name'),
        slug: document.getElementById('product-edit-slug'),
        sku: document.getElementById('product-edit-sku'),
        description: document.getElementById('product-edit-description'),
        price: document.getElementById('product-edit-price'),
        oldPrice: document.getElementById('product-edit-old-price'),
        stock: document.getElementById('product-edit-stock'),
        stockMode: document.getElementById('product-edit-stock-mode'),
        showStock: document.getElementById('product-edit-show-stock'),
        brand: document.getElementById('product-edit-brand'),
        country: document.getElementById('product-edit-country'),
        active: document.getElementById('product-edit-active'),
        title: document.getElementById('product-editor-title'),
        kicker: document.getElementById('product-editor-kicker'),
        sizeList: document.getElementById('product-size-list'),
        sizeTemplate: document.getElementById('product-size-template'),
        imageList: document.getElementById('product-image-list'),
        imageInput: document.getElementById('product-image-input'),
        uploadPreview: document.getElementById('product-upload-preview'),
        save: form.querySelector('.product-editor-save'),
        translationDetails: form.querySelector('[data-translation-details]')
    };

    let uploadPreviewUrls = [];

    function showMessage(text)
    {
        const message = document.getElementById('site-message');

        if (!message) {
            window.alert(text);
            return;
        }

        message.textContent = text;
        message.classList.add('show');

        clearTimeout(window.adminProductMessageTimer);
        window.adminProductMessageTimer = window.setTimeout(function () {
            message.classList.remove('show');
        }, 3500);
    }


    function valueOrEmpty(value)
    {
        return value === null || typeof value === 'undefined'
            ? ''
            : String(value);
    }


    function addSizeRow(size)
    {
        if (!fields.sizeTemplate || !fields.sizeList) {
            return;
        }

        const fragment = fields.sizeTemplate.content.cloneNode(true);
        const row = fragment.querySelector('.product-size-row');
        const id = fragment.querySelector('[data-size-id]');
        const name = fragment.querySelector('[data-size-name]');
        const stock = fragment.querySelector('[data-size-stock]');

        id.value = valueOrEmpty(size && size.id ? size.id : 0);
        name.value = valueOrEmpty(size ? size.name : '');
        stock.value = valueOrEmpty(
            size && typeof size.stock !== 'undefined' ? size.stock : 0
        );

        fragment
            .querySelector('[data-size-remove]')
            .addEventListener('click', function () {
                row.remove();
            });

        fields.sizeList.appendChild(fragment);
        updateStockMode();
    }


    function renderSizes(sizes, addBlank)
    {
        fields.sizeList.innerHTML = '';

        (Array.isArray(sizes) ? sizes : []).forEach(function (size) {
            addSizeRow(size);
        });

        if (addBlank && fields.sizeList.children.length === 0) {
            addSizeRow(null);
        }
    }


    function hasEnteredSize()
    {
        return Array.from(
            fields.sizeList.querySelectorAll('[data-size-name]')
        ).some(function (input) {
            return input.value.trim() !== '';
        });
    }


    function focusSizeEditor(message)
    {
        let input = Array.from(
            fields.sizeList.querySelectorAll('[data-size-name]')
        ).find(function (item) {
            return item.value.trim() === '';
        });

        if (!input) {
            addSizeRow(null);
            input = fields.sizeList.querySelector(
                '.product-size-row:last-child [data-size-name]'
            );
        }

        const details = fields.sizeList.closest(
            'details.product-form-section'
        );

        if (details) {
            details.open = true;
        }

        showMessage(
            message
            || 'Вкажіть розмір товару, наприклад 75B. Для товару без розміру — «Універсальний».'
        );

        window.setTimeout(function () {
            if (!input) {
                return;
            }

            input.scrollIntoView({ behavior: 'smooth', block: 'center' });
            input.focus();
        }, 30);
    }


    function chooseAnotherMainImage()
    {
        const selected = fields.imageList.querySelector(
            'input[name="main_image_id"]:checked'
        );

        if (selected && !selected.closest('.product-image-manage').classList.contains('is-deleted')) {
            return;
        }

        const firstAvailable = Array.from(
            fields.imageList.querySelectorAll('.product-image-manage')
        ).find(function (item) {
            return !item.classList.contains('is-deleted');
        });

        if (firstAvailable) {
            const radio = firstAvailable.querySelector(
                'input[name="main_image_id"]'
            );

            if (radio) {
                radio.checked = true;
            }
        }
    }


    function moveImage(item, direction)
    {
        if (direction < 0) {
            const previous = item.previousElementSibling;

            if (previous) {
                fields.imageList.insertBefore(item, previous);
            }
            return;
        }

        const next = item.nextElementSibling;

        if (next) {
            fields.imageList.insertBefore(next, item);
        }
    }


    function imageManageCard(image)
    {
        const item = document.createElement('div');
        item.className = 'product-image-manage';
        item.dataset.imageId = String(image.id || '');

        const order = document.createElement('input');
        order.type = 'hidden';
        order.name = 'image_order[]';
        order.value = String(image.id || '');

        const preview = document.createElement('img');
        preview.src = String(image.path || '');
        preview.alt = '';

        const mainLabel = document.createElement('label');
        mainLabel.className = 'product-image-choice';
        const main = document.createElement('input');
        main.type = 'radio';
        main.name = 'main_image_id';
        main.value = String(image.id || '');
        main.checked = Number(image.is_main || 0) === 1;
        const mainText = document.createElement('span');
        mainText.textContent = 'Головна';
        mainLabel.appendChild(main);
        mainLabel.appendChild(mainText);

        const tools = document.createElement('div');
        tools.className = 'product-image-tools';
        const previous = document.createElement('button');
        previous.type = 'button';
        previous.textContent = '←';
        previous.setAttribute('aria-label', 'Перемістити раніше');
        previous.addEventListener('click', function () {
            moveImage(item, -1);
        });
        const next = document.createElement('button');
        next.type = 'button';
        next.textContent = '→';
        next.setAttribute('aria-label', 'Перемістити далі');
        next.addEventListener('click', function () {
            moveImage(item, 1);
        });

        const removeLabel = document.createElement('label');
        removeLabel.title = 'Видалити фотографію';
        const remove = document.createElement('input');
        remove.type = 'checkbox';
        remove.name = 'delete_images[]';
        remove.value = String(image.id || '');
        const removeText = document.createElement('span');
        removeText.textContent = '×';
        remove.addEventListener('change', function () {
            item.classList.toggle('is-deleted', remove.checked);
            main.disabled = remove.checked;
            chooseAnotherMainImage();
        });
        removeLabel.appendChild(remove);
        removeLabel.appendChild(removeText);

        tools.appendChild(previous);
        tools.appendChild(next);
        tools.appendChild(removeLabel);
        item.appendChild(order);
        item.appendChild(preview);
        item.appendChild(mainLabel);
        item.appendChild(tools);

        return item;
    }


    function renderImages(images)
    {
        fields.imageList.innerHTML = '';

        (Array.isArray(images) ? images : []).forEach(function (image) {
            fields.imageList.appendChild(imageManageCard(image));
        });

        chooseAnotherMainImage();
    }


    function clearUploadPreviews()
    {
        uploadPreviewUrls.forEach(function (url) {
            URL.revokeObjectURL(url);
        });
        uploadPreviewUrls = [];
        fields.uploadPreview.innerHTML = '';
    }


    function renderUploadPreviews()
    {
        clearUploadPreviews();
        const files = Array.from(fields.imageInput.files || []).slice(0, 8);

        files.forEach(function (file) {
            if (!String(file.type || '').startsWith('image/')) {
                return;
            }

            const url = URL.createObjectURL(file);
            const image = document.createElement('img');
            image.src = url;
            image.alt = '';
            uploadPreviewUrls.push(url);
            fields.uploadPreview.appendChild(image);
        });
    }


    function setTranslationWorkflow(section, source, status)
    {
        if (!section) {
            return;
        }

        const sourceField = section.querySelector(
            '.product-translation-source'
        );
        const sourceLabel = section.querySelector(
            '.product-translation-origin'
        );
        const statusField = section.querySelector(
            '.product-translation-status select'
        );
        const normalizedSource = source === 'ai' ? 'ai' : 'manual';
        const allowedStatuses = ['draft', 'review', 'approved', 'outdated'];
        const normalizedStatus = allowedStatuses.includes(status)
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


    function renderTranslations(translations)
    {
        translations = translations && typeof translations === 'object'
            ? translations
            : {};

        form.querySelectorAll('[data-product-language]').forEach(function (section) {
            const code = String(section.dataset.productLanguage || '');
            const translation = translations[code] || {};
            const name = section.querySelector('.product-translation-name');
            const description = section.querySelector(
                '.product-translation-description'
            );

            name.value = valueOrEmpty(translation.name);
            description.value = valueOrEmpty(translation.description);
            setTranslationWorkflow(
                section,
                translation.source || 'manual',
                translation.status || (
                    name.value.trim() || description.value.trim()
                        ? 'approved'
                        : 'draft'
                )
            );
            section.classList.remove('is-translation-focus');
        });
    }


    function updateStockMode()
    {
        const bySize = fields.stockMode.value === 'by_size';
        const totalField = form.querySelector('[data-total-stock-field]');
        const hint = form.querySelector('[data-size-stock-hint]');

        if (totalField) {
            totalField.hidden = bySize;
        }

        if (fields.stock) {
            fields.stock.required = !bySize;
        }

        form.querySelectorAll('[data-size-stock]').forEach(function (stock) {
            stock.readOnly = !bySize;
        });

        if (hint) {
            hint.textContent = bySize
                ? 'Вкажіть окрему кількість для кожного розміру.'
                : 'Для загального залишку кількість задається вище.';
        }
    }


    function requestedTranslationFocus(productId)
    {
        const params = new URLSearchParams(window.location.search);
        const requestedId = String(params.get('highlight') || '').trim();
        const language = String(params.get('focus_language') || '')
            .trim()
            .toLowerCase();

        if (requestedId !== String(productId || '') || !language) {
            return null;
        }

        return Array.from(
            form.querySelectorAll('[data-product-language]')
        ).find(function (section) {
            return String(section.dataset.productLanguage || '')
                .toLowerCase() === language;
        }) || null;
    }


    function focusEditor(productId)
    {
        const section = requestedTranslationFocus(productId);

        if (!section) {
            fields.name.focus();
            return;
        }

        fields.translationDetails.open = true;
        section.classList.add('is-translation-focus');
        const name = section.querySelector('.product-translation-name');
        const description = section.querySelector(
            '.product-translation-description'
        );
        const target = name && name.value.trim() === ''
            ? name
            : (description || name);

        window.setTimeout(function () {
            section.scrollIntoView({ block: 'center' });

            if (target) {
                target.focus();
            }
        }, 80);
    }


    function openEditor(product)
    {
        const isEdit = product && Number(product.id || 0) > 0;
        const currentCategory = new URLSearchParams(window.location.search)
            .get('category_id') || '';

        form.reset();
        clearUploadPreviews();
        fields.imageInput.value = '';
        fields.id.value = isEdit ? String(product.id) : '0';
        fields.category.value = isEdit
            ? valueOrEmpty(product.category_id)
            : currentCategory;
        fields.name.value = isEdit ? valueOrEmpty(product.name) : '';
        fields.slug.value = isEdit ? valueOrEmpty(product.slug) : '';
        fields.sku.value = isEdit ? valueOrEmpty(product.sku) : '';
        fields.description.value = isEdit
            ? valueOrEmpty(product.description)
            : '';
        fields.price.value = isEdit ? valueOrEmpty(product.price) : '';
        fields.oldPrice.value = isEdit
            ? valueOrEmpty(product.old_price)
            : '';
        fields.stock.value = isEdit ? valueOrEmpty(product.stock) : '0';
        fields.stockMode.value = isEdit
            ? valueOrEmpty(product.stock_mode || 'total')
            : 'total';
        fields.showStock.checked = isEdit
            && Number(product.show_stock_quantity || 0) === 1;
        fields.brand.value = isEdit ? valueOrEmpty(product.brand) : '';
        fields.country.value = isEdit ? valueOrEmpty(product.country) : '';
        fields.active.checked = !isEdit
            || Number(product.is_active || 0) === 1;
        fields.title.textContent = isEdit
            ? valueOrEmpty(product.name)
            : 'Новий товар';
        fields.kicker.textContent = isEdit
            ? 'Редагування товару'
            : 'Створення товару';

        form.querySelectorAll('[data-rank-price]').forEach(function (input) {
            const rankId = String(input.dataset.rankPrice || '');
            const rankPrices = isEdit && product.rank_prices
                ? product.rank_prices
                : {};
            let rankValue = rankPrices[rankId];

            if (
                (rankValue === null || typeof rankValue === 'undefined')
                && String(input.dataset.rankSlug || '').toLowerCase() === 'member'
                && isEdit
                && Number(product.member_price || 0) > 0
            ) {
                rankValue = product.member_price;
            }

            input.value = valueOrEmpty(rankValue);
        });

        renderSizes(isEdit ? product.sizes : [], true);
        renderImages(isEdit ? product.images : []);
        renderTranslations(isEdit ? product.translations : {});
        updateStockMode();

        form.querySelectorAll('details.product-form-section').forEach(function (details) {
            details.open = details.hasAttribute('data-translation-details')
                ? false
                : details.querySelector('[data-total-stock-field]') !== null
                    || details.querySelector('#product-image-list') !== null
                    || details.querySelector('#product-edit-price') !== null;
        });

        editor.hidden = false;
        document.body.classList.add('product-editor-open');
        window.setTimeout(function () {
            focusEditor(isEdit ? product.id : 0);
        }, 30);
    }


    function closeEditor()
    {
        editor.hidden = true;
        document.body.classList.remove('product-editor-open');
        clearUploadPreviews();
    }


    function translationReturnUrl()
    {
        const params = new URLSearchParams(window.location.search);
        const requestedId = String(params.get('highlight') || '').trim();
        const language = String(params.get('focus_language') || '').trim();

        return /^\d+$/.test(requestedId) && language
            ? '/Anabelka/admin/translations/missing?section=products'
            : '';
    }


    document.querySelectorAll('[data-product-edit]').forEach(function (button) {
        button.addEventListener('click', function () {
            const product = productsById.get(
                String(button.dataset.productId || '')
            );

            if (product) {
                openEditor(product);
            }
        });
    });

    document.querySelectorAll('[data-product-create]').forEach(function (button) {
        button.addEventListener('click', function () {
            openEditor(null);
        });
    });

    editor.querySelectorAll('[data-product-close]').forEach(function (button) {
        button.addEventListener('click', closeEditor);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !editor.hidden) {
            closeEditor();
        }
    });

    form.querySelector('[data-size-add]').addEventListener('click', function () {
        addSizeRow(null);
        const rows = fields.sizeList.querySelectorAll('.product-size-row');
        const last = rows[rows.length - 1];

        if (last) {
            last.querySelector('[data-size-name]').focus();
        }
    });

    fields.stockMode.addEventListener('change', updateStockMode);
    fields.imageInput.addEventListener('change', renderUploadPreviews);

    form.addEventListener('input', function (event) {
        const field = event.target;

        if (!field.matches(
            '.product-translation-name, .product-translation-description'
        )) {
            return;
        }

        const section = field.closest('[data-product-language]');
        const status = section.querySelector(
            '.product-translation-status select'
        );
        const hasContent = section.querySelector(
            '.product-translation-name'
        ).value.trim() !== '' || section.querySelector(
            '.product-translation-description'
        ).value.trim() !== '';

        setTranslationWorkflow(
            section,
            'manual',
            status.value === 'draft' && hasContent
                ? 'approved'
                : status.value
        );
    });

    form.addEventListener('change', function (event) {
        if (!event.target.matches('.product-translation-status select')) {
            return;
        }

        const section = event.target.closest('[data-product-language]');

        if (section) {
            section.dataset.translationStatus = event.target.value;
        }
    });

    form.addEventListener('click', async function (event) {
        const button = event.target.closest('[data-product-ai-translate]');

        if (!button) {
            return;
        }

        const section = button.closest('[data-product-language]');

        if (
            !section
            || !window.AnabelkaAITranslation
            || typeof window.AnabelkaAITranslation.suggest !== 'function'
        ) {
            showMessage('Система ШІ-перекладу ще завантажується. Спробуйте ще раз.');
            return;
        }

        const originalText = button.textContent;
        button.disabled = true;
        button.textContent = 'Переклад…';

        try {
            const translation = await window.AnabelkaAITranslation.suggest({
                targetLanguage: button.dataset.targetLanguage || '',
                name: fields.name.value,
                description: fields.description.value,
                context: 'product'
            });

            section.querySelector('.product-translation-name').value =
                translation.name || '';
            section.querySelector('.product-translation-description').value =
                translation.description || '';
            setTranslationWorkflow(section, 'ai', 'draft');
            showMessage('ШІ-переклад отримано. Перевірте його перед збереженням.');
        } catch (error) {
            showMessage(error.message || 'Не вдалося отримати ШІ-переклад.');
        } finally {
            button.disabled = false;
            button.textContent = originalText;
        }
    });

    document.querySelectorAll('[data-product-duplicate-form]').forEach(function (duplicateForm) {
        duplicateForm.addEventListener('submit', function (event) {
            if (!window.confirm(
                'Створити приховану копію товару з цінами, розмірами та фотографіями?'
            )) {
                event.preventDefault();
            }
        });
    });

    document.querySelectorAll('[data-product-toggle-form]').forEach(function (toggleForm) {
        toggleForm.addEventListener('submit', function (event) {
            if (
                toggleForm.dataset.nextActive === '0'
                && !window.confirm('Приховати товар із сайту? Замовлення збережуться.')
            ) {
                event.preventDefault();
            }
        });
    });

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        if (!form.reportValidity()) {
            return;
        }

        if (!hasEnteredSize()) {
            focusSizeEditor();
            return;
        }

        const originalText = fields.save.textContent;
        fields.save.disabled = true;
        fields.save.textContent = 'Збереження…';

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            const responseText = await response.text();
            let data = {};

            try {
                data = JSON.parse(responseText);
            } catch (parseError) {
                throw new Error(
                    response.ok
                        ? 'Сервер повернув некоректну відповідь.'
                        : 'Не вдалося зберегти товар. Перевірте розмір фотографій.'
                );
            }

            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Не вдалося зберегти товар.');
            }

            showMessage(data.message || 'Товар збережено.');

            window.setTimeout(function () {
                const returnUrl = translationReturnUrl();

                if (returnUrl) {
                    window.location.replace(returnUrl);
                    return;
                }

                if (Number(fields.id.value || 0) <= 0 && data.product_id) {
                    window.location.replace(
                        '/Anabelka/admin/products?highlight='
                        + encodeURIComponent(data.product_id)
                    );
                    return;
                }

                window.location.reload();
            }, 450);
        } catch (error) {
            const message = error.message || 'Не вдалося зберегти товар.';

            if (message.indexOf('Додайте хоча б один розмір') !== -1) {
                focusSizeEditor(message);
            } else {
                showMessage(message);
            }

            fields.save.disabled = false;
            fields.save.textContent = originalText;
        }
    });

    const params = new URLSearchParams(window.location.search);
    const highlightedId = String(params.get('highlight') || '').trim();

    if (/^\d+$/.test(highlightedId)) {
        const highlightedProduct = productsById.get(highlightedId);

        if (highlightedProduct) {
            window.setTimeout(function () {
                openEditor(highlightedProduct);
            }, 220);
        }
    }
})();
