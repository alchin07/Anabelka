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
        '.product-size-row.is-variant-summary [data-size-stock]{background:#f8f4fb;color:#6519b9;font-weight:800}',
        '@media(max-width:650px){.product-variant-stock-head{flex-direction:column}.product-variant-stock-color-row{grid-template-columns:1fr}.product-variant-stock-stepper{width:100%;grid-template-columns:46px minmax(0,1fr) 46px}.product-variant-stock-stepper button{width:46px;height:46px}.product-variant-stock-input{height:46px}}'
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
        const seen = new Set();

        roots.forEach(function (root) {
            root.querySelectorAll('.product-image-color-fields').forEach(function (group) {
                const nameInput = group.querySelector('[data-image-color-name]');
                const hexInput = group.querySelector('[data-image-color-hex]');
                const name = nameInput ? String(nameInput.value || '').trim() : '';
                const hex = hexInput ? String(hexInput.value || '').trim().toLowerCase() : '';

                if (!name) {
                    return;
                }

                const key = colorKey(name, hex);

                if (seen.has(key)) {
                    return;
                }

                seen.add(key);
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
                Math.max(0, Math.floor(Number(input.value || 0)))
            );
        });
    }

    function seedLoadedRows()
    {
        loadedRows.forEach(function (row) {
            const key = textKey(row.size_name)
                + '||'
                + colorKey(row.color_name, row.color_hex);

            if (!cache.has(key)) {
                cache.set(key, Math.max(0, Number(row.stock || 0)));
            }
        });
    }

    function loadedStockFallback(sizeName, color)
    {
        const size = textKey(sizeName);
        const name = textKey(color.name);
        const hex = String(color.hex || '').trim().toLowerCase();
        let nameMatch = null;
        let hexMatch = null;

        loadedRows.forEach(function (row) {
            if (textKey(row.size_name) !== size) {
                return;
            }

            const rowName = textKey(row.color_name);
            const rowHex = String(row.color_hex || '').trim().toLowerCase();
            const stock = Math.max(0, Number(row.stock || 0));

            if (nameMatch === null && rowName === name) {
                nameMatch = stock;
            }

            if (hexMatch === null && hex !== '' && rowHex === hex) {
                hexMatch = stock;
            }
        });

        if (nameMatch !== null) {
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

            row.classList.remove('is-variant-summary');

            if (stock && stock.dataset.variantSummary === '1') {
                delete stock.dataset.variantSummary;
                stock.readOnly = stockModeField
                    ? stockModeField.value !== 'by_size'
                    : false;
                stock.removeAttribute('aria-readonly');
            }

            if (labelText && labelText.dataset.variantOriginalText) {
                labelText.textContent = labelText.dataset.variantOriginalText;
            }
        });

        if (sizeHint && sizeHint.dataset.variantMatrixHint === '1') {
            sizeHint.textContent = stockModeField && stockModeField.value === 'by_size'
                ? 'Вкажіть окрему кількість для кожного розміру.'
                : 'Для загального залишку кількість задається вище.';
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

            stock.value = String(sizeTotals.get(key));
            stock.readOnly = true;
            stock.dataset.variantSummary = '1';
            stock.setAttribute('aria-readonly', 'true');
            row.classList.add('is-variant-summary');

            if (labelText) {
                labelText.textContent = 'Підсумок';
            }
        });

        if (sizeHint) {
            sizeHint.textContent = 'Підсумок за розміром рахується з матриці нижче.';
            sizeHint.dataset.variantMatrixHint = '1';
        }
    }

    function updateTotals()
    {
        const sizeTotals = new Map();
        let total = 0;

        cardsWrap.querySelectorAll('[data-variant-stock-input]').forEach(function (input) {
            const stock = Math.max(0, Math.floor(Number(input.value || 0)));
            const sizeKey = textKey(input.dataset.sizeName || '');

            cache.set(String(input.dataset.variantKey || ''), stock);
            total += stock;
            sizeTotals.set(sizeKey, (sizeTotals.get(sizeKey) || 0) + stock);
        });

        cardsWrap.querySelectorAll('[data-variant-size-card]').forEach(function (card) {
            const sizeKey = String(card.dataset.variantSizeKey || '');
            const totalNode = card.querySelector('[data-variant-size-total]');

            if (totalNode) {
                totalNode.textContent = String(sizeTotals.get(sizeKey) || 0) + ' шт.';
            }
        });

        totalLabel.textContent = total + ' шт.';
        syncLegacySizeTotals(sizeTotals);
    }

    function adjustStock(input, delta)
    {
        const current = Math.max(0, Math.floor(Number(input.value || 0)));
        const next = Math.max(0, current + delta);

        input.value = String(next);
        matrixTouched = true;
        updateTotals();
        input.focus();
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
        decrease.dataset.variantDecrease = '';
        decrease.textContent = '−';
        decrease.setAttribute('aria-label', 'Зменшити залишок: ' + sizeName + ', ' + color.name);

        input.type = 'number';
        input.min = '0';
        input.step = '1';
        input.inputMode = 'numeric';
        input.className = 'product-variant-stock-input';
        input.dataset.variantStockInput = '';
        input.dataset.variantKey = key;
        input.dataset.sizeName = sizeName;
        input.dataset.colorName = color.name;
        input.dataset.colorHex = color.hex;
        input.value = String(stock);
        input.setAttribute('aria-label', 'Залишок: ' + sizeName + ', ' + color.name);

        increase.type = 'button';
        increase.dataset.variantIncrease = '';
        increase.textContent = '+';
        increase.setAttribute('aria-label', 'Збільшити залишок: ' + sizeName + ', ' + color.name);

        decrease.addEventListener('click', function () {
            adjustStock(input, -1);
        });
        increase.addEventListener('click', function () {
            adjustStock(input, 1);
        });
        input.addEventListener('input', function () {
            matrixTouched = true;
            updateTotals();
        });
        input.addEventListener('blur', function () {
            input.value = String(
                Math.max(0, Math.floor(Number(input.value || 0)))
            );
            updateTotals();
        });

        stepper.appendChild(decrease);
        stepper.appendChild(input);
        stepper.appendChild(increase);
        row.appendChild(meta);
        row.appendChild(stepper);

        return row;
    }

    function render(options)
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

        cardsWrap.innerHTML = '';

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
            cardsWrap.appendChild(card);
        });

        updateTotals();
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
                stock: Math.max(0, Math.floor(Number(input.value || 0)))
            };
        });
    }

    async function loadForProduct(productId)
    {
        productId = Number(productId || 0);

        if (productId <= 0) {
            loadedProductId = 0;
            loadedRows = [];
            hasStoredMatrix = false;
            matrixTouched = false;
            cache.clear();
            render({ preferLoadedRows: true });
            return;
        }

        if (loadedProductId === productId) {
            render();
            return;
        }

        loadedProductId = productId;
        loadedRows = [];
        hasStoredMatrix = false;
        matrixTouched = false;
        cache.clear();

        try {
            const response = await fetch(
                '/Anabelka/admin/products/variant-stock?product_id=' + encodeURIComponent(productId),
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

        cache.clear();
        render({ preferLoadedRows: true });
    }

    document.addEventListener('click', function (event) {
        const edit = event.target.closest('[data-product-edit]');

        if (edit) {
            window.setTimeout(function () {
                loadForProduct(edit.dataset.productId || 0);
            }, 80);
        }

        if (event.target.closest('[data-color-picker-apply], [data-color-picker-clear]')) {
            window.setTimeout(render, 30);
        }
    });

    form.addEventListener('input', function (event) {
        if (event.target.matches('[data-size-name]')) {
            window.setTimeout(render, 0);
        }
    });

    form.addEventListener('change', function (event) {
        if (
            event.target.matches('#product-image-input')
            || event.target.matches('#product-edit-stock-mode')
        ) {
            window.setTimeout(render, 50);
        }
    });

    const observer = new MutationObserver(function () {
        window.setTimeout(render, 0);
    });
    observer.observe(sizeList, { childList: true, subtree: true });
    observer.observe(imageList, { childList: true, subtree: true });

    if (uploadPreview) {
        observer.observe(uploadPreview, { childList: true, subtree: true });
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
                const productId = Number(data.product_id || productIdField.value || 0);
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

                    hasStoredMatrix = true;
                    matrixTouched = false;
                    updateTotals();
                }
            } catch (error) {
                if (window.AnabelkaNotify) {
                    window.AnabelkaNotify.error(
                        error.message || 'Не вдалося зберегти залишки за кольорами.'
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
            render();
        }
    }, 350);
}());
