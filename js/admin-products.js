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
        material: document.getElementById('product-edit-material'),
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
    let productEditorHistoryArmed = false;
    let productEditorHistoryToken = 0;
    const productEditorHistoryKey = '__anabelkaProductEditor';
    const imageCompareHistoryKey = '__anabelkaProductImageCompare';
    const imageCompareDetailsHistoryKey =
        '__anabelkaProductImageCompareDetails';


    function currentProductEditorUrl()
    {
        return window.location.pathname
            + window.location.search
            + window.location.hash;
    }


    function cleanProductEditorState(source)
    {
        const state = source && typeof source === 'object'
            ? Object.assign({}, source)
            : {};

        delete state[productEditorHistoryKey];
        delete state[imageCompareHistoryKey];
        delete state[imageCompareDetailsHistoryKey];

        return state;
    }


    function armProductEditorHistory()
    {
        /*
         * Each opening owns a fresh history entry. Do not trust a boolean
         * left from an earlier Back cycle: Android may restore history state
         * independently from JavaScript variables.
         */
        productEditorHistoryToken += 1;

        const baseState = cleanProductEditorState(history.state);

        history.replaceState(
            baseState,
            '',
            currentProductEditorUrl()
        );

        const editorState = Object.assign({}, baseState);

        editorState[productEditorHistoryKey] =
            productEditorHistoryToken;

        history.pushState(
            editorState,
            '',
            currentProductEditorUrl()
        );
        productEditorHistoryArmed = true;
    }


    function disarmProductEditorHistory()
    {
        if (!productEditorHistoryArmed) {
            return;
        }

        productEditorHistoryArmed = false;
        const state = history.state || {};
        const comparisonDepth = state[imageCompareDetailsHistoryKey]
            ? 2
            : (state[imageCompareHistoryKey] ? 1 : 0);
        unwindImageComparisonHistory(comparisonDepth + 1);
    }


    if (
        editor.hidden
        && history.state
        && typeof history.state === 'object'
        && history.state[productEditorHistoryKey]
    ) {
        history.replaceState(
            cleanProductEditorState(history.state),
            '',
            currentProductEditorUrl()
        );
    }


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


    let activeImageCompareModal = null;
    let activeImageCompareDetails = null;
    let activeImagePreview = null;
    const imageProcessingSelections = new WeakMap();
    let imagePreviewGeneration = 0;
    let imageCompareHistoryUnwinding = false;
    let imageCompareHistorySettled = Promise.resolve();
    let settleImageCompareHistory = null;
    let imageCompareHistoryPendingDepth = 0;


    function unwindImageComparisonHistory(depth)
    {
        const remainingDepth = Math.max(0, depth - imageCompareHistoryPendingDepth);
        if (remainingDepth === 0) {
            return;
        }

        imageCompareHistoryPendingDepth += remainingDepth;

        function traverse()
        {
            return new Promise(function (resolve) {
                settleImageCompareHistory = resolve;
                history.go(-remainingDepth);
            });
        }

        imageCompareHistorySettled = imageCompareHistoryUnwinding
            ? imageCompareHistorySettled.then(traverse)
            : traverse();
        imageCompareHistoryUnwinding = true;
        const lastTraversal = imageCompareHistorySettled;
        lastTraversal.then(function () {
            if (imageCompareHistorySettled === lastTraversal) {
                imageCompareHistoryUnwinding = false;
                imageCompareHistoryPendingDepth = 0;
            }
        });
    }


    async function imageProcessingRequest(action, payload)
    {
        const response = await fetch(
            '/Anabelka/admin/products/image-process-' + action,
            {
                method: 'POST',
                body: payload,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            }
        );
        let data;

        try {
            data = JSON.parse(await response.text());
        } catch (error) {
            throw new Error('Сервер повернув некоректну відповідь.');
        }

        if (!response.ok || !data || !data.success) {
            throw new Error(
                (data && data.message)
                || 'Не вдалося обробити фотографію.'
            );
        }

        return data;
    }


    function imagePreviewPayload(preview)
    {
        const payload = new FormData();
        payload.append('_csrf', preview.csrf);
        payload.append('image_id', String(preview.imageId));
        payload.append('preview_id', preview.previewId);
        return payload;
    }


    function cancelImagePreview(preview)
    {
        if (!preview) {
            return;
        }

        preview.dismissed = true;

        if (activeImagePreview === preview) {
            activeImagePreview = null;
        }

        if (preview.confirming || preview.committed) {
            return;
        }

        preview.setBusy(false);

        if (!preview.previewId || preview.cancelled) {
            return;
        }

        preview.cancelled = true;
        // A late request is allowed to return its token so it can be cleaned up.
        // Failed cleanup is harmless: previews also expire on the server.
        imageProcessingRequest('cancel', imagePreviewPayload(preview))
            .catch(function () {});
    }


    function cleanImageComparisonState(source)
    {
        const state = Object.assign({}, source || {});
        delete state[imageCompareHistoryKey];
        delete state[imageCompareDetailsHistoryKey];
        return state;
    }


    function publicProductImageUrl(path)
    {
        const normalized = valueOrEmpty(path)
            .trim()
            .replace(/\\/g, '/');

        if (normalized.indexOf('/Anabelka/uploads/products/') === 0) {
            return normalized;
        }

        if (normalized.indexOf('uploads/products/') === 0) {
            return '/Anabelka/' + normalized;
        }

        return '';
    }


    function processedMasterUrl(image)
    {
        const processing = image
            && image.processing
            && typeof image.processing === 'object'
            ? image.processing
            : {};

        if (String(processing.status || '') !== 'ready') {
            return '';
        }

        const path = valueOrEmpty(processing.master_path)
            .trim()
            .replace(/\\/g, '/');

        if (
            path.indexOf('uploads/products/processed/') !== 0
            && path.indexOf('/Anabelka/uploads/products/processed/') !== 0
        ) {
            return '';
        }

        return publicProductImageUrl(path);
    }


    function closeImageComparison(options)
    {
        options = options && typeof options === 'object'
            ? options
            : {};

        if (!activeImageCompareModal) {
            return;
        }

        if (options.cancelPreview !== false) {
            cancelImagePreview(activeImagePreview);
        }

        activeImageCompareModal.remove();
        activeImageCompareModal = null;
        activeImageCompareDetails = null;
        document.documentElement.classList.remove(
            'product-image-compare-open'
        );
        document.body.classList.remove('product-image-compare-open');

        const state = history.state || {};
        const depth = state[imageCompareDetailsHistoryKey]
            ? 2
            : (state[imageCompareHistoryKey] ? 1 : 0);

        if (options.syncHistory !== false && depth > 0) {
            unwindImageComparisonHistory(depth);
        }
    }


    function openImageComparison(image, preview)
    {
        if (!preview) {
            cancelImagePreview(activeImagePreview);
            imagePreviewGeneration += 1;
        }

        if (imageCompareHistoryUnwinding) {
            imageCompareHistorySettled.then(function () {
                if (!editor.hidden && (!preview || !preview.dismissed)) {
                    openImageComparison(image, preview);
                }
            });
            return;
        }

        const originalUrl = publicProductImageUrl(image ? image.path : '');
        const processedUrl = preview
            ? preview.previewUrl
            : processedMasterUrl(image);

        if (!originalUrl || !processedUrl) {
            cancelImagePreview(preview);
            showMessage(
                'Для порівняння потрібна готова оброблена фотографія.'
            );
            return;
        }

        if (activeImageCompareModal) {
            closeImageComparison();
            openImageComparison(image, preview);
            return;
        }

        const processing = preview ? preview.processing : (image.processing || {});
        const modal = document.createElement('div');
        modal.className = 'product-image-compare-modal';
        modal.dataset.productImageCompareModal = '';

        const dialog = document.createElement('div');
        dialog.className = 'product-image-compare-dialog';
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute(
            'aria-label',
            'Порівняння оригіналу та обробленої фотографії'
        );

        const header = document.createElement('div');
        header.className = 'product-image-compare-header';

        const title = document.createElement('strong');
        title.textContent = preview
            ? 'Попередній перегляд'
            : 'Порівняння фотографії';

        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'product-image-compare-close';
        close.textContent = '×';
        close.setAttribute('aria-label', 'Закрити порівняння');

        header.appendChild(title);
        header.appendChild(close);

        const stage = document.createElement('div');
        stage.className = 'product-image-compare-stage';
        stage.style.setProperty('--compare-split', '50%');

        const original = document.createElement('img');
        original.className = 'product-image-compare-original';
        original.src = originalUrl;
        original.alt = 'Оригінальна фотографія';

        const processed = document.createElement('img');
        processed.className = 'product-image-compare-processed';
        processed.src = processedUrl;
        processed.alt = 'Оброблена фотографія';

        const originalLabel = document.createElement('span');
        originalLabel.className =
            'product-image-compare-label is-original';
        originalLabel.textContent = 'Оригінал';

        const processedLabel = document.createElement('span');
        processedLabel.className =
            'product-image-compare-label is-processed';
        processedLabel.textContent = 'Оброблене';

        const divider = document.createElement('span');
        divider.className = 'product-image-compare-divider';
        divider.setAttribute('role', 'slider');
        divider.setAttribute('tabindex', '0');
        divider.setAttribute(
            'aria-label',
            'Межа порівняння оригіналу та обробленої фотографії'
        );
        divider.setAttribute('aria-valuemin', '0');
        divider.setAttribute('aria-valuemax', '100');
        divider.setAttribute('aria-valuenow', '50');

        stage.appendChild(original);
        stage.appendChild(processed);
        stage.appendChild(originalLabel);
        stage.appendChild(processedLabel);
        stage.appendChild(divider);

        const sliderWrap = document.createElement('label');
        sliderWrap.className = 'product-image-compare-slider';

        const sliderText = document.createElement('span');
        sliderText.textContent =
            'Перетягніть повзунок для порівняння';

        const slider = document.createElement('input');
        slider.type = 'range';
        slider.min = '0';
        slider.max = '100';
        slider.value = '50';
        slider.step = '1';
        slider.setAttribute(
            'aria-label',
            'Положення межі порівняння'
        );

        function setCompareSplit(value)
        {
            const normalized = Math.max(
                0,
                Math.min(100, Number(value || 0))
            );

            stage.style.setProperty(
                '--compare-split',
                normalized + '%'
            );
            slider.value = String(Math.round(normalized));
            slider.setAttribute(
                'aria-valuetext',
                Math.round(normalized)
                + '% ширини оригіналу'
            );
            divider.setAttribute(
                'aria-valuenow',
                String(Math.round(normalized))
            );
        }

        function compareSplitFromPointer(event)
        {
            const rect = stage.getBoundingClientRect();

            if (!rect.width) {
                return Number(slider.value || 50);
            }

            return (
                (event.clientX - rect.left)
                / rect.width
                * 100
            );
        }

        slider.addEventListener('input', function () {
            setCompareSplit(slider.value);
        });

        divider.addEventListener('pointerdown', function (event) {
            event.preventDefault();
            divider.setPointerCapture(event.pointerId);
            setCompareSplit(compareSplitFromPointer(event));
        });

        divider.addEventListener('pointermove', function (event) {
            if (!divider.hasPointerCapture(event.pointerId)) {
                return;
            }

            event.preventDefault();
            setCompareSplit(compareSplitFromPointer(event));
        });

        function finishDividerDrag(event)
        {
            if (divider.hasPointerCapture(event.pointerId)) {
                divider.releasePointerCapture(event.pointerId);
            }
        }

        divider.addEventListener('pointerup', finishDividerDrag);
        divider.addEventListener('pointercancel', finishDividerDrag);

        divider.addEventListener('keydown', function (event) {
            const current = Number(slider.value || 50);
            let next = current;

            if (event.key === 'ArrowLeft') {
                next = current - 2;
            } else if (event.key === 'ArrowRight') {
                next = current + 2;
            } else if (event.key === 'Home') {
                next = 0;
            } else if (event.key === 'End') {
                next = 100;
            } else {
                return;
            }

            event.preventDefault();
            setCompareSplit(next);
        });

        setCompareSplit(50);

        sliderWrap.appendChild(sliderText);
        sliderWrap.appendChild(slider);

        const meta = document.createElement('div');
        meta.className = 'product-image-compare-meta';

        const sourceWidth = Number(processing.source_width || 0);
        const sourceHeight = Number(processing.source_height || 0);
        const masterWidth = Number(processing.master_width || 0);
        const masterHeight = Number(processing.master_height || 0);
        const version = valueOrEmpty(processing.processor_version);

        const metaParts = [];

        if (sourceWidth > 0 && sourceHeight > 0) {
            metaParts.push(
                'Оригінал '
                + sourceWidth
                + '×'
                + sourceHeight
            );
        }

        if (masterWidth > 0 && masterHeight > 0) {
            metaParts.push(
                'Оброблене '
                + masterWidth
                + '×'
                + masterHeight
            );
        }

        if (version) {
            metaParts.push(version);
        }

        meta.textContent = metaParts.join(' · ');

        const diagnostics = document.createElement('div');
        diagnostics.className = 'product-image-compare-diagnostics';

        const normalization = processing.normalization
            && typeof processing.normalization === 'object'
            ? processing.normalization
            : {};
        const method = valueOrEmpty(normalization.method);
        const methodLabels = {
            'mediapipe-persondet': 'MediaPipe Person',
            'mediapipe-persondet-no-crop': 'MediaPipe знайдено · crop не застосовано',
            'opencv-haar-face-subject': 'OpenCV Face + subject',
            'opencv-haar-face-subject-no-crop': 'Face знайдено · crop не застосовано',
            'opencv-hog-person': 'OpenCV HOG',
            'opencv-hog-person-no-crop': 'HOG знайдено · crop не застосовано',
            'person-detected-no-crop': 'Безпечний crop не застосовано',
            'standard-canvas-fallback': 'Fallback 2:3'
        };

        function diagnosticBadge(text, state)
        {
            const badge = document.createElement('span');
            badge.className = 'product-image-compare-diagnostic';

            if (state) {
                badge.dataset.state = state;
            }

            badge.textContent = text;
            diagnostics.appendChild(badge);
        }

        if (method) {
            diagnosticBadge(
                'Модель: '
                + (
                    normalization.subject_detected === true
                        ? 'знайдена'
                        : 'не знайдена'
                ),
                normalization.subject_detected === true
                    ? 'success'
                    : 'neutral'
            );
            diagnosticBadge(
                'Кадрування: '
                + (
                    normalization.crop_applied === true
                        ? 'застосовано'
                        : 'ні'
                ),
                normalization.crop_applied === true
                    ? 'success'
                    : 'neutral'
            );

            if (normalization.zoom_out_applied === true) {
                diagnosticBadge(
                    'Масштаб: зменшено',
                    'success'
                );
            }

            diagnosticBadge(
                'Метод: ' + (methodLabels[method] || method),
                'info'
            );

            const backgroundProfile = valueOrEmpty(
                normalization.background_profile
            );
            const requestedBackgroundProfile = valueOrEmpty(
                normalization.background_profile_requested
            );
            const backgroundLabels = {
                'original-canvas': 'Original+Canvas',
                'studio-light': 'Studio Light',
                'anabelka-brand': 'Anabelka Brand'
            };

            if (backgroundProfile) {
                diagnosticBadge(
                    'Фон: '
                    + (
                        backgroundLabels[backgroundProfile]
                        || backgroundProfile
                    ),
                    normalization.background_fallback === true
                        ? 'neutral'
                        : 'success'
                );
            }

            if (
                normalization.background_fallback === true
                && requestedBackgroundProfile
            ) {
                diagnosticBadge(
                    'Fallback з '
                    + (
                        backgroundLabels[requestedBackgroundProfile]
                        || requestedBackgroundProfile
                    ),
                    'neutral'
                );
            }

            if (normalization.subject_mask_applied === true) {
                const maskMethod = valueOrEmpty(
                    normalization.mask_method
                );

                const maskLabels = {
                    'opencv-grabcut': 'GrabCut',
                    'modnet': 'MODNet'
                };

                diagnosticBadge(
                    'Маска: '
                    + (
                        maskLabels[maskMethod]
                        || maskMethod
                        || 'GrabCut'
                    ),
                    'info'
                );
            }

            const personScore = Number(
                normalization.person_score
            );

            if (Number.isFinite(personScore) && personScore > 0) {
                diagnosticBadge(
                    'Впевненість: '
                    + Math.round(personScore * 100)
                    + '%',
                    'info'
                );
            }

            const cropStrategy = valueOrEmpty(
                normalization.crop_strategy
            );

            if (cropStrategy === 'preserve-closeup') {
                diagnosticBadge('Стратегія: збереження крупного плану', 'success');
            } else if (cropStrategy === 'aspect-fill') {
                diagnosticBadge(
                    'Стратегія: 2:3 без полів',
                    'info'
                );
            } else if (cropStrategy === 'subject-bbox') {
                diagnosticBadge(
                    'Стратегія: межі моделі',
                    'info'
                );
            } else if (cropStrategy === 'torso-zoom-out') {
                diagnosticBadge(
                    'Стратегія: віддалення моделі',
                    'success'
                );

                const torsoBefore = Number(
                    normalization.torso_ratio_before
                );
                const torsoAfter = Number(
                    normalization.torso_ratio_after
                );
                const zoomScale = Number(
                    normalization.zoom_scale
                );

                if (
                    Number.isFinite(torsoBefore)
                    && torsoBefore > 0
                    && Number.isFinite(torsoAfter)
                    && torsoAfter > 0
                ) {
                    diagnosticBadge(
                        'Торс: '
                        + Math.round(torsoBefore * 100)
                        + '% → '
                        + Math.round(torsoAfter * 100)
                        + '%',
                        'info'
                    );
                }

                if (
                    Number.isFinite(zoomScale)
                    && zoomScale > 0
                ) {
                    diagnosticBadge(
                        'Масштаб фото: '
                        + Math.round(zoomScale * 100)
                        + '%',
                        'info'
                    );
                }
            } else if (cropStrategy === 'torso-normalize') {
                diagnosticBadge(
                    'Стратегія: масштаб по торсу',
                    'success'
                );

                const torsoBefore = Number(
                    normalization.torso_ratio_before
                );
                const torsoTarget = Number(
                    normalization.torso_target_ratio
                );

                if (
                    Number.isFinite(torsoBefore)
                    && torsoBefore > 0
                    && Number.isFinite(torsoTarget)
                    && torsoTarget > 0
                ) {
                    diagnosticBadge(
                        'Торс: '
                        + Math.round(torsoBefore * 100)
                        + '% → '
                        + Math.round(torsoTarget * 100)
                        + '%',
                        'info'
                    );
                }
            }
        } else {
            diagnosticBadge(
                'Діагностика недоступна для цього старого результату.',
                'neutral'
            );
        }

        const detailsButton = document.createElement('button');
        detailsButton.type = 'button';
        detailsButton.className = 'product-image-compare-details-button';
        detailsButton.textContent = 'Деталі обробки';

        const detailsModal = document.createElement('div');
        detailsModal.className = 'product-image-compare-details-modal';
        detailsModal.hidden = true;

        const detailsDialog = document.createElement('div');
        detailsDialog.className = 'product-image-compare-details-dialog';

        const detailsClose = document.createElement('button');
        detailsClose.type = 'button';
        detailsClose.className = 'product-image-compare-details-close';
        detailsClose.textContent = '×';

        detailsDialog.appendChild(detailsClose);
        detailsDialog.appendChild(meta);
        detailsDialog.appendChild(diagnostics);
        detailsModal.appendChild(detailsDialog);

        function closeDetails(syncHistory)
        {
            if (detailsModal.hidden) {
                return;
            }

            detailsModal.hidden = true;
            activeImageCompareDetails = null;

            if (
                syncHistory !== false
                && history.state
                && history.state[imageCompareDetailsHistoryKey]
            ) {
                unwindImageComparisonHistory(1);
            }
        }

        function openDetails()
        {
            if (!detailsModal.hidden || activeImageCompareModal !== modal) {
                return;
            }

            if (imageCompareHistoryUnwinding) {
                imageCompareHistorySettled.then(openDetails);
                return;
            }

            detailsModal.hidden = false;
            activeImageCompareDetails = detailsModal;

            const state = Object.assign({}, history.state || {});
            state[imageCompareHistoryKey] = true;
            state[imageCompareDetailsHistoryKey] = true;

            history.pushState(
                state,
                '',
                currentProductEditorUrl()
            );
        }

        detailsButton.addEventListener('click', openDetails);

        detailsClose.addEventListener('click', function () {
            closeDetails(true);
        });

        detailsModal.addEventListener('click', function (event) {
            if (event.target === detailsModal) {
                closeDetails(true);
            }
        });

        dialog.appendChild(header);
        dialog.appendChild(stage);
        dialog.appendChild(sliderWrap);
        dialog.appendChild(detailsButton);

        if (preview) {
            const actions = document.createElement('div');
            actions.className = 'product-image-preview-actions';

            const apply = document.createElement('button');
            apply.type = 'button';
            apply.dataset.productImagePreviewConfirm = '';
            apply.textContent = 'Застосувати';

            const cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.dataset.productImagePreviewCancel = '';
            cancel.textContent = 'Скасувати';
            cancel.addEventListener('click', function () {
                if (!preview.confirming) {
                    closeImageComparison();
                }
            });

            apply.addEventListener('click', async function () {
                if (preview.confirming || preview.dismissed || preview.committed) {
                    return;
                }

                preview.confirming = true;
                preview.setBusy(true);
                apply.disabled = true;
                cancel.disabled = true;
                apply.textContent = 'Застосування…';

                try {
                    const data = await imageProcessingRequest(
                        'confirm', imagePreviewPayload(preview)
                    );

                    if (
                        Number(data.image_id) !== preview.imageId
                        || !data.processing
                        || typeof data.processing !== 'object'
                    ) {
                        throw new Error('Сервер повернув некоректну відповідь.');
                    }

                    // The commit may finish after Back or the editor has closed.
                    image.processing = data.processing;
                    imageProcessingSelections.delete(image);
                    preview.committed = true;
                    preview.confirming = false;
                    preview.setBusy(false);
                    fields.imageList.querySelectorAll('[data-image-id]')
                        .forEach(function (card) {
                            if (Number(card.dataset.imageId) === preview.imageId) {
                                const control = card.querySelector('.product-image-processing');
                                if (control) {
                                    syncProcessingControl(control, image);
                                }
                            }
                        });

                    if (activeImagePreview === preview) {
                        activeImagePreview = null;
                        closeImageComparison({ cancelPreview: false });
                    }

                    showMessage('Обробку фотографії застосовано.');
                } catch (error) {
                    preview.confirming = false;
                    preview.setBusy(false);

                    if (preview.dismissed) {
                        cancelImagePreview(preview);
                    } else {
                        apply.disabled = false;
                        cancel.disabled = false;
                        apply.textContent = 'Застосувати';
                        showMessage(error.message || 'Не вдалося застосувати обробку.');
                    }
                }
            });

            actions.appendChild(apply);
            actions.appendChild(cancel);
            dialog.appendChild(actions);
        }

        modal.appendChild(dialog);
        modal.appendChild(detailsModal);
        document.body.appendChild(modal);

        activeImageCompareModal = modal;
        document.documentElement.classList.add(
            'product-image-compare-open'
        );
        document.body.classList.add(
            'product-image-compare-open'
        );

        const historyState = Object.assign(
            {},
            history.state && typeof history.state === 'object'
                ? history.state
                : {}
        );
        delete historyState[imageCompareDetailsHistoryKey];
        historyState[imageCompareHistoryKey] = true;
        history.pushState(
            historyState,
            '',
            currentProductEditorUrl()
        );

        close.addEventListener('click', function () {
            closeImageComparison();
        });

        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeImageComparison();
            }
        });

        close.focus();
    }


    window.addEventListener('popstate', function (event) {
        const state = event.state
            && typeof event.state === 'object'
            ? event.state
            : {};

        if (
            activeImageCompareDetails
            && !state[imageCompareDetailsHistoryKey]
        ) {
            activeImageCompareDetails.hidden = true;
            activeImageCompareDetails = null;
        }

        if (
            activeImageCompareModal
            && !state[imageCompareHistoryKey]
        ) {
            closeImageComparison({ syncHistory: false });
        }

        if (!activeImageCompareModal && state[imageCompareHistoryKey]) {
            history.replaceState(
                cleanImageComparisonState(state), '', currentProductEditorUrl()
            );
        }

        if (imageCompareHistoryUnwinding) {
            const settle = settleImageCompareHistory;
            settleImageCompareHistory = null;
            if (settle) {
                settle();
            }
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && activeImageCompareModal) {
            event.preventDefault();
            event.stopImmediatePropagation();
            closeImageComparison();
        }
    });


    function colorPickerValue(value)
    {
        const normalized = valueOrEmpty(value).trim().toLowerCase();

        return /^#[0-9a-f]{6}$/.test(normalized)
            ? normalized
            : '#b8b0bd';
    }


    function imageColorFields(colorName, colorHex, names)
    {
        const group = document.createElement('div');
        group.className = 'product-image-color-fields';

        const hex = document.createElement('input');
        hex.type = 'hidden';
        hex.name = names.hex;
        hex.value = colorPickerValue(colorHex);
        hex.dataset.imageColorHex = '';
        const name = document.createElement('input');
        name.type = 'hidden';
        name.name = names.name;
        name.value = valueOrEmpty(colorName);
        name.dataset.imageColorName = '';

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'product-image-color-open';
        button.dataset.imageColorOpen = '';

        const dot = document.createElement('span');
        dot.className = 'product-image-color-dot';
        dot.dataset.imageColorDot = '';
        dot.setAttribute('aria-hidden', 'true');

        const label = document.createElement('span');
        label.className = 'product-image-color-label';
        label.dataset.imageColorLabel = '';

        button.appendChild(dot);
        button.appendChild(label);
        group.appendChild(hex);
        group.appendChild(name);
        group.appendChild(button);

        if (name.value.trim() !== '') {
            group.classList.add('has-color');
            dot.style.setProperty('--image-color', hex.value);
            label.textContent = name.value;
            button.setAttribute(
                'aria-label',
                'Змінити колір: ' + name.value
            );
        } else {
            label.textContent = 'Вибрати колір';
            button.setAttribute('aria-label', 'Вибрати колір фотографії');
        }

        return group;
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
                validateSizeUniqueness();
                syncBySizeTotalFromRows();
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


    function normalizeSizeName(value)
    {
        return String(value || '')
            .trim()
            .toLocaleLowerCase();
    }


    function validateSizeUniqueness()
    {
        const seen = new Map();
        let duplicate = null;

        fields.sizeList
            .querySelectorAll('[data-size-name]')
            .forEach(function (input) {
                input.setCustomValidity('');

                const name = String(input.value || '').trim();
                const key = normalizeSizeName(name);

                if (key === '' || duplicate) {
                    return;
                }

                if (seen.has(key)) {
                    input.setCustomValidity(
                        'Цей розмір уже додано.'
                    );
                    duplicate = {
                        input: input,
                        name: name
                    };
                    return;
                }

                seen.set(key, input);
            });

        return duplicate;
    }


    function focusDuplicateSize(duplicate)
    {
        if (!duplicate || !duplicate.input) {
            return;
        }

        const details = duplicate.input.closest(
            'details.product-form-section'
        );

        if (details) {
            details.open = true;
        }

        showMessage(
            'Розмір «'
            + duplicate.name
            + '» додано двічі. Видаліть дублікат або вкажіть інший розмір.'
        );

        window.setTimeout(function () {
            duplicate.input.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
            duplicate.input.focus();
            duplicate.input.select();
        }, 30);
    }


    function syncBySizeTotalFromRows()
    {
        if (
            !fields.stock
            || !fields.stockMode
            || fields.stockMode.value !== 'by_size'
        ) {
            return;
        }

        if (
            form.querySelector(
                '[data-variant-stock-input]'
            )
        ) {
            return;
        }

        const seen = new Set();
        let total = 0;

        fields.sizeList
            .querySelectorAll('.product-size-row')
            .forEach(function (row) {
                const name = row.querySelector('[data-size-name]');
                const stock = row.querySelector('[data-size-stock]');
                const key = normalizeSizeName(
                    name ? name.value : ''
                );

                if (!stock || key === '' || seen.has(key)) {
                    return;
                }

                seen.add(key);
                total += Math.max(
                    0,
                    parseInt(stock.value || '0', 10) || 0
                );
            });

        fields.stock.value = String(total);
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


    function processingStatusText(processing)
    {
        processing = processing && typeof processing === 'object'
            ? processing
            : {};
        const status = String(processing.status || '');

        if (status === 'ready') {
            const width = Number(processing.master_width || 0);
            const height = Number(processing.master_height || 0);

            return width > 0 && height > 0
                ? 'Готово · ' + width + '×' + height
                : 'Готово';
        }

        if (status === 'error') {
            return 'Помилка';
        }

        if (status === 'processing') {
            return 'Обробка…';
        }

        return 'Не оброблено';
    }


    function syncProcessingControl(control, image)
    {
        const processing = image.processing
            && typeof image.processing === 'object'
            ? image.processing
            : {};
        const status = String(processing.status || '');
        const label = control.querySelector(
            '[data-product-image-processing-status]'
        );
        const button = control.querySelector(
            '[data-product-image-process]'
        );
        const compareButton = control.querySelector(
            '[data-product-image-compare]'
        );
        const profileSelect = control.querySelector(
            '[data-product-image-background-profile]'
        );
        const modeSelect = control.querySelector('[data-product-image-mask-mode]');
        const normalization = processing.normalization
            && typeof processing.normalization === 'object'
            ? processing.normalization
            : {};
        const selection = imageProcessingSelections.get(image) || {};
        const storedBackgroundProfile = valueOrEmpty(
            selection.backgroundProfile
            || normalization.background_profile_requested
            || normalization.background_profile
            || 'original-canvas'
        );
        const canCompare = status === 'ready'
            && processedMasterUrl(image) !== '';

        control.dataset.processingStatus = status || 'pending';
        control.classList.toggle(
            'has-comparison',
            canCompare
        );

        if (label) {
            label.textContent = processingStatusText(processing);
            label.title = status === 'error'
                ? valueOrEmpty(processing.last_error)
                : valueOrEmpty(processing.processed_at);
        }

        if (button) {
            button.textContent = 'Попередній перегляд';
        }

        if (
            profileSelect
            && Array.from(profileSelect.options).some(function (option) {
                return option.value === storedBackgroundProfile;
            })
        ) {
            profileSelect.value = storedBackgroundProfile;
        }

        if (modeSelect) {
            const mode = valueOrEmpty(selection.maskMode || normalization.mask_mode_requested || 'auto');
            modeSelect.value = ['auto', 'grabcut', 'modnet'].indexOf(mode) >= 0
                ? mode
                : 'auto';
            modeSelect.disabled = !profileSelect
                || profileSelect.disabled
                || profileSelect.value === 'original-canvas';
        }

        if (compareButton) {
            compareButton.hidden = !canCompare;
        }
    }


    function imageProcessingControl(image, item, remove)
    {
        const control = document.createElement('div');
        control.className = 'product-image-processing';

        const status = document.createElement('span');
        status.className = 'product-image-processing-status';
        status.dataset.productImageProcessingStatus = '';

        const button = document.createElement('button');
        button.type = 'button';
        button.setAttribute('data-product-image-process', '');
        button.setAttribute(
            'aria-label',
            'Попередній перегляд обробки фотографії товару'
        );

        const profileSelect = document.createElement('select');
        profileSelect.className = 'product-image-background-profile';
        profileSelect.dataset.productImageBackgroundProfile = '';
        profileSelect.setAttribute(
            'aria-label',
            'Профіль фону фотографії'
        );

        [
            ['original-canvas', 'Original+Canvas'],
            ['studio-light', 'Studio Light'],
            ['anabelka-brand', 'Anabelka Brand']
        ].forEach(function (entry) {
            const option = document.createElement('option');
            option.value = entry[0];
            option.textContent = entry[1];
            profileSelect.appendChild(option);
        });

        const modeSelect = document.createElement('select');
        modeSelect.className = 'product-image-mask-mode';
        modeSelect.dataset.productImageMaskMode = '';
        modeSelect.setAttribute('aria-label', 'Метод маски фотографії');
        [
            ['auto', 'Автоматично (рекомендовано)'],
            ['grabcut', 'GrabCut'],
            ['modnet', 'MODNet']
        ].forEach(function (entry) {
            const option = document.createElement('option');
            option.value = entry[0];
            option.textContent = entry[1];
            modeSelect.appendChild(option);
        });

        let busy = false;
        let busyPreview = null;

        function updateDisabled()
        {
            button.disabled = busy || remove.checked;
            profileSelect.disabled = busy || remove.checked;
            modeSelect.disabled = busy || remove.checked
                || profileSelect.value === 'original-canvas';
            compareButton.disabled = busy || remove.checked;
        }

        function rememberSelection()
        {
            // Draft choices survive status refreshes and editor card rebuilds.
            imageProcessingSelections.set(image, {
                backgroundProfile: profileSelect.value,
                maskMode: modeSelect.value
            });
        }

        profileSelect.addEventListener('change', function () {
            rememberSelection();
            updateDisabled();
        });
        modeSelect.addEventListener('change', rememberSelection);

        const compareButton = document.createElement('button');
        compareButton.type = 'button';
        compareButton.className = 'product-image-compare-button';
        compareButton.setAttribute(
            'data-product-image-compare',
            ''
        );
        compareButton.setAttribute(
            'aria-label',
            'Порівняти оригінал і оброблену фотографію'
        );
        compareButton.textContent = 'Порівняти';
        compareButton.hidden = true;
        compareButton.addEventListener('click', function () {
            openImageComparison(image);
        });

        button.addEventListener('click', async function () {
            const imageId = Number(image.id || 0);
            const csrf = form.querySelector('input[name="_csrf"]');

            if (
                busy || editor.hidden || imageId <= 0
                || !csrf || !csrf.value || remove.checked
                || (activeImagePreview && activeImagePreview.confirming)
            ) {
                return;
            }

            closeImageComparison();
            cancelImagePreview(activeImagePreview);
            imagePreviewGeneration += 1;
            const preview = {
                imageId: imageId,
                csrf: csrf.value,
                generation: imagePreviewGeneration,
                editorToken: productEditorHistoryToken,
                previewId: '',
                dismissed: false,
                confirming: false,
                committed: false,
                cancelled: false,
                setBusy: function (value) {
                    if (busyPreview && busyPreview !== preview) {
                        return;
                    }
                    busyPreview = value ? preview : null;
                    busy = value;
                    syncProcessingControl(control, image);
                    updateDisabled();
                    if (value) {
                        status.textContent = 'Обробка…';
                        control.dataset.processingStatus = 'processing';
                    }
                }
            };
            const payload = new FormData();
            rememberSelection();
            payload.append('_csrf', csrf.value);
            payload.append('image_id', String(imageId));
            payload.append('background_profile', profileSelect.value || 'original-canvas');
            payload.append('mask_mode', modeSelect.value || 'auto');

            activeImagePreview = preview;
            preview.setBusy(true);

            try {
                const data = await imageProcessingRequest('preview', payload);
                preview.previewId = valueOrEmpty(data.preview_id);

                if (
                    Number(data.image_id) !== imageId
                    || !preview.previewId
                    || !data.processing
                    || typeof data.processing !== 'object'
                    || !valueOrEmpty(data.preview_url).startsWith(
                        '/Anabelka/admin/products/image-process-preview-file?'
                    )
                ) {
                    throw new Error('Сервер повернув некоректну відповідь.');
                }

                preview.processing = data.processing;
                preview.previewUrl = data.preview_url;
                await imageCompareHistorySettled;

                if (
                    preview.dismissed || editor.hidden || !item.isConnected
                    || remove.checked || activeImagePreview !== preview
                    || preview.generation !== imagePreviewGeneration
                    || preview.editorToken !== productEditorHistoryToken
                ) {
                    cancelImagePreview(preview);
                    return;
                }

                preview.setBusy(false);
                openImageComparison(image, preview);
            } catch (error) {
                if (!preview.dismissed && activeImagePreview === preview) {
                    showMessage(error.message || 'Не вдалося отримати попередній перегляд.');
                }
                cancelImagePreview(preview);
            } finally {
                if (!preview.confirming) {
                    preview.setBusy(false);
                }
            }
        });

        control.appendChild(status);
        control.appendChild(profileSelect);
        control.appendChild(modeSelect);
        control.appendChild(button);
        control.appendChild(compareButton);
        syncProcessingControl(control, image);

        remove.addEventListener('change', function () {
            updateDisabled();
            if (remove.checked && activeImagePreview
                && activeImagePreview.imageId === Number(image.id)) {
                closeImageComparison();
                cancelImagePreview(activeImagePreview);
            }
        });

        return control;
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
        item.appendChild(imageColorFields(
            image.color_name,
            image.color_hex,
            {
                hex: 'image_color_hex[' + String(image.id || '') + ']',
                name: 'image_color_name[' + String(image.id || '') + ']'
            }
        ));
        item.appendChild(
            imageProcessingControl(image, item, remove)
        );
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
            const item = document.createElement('div');
            item.className = 'product-upload-preview-item';
            image.src = url;
            image.alt = '';
            uploadPreviewUrls.push(url);
            item.appendChild(image);
            item.appendChild(imageColorFields('', '#b8b0bd', {
                hex: 'new_image_color_hex[]',
                name: 'new_image_color_name[]'
            }));
            fields.uploadPreview.appendChild(item);
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
            totalField.hidden = false;

            const totalLabel = totalField.querySelector('span');

            if (totalLabel) {
                totalLabel.textContent = bySize
                    ? 'Загальний залишок, шт. (автоматично)'
                    : 'Загальний залишок, шт.';
            }
        }

        if (fields.stock) {
            fields.stock.required = !bySize;
            fields.stock.readOnly = bySize;

            if (bySize) {
                fields.stock.setAttribute('aria-readonly', 'true');
            } else {
                fields.stock.removeAttribute('aria-readonly');
            }
        }

        form.querySelectorAll('[data-size-stock]').forEach(function (stock) {
            stock.readOnly = !bySize;
        });

        if (hint) {
            hint.textContent = bySize
                ? 'Залишки задаються за розмірами. Загальний залишок рахується автоматично.'
                : 'Для загального залишку кількість задається вище.';
        }

        if (bySize) {
            syncBySizeTotalFromRows();
        }
    }


    window.addEventListener('popstate', function (event) {
        const state = event.state
            && typeof event.state === 'object'
            ? event.state
            : {};
        const stateToken = Number(
            state[productEditorHistoryKey] || 0
        );

        /*
         * Forward navigation can land on the editor-owned entry again.
         * In that case keep the editor state armed. Back from the editor
         * lands on the clean base entry and closes it.
         */
        if (
            !editor.hidden
            && stateToken === productEditorHistoryToken
            && stateToken > 0
        ) {
            productEditorHistoryArmed = true;
            return;
        }

        if (!editor.hidden) {
            productEditorHistoryArmed = false;
            closeEditor({ syncHistory: false });
            return;
        }

        productEditorHistoryArmed = Boolean(
            stateToken === productEditorHistoryToken
            && stateToken > 0
        );
    });


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
        fields.material.value = isEdit ? valueOrEmpty(product.material) : '';
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
        armProductEditorHistory();

        if (
            window.AnabelkaAdminBack
            && typeof window.AnabelkaAdminBack.syncNow === 'function'
        ) {
            window.AnabelkaAdminBack.syncNow();
        }

        if (window.AnabelkaAIEditingContext === 'product-translations') {
            window.AnabelkaAIEditingContext = '';
        }

        document.dispatchEvent(new CustomEvent(
            'anabelka:ai-context-change'
        ));
        window.setTimeout(function () {
            focusEditor(isEdit ? product.id : 0);
        }, 30);
    }


    function closeEditor(options)
    {
        const settings = options && typeof options === 'object'
            ? options
            : {};
        const syncHistory = settings.syncHistory !== false;

        cancelImagePreview(activeImagePreview);
        closeImageComparison({ syncHistory: false });
        imagePreviewGeneration += 1;
        editor.hidden = true;
        document.body.classList.remove('product-editor-open');

        if (syncHistory) {
            disarmProductEditorHistory();
        }

        if (
            window.AnabelkaAdminBack
            && typeof window.AnabelkaAdminBack.syncNow === 'function'
        ) {
            window.AnabelkaAdminBack.syncNow();
        }

        if (window.AnabelkaAIEditingContext === 'product-translations') {
            window.AnabelkaAIEditingContext = '';
        }

        document.dispatchEvent(new CustomEvent(
            'anabelka:ai-context-change'
        ));
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
        const colorPicker = document.getElementById('product-color-picker');

        if (
            event.key === 'Escape'
            && colorPicker
            && !colorPicker.hidden
        ) {
            return;
        }

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

    fields.sizeList.addEventListener('input', function (event) {
        if (event.target.matches('[data-size-name]')) {
            validateSizeUniqueness();
            syncBySizeTotalFromRows();
            return;
        }

        if (event.target.matches('[data-size-stock]')) {
            syncBySizeTotalFromRows();
        }
    });

    fields.imageInput.addEventListener('change', renderUploadPreviews);

    const translationDetails = form.querySelector(
        '[data-translation-details]'
    );

    if (translationDetails) {
        if (
            window.AnabelkaAdminBack
            && typeof window.AnabelkaAdminBack.register === 'function'
        ) {
            window.AnabelkaAdminBack.register({
                key: 'product-translations',
                priority: 60,
                isActive: function () {
                    return !editor.hidden && translationDetails.open;
                },
                close: function () {
                    translationDetails.open = false;

                    if (
                        window.AnabelkaAdminBack
                        && typeof window.AnabelkaAdminBack.syncNow
                            === 'function'
                    ) {
                        window.AnabelkaAdminBack.syncNow();
                    }
                }
            });
        }

        translationDetails.addEventListener('toggle', function () {
            if (
                window.AnabelkaAdminBack
                && typeof window.AnabelkaAdminBack.syncNow === 'function'
            ) {
                window.AnabelkaAdminBack.syncNow();
            }

            window.AnabelkaAIEditingContext = translationDetails.open
                ? 'product-translations'
                : '';

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
        });
    }

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

        const duplicateSize = validateSizeUniqueness();

        if (duplicateSize) {
            focusDuplicateSize(duplicateSize);
            return;
        }

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
