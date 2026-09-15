(function () {
    'use strict';

    const form = document.getElementById('product-editor-form');
    const editor = document.getElementById('product-editor');
    const sizeList = document.getElementById('product-size-list');
    const imageList = document.getElementById('product-image-list');
    const uploadPreview = document.getElementById('product-upload-preview');
    const productIdField = document.getElementById('product-edit-id');
    const stockModeField = document.getElementById('product-edit-stock-mode');

    if (!form || !editor || !sizeList || !imageList || !productIdField) {
        return;
    }

    const section = sizeList.closest('details.product-form-section');

    if (!section) {
        return;
    }

    const oldBlock = section.querySelector('.product-variant-stock-block');

    if (oldBlock) {
        oldBlock.remove();
    }

    const block = document.createElement('section');
    block.className = 'product-variant-stock-block';
    block.innerHTML = [
        '<div class="product-variant-stock-head">',
        '  <div>',
        '    <strong>Залишки за розміром і кольором</strong>',
        '    <span>Редагуйте кількість тільки тут. Підсумки за розмірами рахуються автоматично.</span>',
        '  </div>',
        '  <strong data-variant-total>0 шт.</strong>',
        '</div>',
        '<div class="product-variant-stock-note" data-variant-note></div>',
        '<div class="product-variant-stock-cards" data-variant-cards></div>'
    ].join('');

    sizeList.insertAdjacentElement('afterend', block);

    const cardsWrap = block.querySelector('[data-variant-cards]');
    const note = block.querySelector('[data-variant-note]');
    const totalLabel = block.querySelector('[data-variant-total]');
    const sizeHint = form.querySelector('[data-size-stock-hint]');
    const cache = new Map();

    let loadedProductId = 0;
    let loadedRows = [];
    let hasStoredMatrix = false;
    let matrixTouched = false;
    let lastDimensionSignature = '';
    let isLoadingProduct = false;

    const style = document.createElement('style');
    style.textContent = [
        '.product-variant-stock-block{min-width:0;max-width:100%;margin-top:16px;padding-top:15px;border-top:1px solid #e2d8e8}',
        '.product-variant-stock-head{min-width:0;display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:10px}',
        '.product-variant-stock-head>div{min-width:0;display:grid;gap:3px}',
        '.product-variant-stock-head>div>strong{font-size:15px}',
        '.product-variant-stock-head>strong{flex:0 0 auto;padding:6px 9px;border-radius:999px;background:#f4eaff;color:#6519b9;font-size:12px}',
        '.product-variant-stock-head span,.product-variant-stock-note{color:#77707c;font-size:11px;line-height:1.4}',
        '.product-variant-stock-note{padding:10px;border-radius:10px;background:#faf7fc}',
        '.product-variant-stock-cards{display:grid;gap:10px;margin-top:10px}',
        '.product-variant-stock-card{min-width:0;padding:11px;border:1px solid #e2d8e8;border-radius:14px;background:#faf7ff}',
        '.product-variant-stock-card-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px}',
        '.product-variant-stock-card-size{color:#33263a;font-size:15px;font-weight:900}',
        '.product-variant-stock-card-total{flex:0 0 auto;padding:5px 8px;border-radius:999px;background:#fff;color:#6519b9;font-size:12px;font-weight:900}',
        '.product-variant-stock-color-list{display:grid;gap:7px}',
        '.product-variant-stock-color-row{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:10px;padding:8px;border-radius:11px;background:#fff}',
        '.product-variant-stock-color-meta{min-width:0;display:flex;align-items:center;gap:8px;font-size:13px;font-weight:800;color:#433848}',
        '.product-variant-stock-dot{flex:0 0 auto;width:22px;height:22px;border:1px solid rgba(50,43,55,.28);border-radius:50%;background:var(--variant-color,#b8b0bd);box-shadow:inset 0 0 0 1px rgba(255,255,255,.35)}',
        '.product-variant-stock-color-name{min-width:0;overflow-wrap:anywhere}',
        '.product-variant-stock-stepper{display:grid;grid-template-columns:44px minmax(68px,86px) 44px;align-items:center;gap:6px}',
        '.product-variant-stock-stepper button{width:44px;height:44px;padding:0;border:1px solid #d8c8e8;border-radius:11px;background:#f4eaff;color:#6519b9;font:inherit;font-size:22px;font-weight:900;line-height:1;cursor:pointer}',
        '.product-variant-stock-stepper button:active{transform:translateY(1px)}',
        '.product-variant-stock-stepper button:focus-visible,.product-variant-stock-input:focus{outline:3px solid rgba(138,43,226,.16);outline-offset:1px;border-color:#8A2BE2}',
        '.product-variant-stock-input{box-sizing:border-box;width:100%;height:44px;min-width:0;padding:7px 8px;border:1px solid #d8cedf;border-radius:11px;background:#fff;color:#33263a;text-align:center;font:inherit;font-weight:800}',
        '.product-size-row.is-variant-summary{grid-template-columns:minmax(0,1fr) auto 37px}',
        '.product-size-row.is-variant-summary [data-variant-stock-hidden="1"]{display:none!important}',
        '.product-variant-size-summary{align-self:end;display:flex;align-items:center;justify-content:flex-end;min-height:38px;padding:0 3px 8px;color:#6519b9;font-size:12px;font-weight:900;line-height:1.15;white-space:nowrap}',
        '@media(max-width:650px){.product-variant-stock-head{flex-direction:column}.product-variant-stock-color-row{grid-template-columns:1fr}.product-variant-stock-stepper{width:100%;grid-template-columns:46px minmax(0,1fr) 46px}.product-variant-stock-stepper button{width:46px;height:46px}.product-variant-stock-input{height:46px}.product-size-row.is-variant-summary{grid-template-columns:minmax(0,1fr) auto 34px}}'
    ].join('\n');
    document.head.appendChild(style);

    function textKey(value)
    {
        return String(value || '').trim().toLocaleLowerCase();
    }

    function colorKey(name, hex)
    {
        return textKey(name) + '|' + String(hex || '').trim().toLowerCase();
    }

    function normalizeStockValue(value)
    {
        return String(value || '').replace(/[^0-9]/g, '');
    }

    function stockNumber(value)
    {
        const digits = normalizeStockValue(value);

        if (digits === '') {
            return 0;
        }

        return Math.max(0, parseInt(digits, 10) || 0);
    }

    function currentSizes()
    {
        return Array.from(sizeList.querySelectorAll('.product-size-row'))
            .map(function (row) {
                const input = row.querySelector('[data-size-name]');
                return input ? String(input.value || '').trim() : '';
            })
            .filter(Boolean)
            .filter(function (name, index, all) {
                return all.findIndex(function (item) {
                    return textKey(item) === textKey(name);
                }) === index;
            });
    }

    function currentColors()
    {
        const roots = [imageList, uploadPreview].filter(Boolean);
        const result = [];
        const seenNames = new Set();

        roots.forEach(function (root) {
            root.querySelectorAll('.product-image-color-fields').forEach(function (group) {
                const nameInput = group.querySelector('[data-image-color-name]');
                const hexInput = group.querySelector('[data-image-color-hex]');
                const name = nameInput ? String(nameInput.value || '').trim() : '';
                const hex = hexInput ? String(hexInput.value || '').trim().toLowerCase() : '';
                const key = textKey(name);

                if (!name || seenNames.has(key)) {
                    return;
                }

                seenNames.add(key);
                result.push({ name: name, hex: hex, key: key });
            });
        });

        return result;
    }

    function cacheCurrentInputs()
    {
        cardsWrap.querySelectorAll('[data-variant-stock-input]').forEach(function (input) {
            cache.set(
                String(input.dataset.variantKey || ''),
                stockNumber(input.value)
            );
        });
    }

    function seedLoadedRows()
    {
        const loadedTotals = new Map();

        loadedRows.forEach(function (row) {
            const key = textKey(row.size_name)
                + '||'
                + textKey(row.color_name);
            const stock = Math.max(0, Number(row.stock || 0));

            loadedTotals.set(key, (loadedTotals.get(key) || 0) + stock);
        });

        loadedTotals.forEach(function (stock, key) {
            if (!cache.has(key)) {
                cache.set(key, stock);
            }
        });
    }

    function loadedStockFallback(sizeName, color)
    {
        const size = textKey(sizeName);
        const name = textKey(color.name);
        const hex = String(color.hex || '').trim().toLowerCase();
        let nameMatch = 0;
        let hasNameMatch = false;
        let hexMatch = null;

        loadedRows.forEach(function (row) {
            if (textKey(row.size_name) !== size) {
                return;
            }

            const rowName = textKey(row.color_name);
            const rowHex = String(row.color_hex || '').trim().toLowerCase();
            const stock = Math.max(0, Number(row.stock || 0));

            if (rowName === name) {
                nameMatch += stock;
                hasNameMatch = true;
            }

            if (hexMatch === null && hex !== '' && rowHex === hex) {
                hexMatch = stock;
            }
        });

        if (hasNameMatch) {
            return nameMatch;
        }

        if (hexMatch !== null) {
            return hexMatch;
        }

        return 0;
    }

    function matrixIsActive()
    {
        return hasStoredMatrix || matrixTouched;
    }

    function restoreLegacySizeFields()
    {
        sizeList.querySelectorAll('.product-size-row').forEach(function (row) {
            const stock = row.querySelector('[data-size-stock]');
            const label = stock ? stock.closest('label') : null;
            const labelText = label ? label.querySelector('span') : null;
            const summary = row.querySelector('[data-variant-size-summary]');

            if (row.classList.contains('is-variant-summary')) {
                row.classList.remove('is-variant-summary');
            }

            if (stock && stock.dataset.variantSummary === '1') {
                delete stock.dataset.variantSummary;
                stock.readOnly = stockModeField
                    ? stockModeField.value !== 'by_size'
                    : false;
                stock.removeAttribute('aria-readonly');
            }

            if (label && label.dataset.variantOriginalHidden !== undefined) {
                label.hidden = label.dataset.variantOriginalHidden === '1';
                label.removeAttribute('data-variant-stock-hidden');

                const originalDisplay = label.dataset.variantOriginalDisplay || '';
                const originalPriority = label.dataset.variantOriginalDisplayPriority || '';

                if (originalDisplay === '') {
                    label.style.removeProperty('display');
                } else {
                    label.style.setProperty('display', originalDisplay, originalPriority);
                }

                delete label.dataset.variantOriginalHidden;
                delete label.dataset.variantOriginalDisplay;
                delete label.dataset.variantOriginalDisplayPriority;
            }

            if (summary) {
                summary.remove();
            }

            if (
                labelText
                && labelText.dataset.variantOriginalText
                && labelText.textContent !== labelText.dataset.variantOriginalText
            ) {
                labelText.textContent = labelText.dataset.variantOriginalText;
            }
        });

        if (sizeHint && sizeHint.dataset.variantMatrixHint === '1') {
            const restoredHint = stockModeField && stockModeField.value === 'by_size'
                ? 'Вкажіть окрему кількість для кожного розміру.'
                : 'Для загального залишку кількість задається вище.';

            if (sizeHint.textContent !== restoredHint) {
                sizeHint.textContent = restoredHint;
            }

            delete sizeHint.dataset.variantMatrixHint;
        }
    }

    function syncLegacySizeTotals(sizeTotals)
    {
        if (!matrixIsActive()) {
            restoreLegacySizeFields();
            return;
        }

        sizeList.querySelectorAll('.product-size-row').forEach(function (row) {
            const name = row.querySelector('[data-size-name]');
            const stock = row.querySelector('[data-size-stock]');
            const label = stock ? stock.closest('label') : null;
            const labelText = label ? label.querySelector('span') : null;
            const key = textKey(name ? name.value : '');

            if (!stock || !key || !sizeTotals.has(key)) {
                return;
            }

            if (labelText && !labelText.dataset.variantOriginalText) {
                labelText.dataset.variantOriginalText = labelText.textContent || 'Залишок';
            }

            const nextValue = String(sizeTotals.get(key));
            let summary = row.querySelector('[data-variant-size-summary]');

            if (stock.value !== nextValue) {
                stock.value = nextValue;
            }
            if (!stock.readOnly) {
                stock.readOnly = true;
            }
            if (stock.dataset.variantSummary !== '1') {
                stock.dataset.variantSummary = '1';
            }
            if (stock.getAttribute('aria-readonly') !== 'true') {
                stock.setAttribute('aria-readonly', 'true');
            }
            if (!row.classList.contains('is-variant-summary')) {
                row.classList.add('is-variant-summary');
            }

            if (label) {
                if (label.dataset.variantOriginalHidden === undefined) {
                    label.dataset.variantOriginalHidden = label.hidden ? '1' : '0';
                    label.dataset.variantOriginalDisplay = label.style.getPropertyValue('display') || '';
                    label.dataset.variantOriginalDisplayPriority = label.style.getPropertyPriority('display') || '';
                }

                label.hidden = true;
                label.setAttribute('data-variant-stock-hidden', '1');
                label.style.setProperty('display', 'none', 'important');
            }

            if (!summary) {
                summary = document.createElement('span');
                summary.className = 'product-variant-size-summary';
                summary.dataset.variantSizeSummary = '';
                const removeButton = row.querySelector('[data-size-remove]');
                row.insertBefore(summary, removeButton || null);
            }

            const summaryText = 'Усього: ' + nextValue + ' шт.';

            if (summary.textContent !== summaryText) {
                summary.textContent = summaryText;
            }
        });

        if (sizeHint) {
            const matrixHint = 'Кількість редагується тільки в матриці нижче. Підсумок за розміром рахується автоматично.';

            if (sizeHint.textContent !== matrixHint) {
                sizeHint.textContent = matrixHint;
            }
            if (sizeHint.dataset.variantMatrixHint !== '1') {
                sizeHint.dataset.variantMatrixHint = '1';
            }
        }
    }

    function updateTotals()
    {
        const sizeTotals = new Map();
        let total = 0;

        cardsWrap.querySelectorAll('[data-variant-stock-input]').forEach(function (input) {
            const stock = stockNumber(input.value);
            const sizeKey = textKey(input.dataset.sizeName || '');

            cache.set(String(input.dataset.variantKey || ''), stock);
            total += stock;
            sizeTotals.set(sizeKey, (sizeTotals.get(sizeKey) || 0) + stock);
        });

        cardsWrap.querySelectorAll('[data-variant-size-card]').forEach(function (card) {
            const sizeKey = String(card.dataset.variantSizeKey || '');
            const totalNode = card.querySelector('[data-variant-size-total]');
            const nextText = String(sizeTotals.get(sizeKey) || 0) + ' шт.';

            if (totalNode && totalNode.textContent !== nextText) {
                totalNode.textContent = nextText;
            }
        });

        const grandTotalText = total + ' шт.';

        if (totalLabel.textContent !== grandTotalText) {
            totalLabel.textContent = grandTotalText;
        }

        syncLegacySizeTotals(sizeTotals);
    }

    function adjustStock(input, delta)
    {
        const current = stockNumber(input.value);
        const next = Math.max(0, current + delta);

        input.value = String(next);
        matrixTouched = true;
        updateTotals();
    }

    function buildColorRow(sizeName, color)
    {
        const row = document.createElement('div');
        const meta = document.createElement('div');
        const dot = document.createElement('span');
        const name = document.createElement('span');
        const stepper = document.createElement('div');
        const decrease = document.createElement('button');
        const input = document.createElement('input');
        const increase = document.createElement('button');
        const key = textKey(sizeName) + '||' + color.key;
        const stock = cache.has(key)
            ? Math.max(0, Number(cache.get(key) || 0))
            : loadedStockFallback(sizeName, color);

        cache.set(key, stock);

        row.className = 'product-variant-stock-color-row';
        meta.className = 'product-variant-stock-color-meta';
        dot.className = 'product-variant-stock-dot';
        dot.style.setProperty('--variant-color', color.hex || '#b8b0bd');
        name.className = 'product-variant-stock-color-name';
        name.textContent = color.name;
        meta.appendChild(dot);
        meta.appendChild(name);

        stepper.className = 'product-variant-stock-stepper';
        decrease.type = 'button';
        decrease.setAttribute('data-variant-decrease', '');
        decrease.textContent = '−';
        decrease.setAttribute(
            'aria-label',
            'Зменшити залишок: ' + sizeName + ', ' + color.name
        );

        input.type = 'text';
        input.inputMode = 'numeric';
        input.pattern = '[0-9]*';
        input.autocomplete = 'off';
        input.enterKeyHint = 'next';
        input.className = 'product-variant-stock-input';
        input.dataset.variantStockInput = '';
        input.dataset.variantKey = key;
        input.dataset.sizeName = sizeName;
        input.dataset.colorName = color.name;
        input.dataset.colorHex = color.hex;
        input.value = String(stock);
        input.setAttribute(
            'aria-label',
            'Залишок: ' + sizeName + ', ' + color.name
        );

        increase.type = 'button';
        increase.setAttribute('data-variant-increase', '');
        increase.textContent = '+';
        increase.setAttribute(
            'aria-label',
            'Збільшити залишок: ' + sizeName + ', ' + color.name
        );

        decrease.addEventListener('click', function () {
            adjustStock(input, -1);
        });

        increase.addEventListener('click', function () {
            adjustStock(input, 1);
        });

        input.addEventListener('focus', function () {
            input.select();
        });

        input.addEventListener('input', function () {
            const normalized = normalizeStockValue(input.value);

            if (normalized !== input.value) {
                input.value = normalized;
            }

            matrixTouched = true;
            updateTotals();
        });

        input.addEventListener('blur', function () {
            const normalized = normalizeStockValue(input.value);
            input.value = normalized === ''
                ? '0'
                : String(stockNumber(normalized));
            updateTotals();
        });

        stepper.appendChild(decrease);
        stepper.appendChild(input);
        stepper.appendChild(increase);
        row.appendChild(meta);
        row.appendChild(stepper);

        return row;
    }

    function renderMatrix(options)
    {
        const preferLoadedRows = Boolean(options && options.preferLoadedRows);

        if (!preferLoadedRows) {
            cacheCurrentInputs();
        }

        seedLoadedRows();

        const sizes = currentSizes();
        const colors = currentColors();

        if (sizes.length === 0) {
            note.textContent = 'Спочатку додайте хоча б один розмір.';
            cardsWrap.innerHTML = '';
            totalLabel.textContent = '0 шт.';
            restoreLegacySizeFields();
            return;
        }

        if (colors.length === 0) {
            note.textContent = 'Призначте колір хоча б одній фотографії товару.';
            cardsWrap.innerHTML = '';
            totalLabel.textContent = '0 шт.';
            restoreLegacySizeFields();
            return;
        }

        note.textContent = hasStoredMatrix
            ? 'Кожна комбінація «розмір + колір» має власний залишок.'
            : 'Старі залишки ще не розподілені за кольорами. Введіть кількість у картках, щоб перейти на облік «розмір + колір».';

        const fragment = document.createDocumentFragment();

        sizes.forEach(function (sizeName) {
            const card = document.createElement('section');
            const head = document.createElement('div');
            const title = document.createElement('strong');
            const sizeTotal = document.createElement('strong');
            const colorList = document.createElement('div');

            card.className = 'product-variant-stock-card';
            card.dataset.variantSizeCard = '';
            card.dataset.variantSizeKey = textKey(sizeName);
            head.className = 'product-variant-stock-card-head';
            title.className = 'product-variant-stock-card-size';
            title.textContent = sizeName;
            sizeTotal.className = 'product-variant-stock-card-total';
            sizeTotal.dataset.variantSizeTotal = '';
            sizeTotal.textContent = '0 шт.';
            colorList.className = 'product-variant-stock-color-list';

            colors.forEach(function (color) {
                colorList.appendChild(buildColorRow(sizeName, color));
            });

            head.appendChild(title);
            head.appendChild(sizeTotal);
            card.appendChild(head);
            card.appendChild(colorList);
            fragment.appendChild(card);
        });

        cardsWrap.replaceChildren(fragment);
        updateTotals();
    }

    function dimensionSignature()
    {
        return JSON.stringify({
            productId: Number(productIdField.value || 0),
            sizes: currentSizes().map(textKey),
            colors: currentColors().map(function (color) {
                return colorKey(color.name, color.hex);
            })
        });
    }

    function rebuildIfDimensionsChanged(force, options)
    {
        if (isLoadingProduct && !force) {
            return false;
        }

        const nextSignature = dimensionSignature();

        if (!force && nextSignature === lastDimensionSignature) {
            return false;
        }

        const preferLoadedRows = Boolean(options && options.preferLoadedRows);

        if (!preferLoadedRows) {
            cacheCurrentInputs();
        } else {
            cache.clear();
        }

        lastDimensionSignature = nextSignature;
        renderMatrix({ preferLoadedRows: preferLoadedRows });
        return true;
    }

    function matrixRows()
    {
        return Array.from(
            cardsWrap.querySelectorAll('[data-variant-stock-input]')
        ).map(function (input) {
            return {
                size_name: String(input.dataset.sizeName || ''),
                color_name: String(input.dataset.colorName || ''),
                color_hex: String(input.dataset.colorHex || ''),
                stock: stockNumber(input.value)
            };
        });
    }

    async function loadForProduct(productId)
    {
        productId = Number(productId || 0);
        isLoadingProduct = true;
        loadedProductId = productId;
        loadedRows = [];
        hasStoredMatrix = false;
        matrixTouched = false;
        cache.clear();

        if (productId > 0) {
            try {
                const response = await fetch(
                    '/Anabelka/admin/products/variant-stock?product_id='
                    + encodeURIComponent(productId),
                    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
                );
                const data = await response.json();

                if (response.ok && data.success && Array.isArray(data.rows)) {
                    loadedRows = data.rows;
                    hasStoredMatrix = loadedRows.length > 0;
                }
            } catch (error) {
                loadedRows = [];
                hasStoredMatrix = false;
            }
        }

        isLoadingProduct = false;
        lastDimensionSignature = '';
        rebuildIfDimensionsChanged(true, { preferLoadedRows: true });
    }

    document.addEventListener('click', function (event) {
        const edit = event.target.closest('[data-product-edit]');
        const create = event.target.closest('[data-product-create]');

        if (edit) {
            isLoadingProduct = true;
            window.setTimeout(function () {
                loadForProduct(edit.dataset.productId || 0);
            }, 80);
        }

        if (create) {
            isLoadingProduct = true;
            window.setTimeout(function () {
                loadForProduct(0);
            }, 80);
        }

        if (event.target.closest('[data-color-picker-apply], [data-color-picker-clear]')) {
            window.setTimeout(function () {
                rebuildIfDimensionsChanged(false);
            }, 30);
        }
    });

    form.addEventListener('input', function (event) {
        if (event.target.matches('[data-size-name]')) {
            window.setTimeout(function () {
                rebuildIfDimensionsChanged(false);
            }, 0);
        }
    });

    form.addEventListener('change', function (event) {
        if (
            event.target.matches('#product-image-input')
            || event.target.matches('#product-edit-stock-mode')
        ) {
            window.setTimeout(function () {
                rebuildIfDimensionsChanged(false);
            }, 50);
        }
    });

    const sizeObserver = new MutationObserver(function () {
        rebuildIfDimensionsChanged(false);
    });
    sizeObserver.observe(sizeList, { childList: true });

    const sourceObserver = new MutationObserver(function () {
        rebuildIfDimensionsChanged(false);
    });
    sourceObserver.observe(imageList, { childList: true, subtree: true });

    if (uploadPreview) {
        sourceObserver.observe(uploadPreview, { childList: true, subtree: true });
    }

    const nativeFetch = window.fetch.bind(window);

    window.fetch = async function (input, init) {
        const response = await nativeFetch(input, init);
        const url = typeof input === 'string'
            ? input
            : String(input && input.url ? input.url : '');
        const method = String((init && init.method) || 'GET').toUpperCase();

        if (
            method === 'POST'
            && url.indexOf('/Anabelka/admin/products/save') !== -1
            && response.ok
        ) {
            try {
                const data = await response.clone().json();
                const productId = Number(
                    data.product_id || productIdField.value || 0
                );
                const csrf = form.querySelector('input[name="csrf_token"]');
                const rows = matrixRows();
                const shouldSaveMatrix = hasStoredMatrix || matrixTouched;

                if (
                    data.success
                    && productId > 0
                    && csrf
                    && rows.length > 0
                    && shouldSaveMatrix
                ) {
                    const payload = new FormData();
                    payload.append('csrf_token', csrf.value);
                    payload.append('product_id', String(productId));
                    payload.append('variant_stock_json', JSON.stringify(rows));

                    const variantResponse = await nativeFetch(
                        '/Anabelka/admin/products/variant-stock/save',
                        {
                            method: 'POST',
                            body: payload,
                            headers: { 'X-Requested-With': 'XMLHttpRequest' }
                        }
                    );

                    if (!variantResponse.ok) {
                        const variantData = await variantResponse.json().catch(function () {
                            return {};
                        });
                        throw new Error(
                            variantData.message
                            || 'Не вдалося зберегти залишки за кольорами.'
                        );
                    }

                    loadedProductId = productId;
                    loadedRows = rows.map(function (row) {
                        return {
                            size_name: row.size_name,
                            color_name: row.color_name,
                            color_hex: row.color_hex,
                            stock: row.stock
                        };
                    });
                    hasStoredMatrix = true;
                    matrixTouched = false;
                    updateTotals();
                }
            } catch (error) {
                if (window.AnabelkaNotify) {
                    window.AnabelkaNotify.error(
                        error.message
                        || 'Не вдалося зберегти залишки за кольорами.'
                    );
                } else {
                    const message = document.getElementById('site-message');

                    if (message) {
                        message.textContent = error.message
                            || 'Не вдалося зберегти залишки за кольорами.';
                        message.classList.add('show');
                    }
                }
            }
        }

        return response;
    };

    window.setTimeout(function () {
        if (!editor.hidden) {
            loadForProduct(productIdField.value || 0);
        } else {
            lastDimensionSignature = '';
            rebuildIfDimensionsChanged(true, { preferLoadedRows: true });
        }
    }, 350);
}());
