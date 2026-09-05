(function () {
    'use strict';

    const form = document.getElementById('product-editor-form');
    const editor = document.getElementById('product-editor');
    const sizeList = document.getElementById('product-size-list');
    const imageList = document.getElementById('product-image-list');
    const uploadPreview = document.getElementById('product-upload-preview');
    const productIdField = document.getElementById('product-edit-id');

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
        '    <strong>Кількість за розміром і кольором</strong>',
        '    <span>Залишок задається для кожної комбінації.</span>',
        '  </div>',
        '  <strong data-variant-total>0 шт.</strong>',
        '</div>',
        '<div class="product-variant-stock-note" data-variant-note></div>',
        '<div class="product-variant-stock-table-wrap" data-variant-table></div>'
    ].join('');

    sizeList.insertAdjacentElement('afterend', block);

    const tableWrap = block.querySelector('[data-variant-table]');
    const note = block.querySelector('[data-variant-note]');
    const totalLabel = block.querySelector('[data-variant-total]');
    const cache = new Map();
    let loadedProductId = 0;
    let loadedRows = [];

    const style = document.createElement('style');
    style.textContent = [
        '.product-variant-stock-block{margin-top:16px;padding-top:15px;border-top:1px solid #e2d8e8}',
        '.product-variant-stock-head{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;margin-bottom:10px}',
        '.product-variant-stock-head>div{display:grid;gap:3px}',
        '.product-variant-stock-head span,.product-variant-stock-note{color:#77707c;font-size:11px}',
        '.product-variant-stock-note{padding:10px;border-radius:10px;background:#faf7fc}',
        '.product-variant-stock-table-wrap{overflow-x:auto;margin-top:10px;padding-bottom:4px}',
        '.product-variant-stock-table{min-width:100%;border-collapse:separate;border-spacing:6px;font-size:12px}',
        '.product-variant-stock-table th{font-weight:800;text-align:center;white-space:nowrap}',
        '.product-variant-stock-table th:first-child{text-align:left;position:sticky;left:0;background:#fff;z-index:2}',
        '.product-variant-stock-color{display:inline-flex;align-items:center;gap:6px}',
        '.product-variant-stock-dot{width:18px;height:18px;border:1px solid rgba(50,43,55,.28);border-radius:50%;background:var(--variant-color,#b8b0bd)}',
        '.product-variant-stock-size{min-width:72px;padding-right:5px;text-align:left}',
        '.product-variant-stock-input{width:74px;min-height:40px;padding:7px 8px;border:1px solid #d8cedf;border-radius:9px;background:#fff;text-align:center;font:inherit}',
        '.product-variant-stock-input:focus{border-color:#8A2BE2;outline:3px solid rgba(138,43,226,.13)}',
        '@media(max-width:650px){.product-variant-stock-head{align-items:flex-start}.product-variant-stock-input{width:68px}}'
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
        tableWrap.querySelectorAll('[data-variant-stock-input]').forEach(function (input) {
            cache.set(String(input.dataset.variantKey || ''), Math.max(0, Number(input.value || 0)));
        });
    }


    function seedLoadedRows()
    {
        loadedRows.forEach(function (row) {
            const key = textKey(row.size_name) + '||' + colorKey(row.color_name, row.color_hex);

            if (!cache.has(key)) {
                cache.set(key, Math.max(0, Number(row.stock || 0)));
            }
        });
    }


    function render()
    {
        cacheCurrentInputs();
        seedLoadedRows();

        const sizes = currentSizes();
        const colors = currentColors();

        if (sizes.length === 0) {
            note.textContent = 'Спочатку додайте хоча б один розмір.';
            tableWrap.innerHTML = '';
            totalLabel.textContent = '0 шт.';
            return;
        }

        if (colors.length === 0) {
            note.textContent = 'Призначте колір хоча б одній фотографії товару.';
            tableWrap.innerHTML = '';
            totalLabel.textContent = '0 шт.';
            return;
        }

        note.textContent = 'Заповніть кількість для кожного розміру в кожному кольорі.';
        const table = document.createElement('table');
        table.className = 'product-variant-stock-table';
        const thead = document.createElement('thead');
        const headRow = document.createElement('tr');
        const first = document.createElement('th');
        first.textContent = 'Розмір';
        headRow.appendChild(first);

        colors.forEach(function (color) {
            const th = document.createElement('th');
            const label = document.createElement('span');
            label.className = 'product-variant-stock-color';
            const dot = document.createElement('span');
            dot.className = 'product-variant-stock-dot';
            dot.style.setProperty('--variant-color', color.hex || '#b8b0bd');
            const text = document.createElement('span');
            text.textContent = color.name;
            label.appendChild(dot);
            label.appendChild(text);
            th.appendChild(label);
            headRow.appendChild(th);
        });

        thead.appendChild(headRow);
        table.appendChild(thead);
        const tbody = document.createElement('tbody');

        sizes.forEach(function (sizeName) {
            const row = document.createElement('tr');
            const title = document.createElement('th');
            title.className = 'product-variant-stock-size';
            title.textContent = sizeName;
            row.appendChild(title);

            colors.forEach(function (color) {
                const td = document.createElement('td');
                const input = document.createElement('input');
                const key = textKey(sizeName) + '||' + color.key;
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
                input.value = String(Math.max(0, Number(cache.get(key) || 0)));
                input.addEventListener('input', updateTotal);
                td.appendChild(input);
                row.appendChild(td);
            });

            tbody.appendChild(row);
        });

        table.appendChild(tbody);
        tableWrap.innerHTML = '';
        tableWrap.appendChild(table);
        updateTotal();
    }


    function updateTotal()
    {
        const total = Array.from(
            tableWrap.querySelectorAll('[data-variant-stock-input]')
        ).reduce(function (sum, input) {
            return sum + Math.max(0, Number(input.value || 0));
        }, 0);

        totalLabel.textContent = total + ' шт.';
    }


    function matrixRows()
    {
        return Array.from(
            tableWrap.querySelectorAll('[data-variant-stock-input]')
        ).map(function (input) {
            return {
                size_name: String(input.dataset.sizeName || ''),
                color_name: String(input.dataset.colorName || ''),
                color_hex: String(input.dataset.colorHex || ''),
                stock: Math.max(0, Number(input.value || 0))
            };
        });
    }


    async function loadForProduct(productId)
    {
        productId = Number(productId || 0);

        if (productId <= 0) {
            loadedProductId = 0;
            loadedRows = [];
            cache.clear();
            render();
            return;
        }

        if (loadedProductId === productId) {
            render();
            return;
        }

        loadedProductId = productId;
        loadedRows = [];
        cache.clear();

        try {
            const response = await fetch(
                '/Anabelka/admin/products/variant-stock?product_id=' + encodeURIComponent(productId),
                { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
            );
            const data = await response.json();

            if (response.ok && data.success && Array.isArray(data.rows)) {
                loadedRows = data.rows;
            }
        } catch (error) {
            loadedRows = [];
        }

        render();
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
        if (event.target.matches('#product-image-input')) {
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

        if (method === 'POST' && url.indexOf('/Anabelka/admin/products/save') !== -1 && response.ok) {
            try {
                const data = await response.clone().json();
                const productId = Number(data.product_id || productIdField.value || 0);
                const csrf = form.querySelector('input[name="csrf_token"]');
                const rows = matrixRows();

                if (data.success && productId > 0 && csrf && rows.length > 0) {
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
                        throw new Error(variantData.message || 'Не вдалося зберегти залишки за кольорами.');
                    }
                }
            } catch (error) {
                const message = document.getElementById('site-message');

                if (message) {
                    message.textContent = error.message || 'Не вдалося зберегти залишки за кольорами.';
                    message.classList.add('show');
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
})();
