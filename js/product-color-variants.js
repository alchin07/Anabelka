(function () {
    'use strict';

    const cartForm = document.getElementById('cart-form');

    if (!cartForm) {
        return;
    }

    const productIdField = cartForm.querySelector('input[name="product_id"]');
    const sizeOptions = cartForm.querySelector('.size-options');
    const sizeCheckboxes = Array.from(
        cartForm.querySelectorAll('.size-checkbox')
    );
    const galleryMain = document.querySelector('[data-product-gallery-main]');
    const galleryThumbs = Array.from(
        document.querySelectorAll('[data-product-gallery-thumb]')
    );

    if (!productIdField || !sizeOptions) {
        return;
    }

    const hiddenColorKey = document.createElement('input');
    hiddenColorKey.type = 'hidden';
    hiddenColorKey.name = 'color_key';
    const hiddenColorName = document.createElement('input');
    hiddenColorName.type = 'hidden';
    hiddenColorName.name = 'color_name';
    const hiddenColorHex = document.createElement('input');
    hiddenColorHex.type = 'hidden';
    hiddenColorHex.name = 'color_hex';
    cartForm.appendChild(hiddenColorKey);
    cartForm.appendChild(hiddenColorName);
    cartForm.appendChild(hiddenColorHex);

    const sizeBlock = sizeOptions.parentElement;
    const colorBlock = document.createElement('section');
    colorBlock.className = 'product-page-color-block';
    colorBlock.innerHTML = [
        '<div class="product-page-color-head">',
        '  <strong>Колір:</strong>',
        '  <span data-product-selected-color>—</span>',
        '</div>',
        '<div class="product-page-color-options" data-product-color-options></div>'
    ].join('');
    sizeBlock.parentElement.insertBefore(colorBlock, sizeBlock);

    const selectedColorLabel = colorBlock.querySelector(
        '[data-product-selected-color]'
    );
    const colorOptions = colorBlock.querySelector(
        '[data-product-color-options]'
    );

    const style = document.createElement('style');
    style.textContent = [
        '.product-page-color-block{padding:15px 15px 2px}',
        '.product-page-color-head{display:flex;align-items:center;gap:7px;margin-bottom:11px}',
        '.product-page-color-head span{color:#625968;font-weight:700}',
        '.product-page-color-options{display:flex;flex-wrap:wrap;gap:9px}',
        '.product-page-color-swatch{width:42px;height:42px;display:grid;place-items:center;padding:0;border:2px solid transparent;border-radius:50%;background:#fff;cursor:pointer}',
        '.product-page-color-swatch::before{content:"";width:28px;height:28px;border:1px solid rgba(45,40,48,.28);border-radius:50%;background:var(--product-page-color,#b8b0bd);box-shadow:inset 0 0 0 1px rgba(255,255,255,.3)}',
        '.product-page-color-swatch.is-active{border-color:var(--primary-color);box-shadow:0 0 0 3px rgba(138,43,226,.12)}',
        '.product-page-color-swatch:disabled{opacity:.35;cursor:not-allowed}',
        '.product-page-color-empty{color:#777;font-size:13px}',
        '.product-size-unavailable{opacity:.42!important;cursor:not-allowed!important}'
    ].join('\n');
    document.head.appendChild(style);

    const state = {
        colors: [],
        stockRows: [],
        stockMap: new Map(),
        usesVariantStock: false,
        selectedColor: null
    };


    function showMessage(text)
    {
        const message = document.getElementById('site-message');

        if (!message) {
            return;
        }

        message.textContent = text;
        message.classList.add('show');
        clearTimeout(window.productColorVariantMessageTimer);
        window.productColorVariantMessageTimer = window.setTimeout(function () {
            message.classList.remove('show');
        }, 2400);
    }


    function currentProductSlug()
    {
        const match = window.location.pathname.match(/\/product\/([^/]+)$/);
        return match ? decodeURIComponent(match[1]) : '';
    }


    function stockKey(sizeId, colorKey)
    {
        return String(sizeId) + '||' + String(colorKey || '');
    }


    function rebuildStockMap()
    {
        state.stockMap.clear();

        state.stockRows.forEach(function (row) {
            state.stockMap.set(
                stockKey(row.size_id, row.color_key),
                Math.max(0, Number(row.stock || 0))
            );
        });
    }


    function stockForSize(sizeId)
    {
        if (!state.usesVariantStock || !state.selectedColor) {
            const checkbox = sizeCheckboxes.find(function (item) {
                return Number(item.value) === Number(sizeId);
            });
            const button = checkbox ? checkbox.nextElementSibling : null;
            return button ? Math.max(0, Number(button.dataset.stock || 0)) : 0;
        }

        return Math.max(
            0,
            Number(
                state.stockMap.get(
                    stockKey(sizeId, state.selectedColor.key)
                ) || 0
            )
        );
    }


    function updateStockSummary()
    {
        let summary = Array.from(cartForm.querySelectorAll('p')).find(function (p) {
            return String(p.textContent || '').trim().toLowerCase().startsWith('в наличии');
        });

        if (!summary) {
            return;
        }

        if (!state.usesVariantStock || !state.selectedColor) {
            return;
        }

        const total = sizeCheckboxes.reduce(function (sum, checkbox) {
            return sum + stockForSize(Number(checkbox.value));
        }, 0);

        summary.textContent = 'В наличии в цвете «'
            + state.selectedColor.name
            + '»: '
            + total
            + ' шт.';
    }


    function refreshSizes()
    {
        sizeCheckboxes.forEach(function (checkbox) {
            const label = checkbox.closest('label');
            const button = checkbox.nextElementSibling;
            const stockElement = button
                ? button.querySelector('.size-stock')
                : null;
            const stock = stockForSize(Number(checkbox.value));
            const available = !state.usesVariantStock || stock > 0;

            if (button) {
                button.dataset.stock = String(stock);
            }

            checkbox.disabled = !available;

            if (!available) {
                checkbox.checked = false;
            }

            if (label) {
                label.classList.toggle('product-size-unavailable', !available);
                label.style.cursor = available ? 'pointer' : 'not-allowed';
                label.style.opacity = available ? '1' : '0.45';
            }

            if (button) {
                if (!available) {
                    button.style.background = '#f3f3f3';
                    button.style.color = '#888888';
                    button.style.borderColor = '#aaaaaa';
                } else if (checkbox.checked) {
                    button.style.background = 'var(--primary-color)';
                    button.style.color = '#fff';
                    button.style.borderColor = 'var(--primary-color)';
                } else {
                    button.style.background = '#fff';
                    button.style.color = 'var(--primary-color)';
                    button.style.borderColor = 'var(--primary-color)';
                }
            }

            if (stockElement) {
                const showQuantity = stockElement.dataset.showQuantity === '1';

                if (!available) {
                    stockElement.textContent = 'Нет в наличии';
                    stockElement.style.display = 'inline';
                } else if (showQuantity) {
                    stockElement.textContent = stock + ' шт.';
                } else {
                    stockElement.textContent = '';
                    stockElement.style.display = 'none';
                }
            }
        });

        updateStockSummary();
    }


    function selectGalleryImage(path)
    {
        path = String(path || '').trim();

        if (path === '') {
            return;
        }

        if (galleryMain) {
            galleryMain.src = path;
        }

        galleryThumbs.forEach(function (thumb) {
            const selected = String(thumb.dataset.imageSrc || '') === path;
            thumb.classList.toggle('is-active', selected);
            thumb.setAttribute('aria-pressed', selected ? 'true' : 'false');
        });
    }


    function updateUrlColor(colorKey)
    {
        const url = new URL(window.location.href);

        if (colorKey) {
            url.searchParams.set('color_key', colorKey);
        } else {
            url.searchParams.delete('color_key');
        }

        window.history.replaceState({}, '', url.pathname + url.search + url.hash);
    }


    function selectColor(color, updateUrl)
    {
        state.selectedColor = color;
        hiddenColorKey.value = color ? String(color.key || '') : '';
        hiddenColorName.value = color ? String(color.name || '') : '';
        hiddenColorHex.value = color ? String(color.hex || '') : '';
        selectedColorLabel.textContent = color ? color.name : '—';

        colorOptions.querySelectorAll('[data-product-page-color]').forEach(function (button) {
            const selected = color
                && String(button.dataset.colorKey || '') === String(color.key || '');
            button.classList.toggle('is-active', Boolean(selected));
            button.setAttribute('aria-pressed', selected ? 'true' : 'false');
        });

        if (color && color.image) {
            selectGalleryImage(color.image);
        }

        sizeCheckboxes.forEach(function (checkbox) {
            checkbox.checked = false;
        });
        refreshSizes();

        if (updateUrl !== false) {
            updateUrlColor(color ? color.key : '');
        }
    }


    function renderColors()
    {
        colorOptions.innerHTML = '';

        if (state.colors.length === 0) {
            const empty = document.createElement('span');
            empty.className = 'product-page-color-empty';
            empty.textContent = 'Для цього товару колір не задано.';
            colorOptions.appendChild(empty);
            colorBlock.hidden = true;
            return;
        }

        colorBlock.hidden = false;
        state.colors.forEach(function (color) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'product-page-color-swatch';
            button.dataset.productPageColor = '';
            button.dataset.colorKey = color.key;
            button.style.setProperty(
                '--product-page-color',
                color.hex || '#b8b0bd'
            );
            button.title = color.name;
            button.setAttribute('aria-label', 'Колір: ' + color.name);
            button.setAttribute('aria-pressed', 'false');
            button.addEventListener('click', function () {
                selectColor(color, true);
            });
            colorOptions.appendChild(button);
        });
    }


    async function loadVariants()
    {
        const slug = currentProductSlug();

        if (slug === '') {
            return;
        }

        try {
            const response = await fetch(
                '/Anabelka/product/' + encodeURIComponent(slug) + '/variants',
                { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
            );
            const data = await response.json();

            if (!response.ok || !data.success) {
                return;
            }

            state.colors = Array.isArray(data.colors) ? data.colors : [];
            state.stockRows = Array.isArray(data.stock) ? data.stock : [];
            state.usesVariantStock = Boolean(data.uses_variant_stock);
            rebuildStockMap();
            renderColors();

            const requestedKey = String(
                new URLSearchParams(window.location.search).get('color_key') || ''
            ).trim();
            let selected = state.colors.find(function (color) {
                return String(color.key || '') === requestedKey;
            });

            if (!selected && galleryMain) {
                const currentImage = String(galleryMain.currentSrc || galleryMain.src || '');
                selected = state.colors.find(function (color) {
                    try {
                        return new URL(color.image, window.location.origin).href === currentImage;
                    } catch (error) {
                        return false;
                    }
                });
            }

            if (!selected) {
                selected = state.colors.find(function (color) {
                    if (!state.usesVariantStock) {
                        return true;
                    }

                    return sizeCheckboxes.some(function (checkbox) {
                        return Number(
                            state.stockMap.get(
                                stockKey(checkbox.value, color.key)
                            ) || 0
                        ) > 0;
                    });
                }) || state.colors[0] || null;
            }

            selectColor(selected, requestedKey === '');
        } catch (error) {
            // Старий режим сторінки залишається працездатним без цього шару.
        }
    }


    async function submitColorCart(event)
    {
        event.preventDefault();
        event.stopImmediatePropagation();

        const selectedSizes = sizeCheckboxes.filter(function (checkbox) {
            return checkbox.checked && !checkbox.disabled;
        });

        if (state.colors.length > 0 && !state.selectedColor) {
            showMessage('Выберите цвет.');
            return;
        }

        if (selectedSizes.length === 0) {
            showMessage('Выберите хотя бы один размер.');
            return;
        }

        const formData = new FormData(cartForm);

        try {
            const response = await fetch(cartForm.action, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const text = await response.text();
            let data = null;

            try {
                data = JSON.parse(text);
            } catch (error) {
                showMessage('Ошибка при добавлении товара.');
                return;
            }

            if (!data.success) {
                showMessage(data.message || 'Не удалось добавить товар в корзину.');
                return;
            }

            if (state.usesVariantStock && state.selectedColor) {
                selectedSizes.forEach(function (checkbox) {
                    const key = stockKey(checkbox.value, state.selectedColor.key);
                    const stock = Math.max(0, Number(state.stockMap.get(key) || 0) - 1);
                    state.stockMap.set(key, stock);
                });
            }

            sizeCheckboxes.forEach(function (checkbox) {
                checkbox.checked = false;
            });
            refreshSizes();

            const cartCount = document.getElementById('cart-count');

            if (cartCount) {
                cartCount.textContent = data.cart_count;
            }

            showMessage('✓ Товар добавлен в корзину');
        } catch (error) {
            showMessage('Не удалось добавить товар.');
        }
    }


    cartForm.addEventListener('submit', submitColorCart, true);
    loadVariants();
})();
