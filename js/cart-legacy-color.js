(function () {
    'use strict';

    function escapeHtml(value)
    {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function validHex(value)
    {
        return /^#[0-9a-f]{6}$/i.test(String(value || ''))
            ? String(value)
            : '#b8b0bd';
    }

    function showRequiredBanner()
    {
        const params = new URLSearchParams(window.location.search);

        if (params.get('color_required') !== '1') {
            return;
        }

        const page = document.getElementById('cart-page');

        if (!page || page.querySelector('[data-cart-color-banner]')) {
            return;
        }

        const banner = document.createElement('div');
        banner.dataset.cartColorBanner = '1';
        banner.style.cssText = [
            'margin:0 0 18px',
            'padding:14px 16px',
            'border:1px solid #d9c2eb',
            'border-radius:12px',
            'background:#faf5ff',
            'color:#5d3b72',
            'font-weight:700'
        ].join(';');
        banner.textContent = 'Для старої позиції в кошику потрібно вибрати колір перед оформленням замовлення.';
        page.insertBefore(banner, page.firstChild);
    }

    async function loadItem(item)
    {
        const cartKey = String(item.dataset.cartKey || '').trim();

        if (cartKey === '') {
            return;
        }

        try {
            const response = await fetch(
                '/Anabelka/cart/color-options?cart_key=' + encodeURIComponent(cartKey),
                { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
            );
            const data = await response.json();

            if (!response.ok || !data.success || !data.requires_color) {
                return;
            }

            renderChooser(item, data);
        } catch (error) {
            // Стара позиція просто залишиться без автоматичного відновлення.
        }
    }

    function renderChooser(item, data)
    {
        if (item.querySelector('[data-cart-color-repair]')) {
            return;
        }

        const block = document.createElement('div');
        block.dataset.cartColorRepair = '1';
        block.style.cssText = [
            'margin:12px 0',
            'padding:12px',
            'border:1px solid #e0cbed',
            'border-radius:12px',
            'background:#fcf8ff'
        ].join(';');

        const options = Array.isArray(data.options) ? data.options : [];
        const availableCount = options.filter(function (option) {
            return Boolean(option.available);
        }).length;
        let html = '<strong style="display:block;margin-bottom:8px;color:#69358b">Оберіть колір для цієї позиції</strong>';

        if (options.length === 0) {
            html += '<span style="color:#8a4452">Для цього розміру кольорових варіантів немає. Видаліть позицію або додайте товар заново.</span>';
        } else {
            if (availableCount === 0) {
                html += '<span style="display:block;margin-bottom:8px;color:#8a4452">Зараз немає достатнього залишку жодного кольору для цієї кількості.</span>';
            }

            html += '<div style="display:flex;flex-wrap:wrap;gap:8px">';

            options.forEach(function (option) {
                const available = Boolean(option.available);
                const availableQuantity = Number(option.available_quantity || 0);
                const disabled = available ? '' : ' disabled aria-disabled="true" ';
                const style = available
                    ? 'display:inline-flex;align-items:center;gap:7px;padding:8px 11px;border:1px solid #cda9e4;border-radius:999px;background:#fff;color:#4e3e58;font-weight:700;cursor:pointer'
                    : 'display:inline-flex;align-items:center;gap:7px;padding:8px 11px;border:1px solid #ded7e2;border-radius:999px;background:#f5f2f6;color:#9a929d;font-weight:700;opacity:.58;cursor:not-allowed;text-decoration:line-through';
                const title = available
                    ? 'Доступно: ' + availableQuantity
                    : 'Недостатньо залишку для цієї позиції';

                html += [
                    '<button type="button" data-cart-color-option ',
                    'data-product-id="', escapeHtml(data.product_id), '" ',
                    'data-size-id="', escapeHtml(data.size_id), '" ',
                    'data-color-key="', escapeHtml(option.color_key), '" ',
                    disabled,
                    'title="', escapeHtml(title), '" ',
                    'style="', style, '">',
                    '<span aria-hidden="true" style="width:18px;height:18px;border-radius:50%;border:1px solid rgba(40,35,45,.25);background:',
                    escapeHtml(validHex(option.color_hex)), '"></span>',
                    escapeHtml(option.color_name),
                    available ? '' : ' · немає',
                    '</button>'
                ].join('');
            });

            html += '</div>';
        }

        block.innerHTML = html;
        item.appendChild(block);

        block.querySelectorAll('[data-cart-color-option]:not([disabled])').forEach(function (button) {
            button.addEventListener('click', async function () {
                button.disabled = true;

                const body = new URLSearchParams();
                body.set('product_id', button.dataset.productId || '');
                body.set('size_id', button.dataset.sizeId || '');
                body.set('color_key', button.dataset.colorKey || '');

                try {
                    const response = await fetch('/Anabelka/cart/set-color', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: body.toString()
                    });
                    const result = await response.json();

                    if (!response.ok || !result.success) {
                        window.alert(result.message || 'Не вдалося зберегти колір.');
                        button.disabled = false;
                        return;
                    }

                    window.location.href = '/Anabelka/cart';
                } catch (error) {
                    window.alert('Не вдалося зберегти колір.');
                    button.disabled = false;
                }
            });
        });
    }

    function init()
    {
        showRequiredBanner();
        document.querySelectorAll('.cart-item').forEach(loadItem);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
