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

    function enhance()
    {
        document.querySelectorAll('.admin-order-item > div > span').forEach(function (line) {
            if (line.dataset.variantEnhanced === '1') {
                return;
            }

            const text = String(line.textContent || '')
                .replace(/\s+/g, ' ')
                .trim();

            const sizeMatch = text.match(/Розмір:\s*([^·]+?)(?=\s*·|$)/i);
            const colorMatch = text.match(/Колір:\s*([^·]+?)(?=\s*·|$)/i);
            const quantityMatch = text.match(/(\d+)\s*шт\.?/i);
            const skuMatch = text.match(/SKU:\s*([^·]+?)(?=\s*·|$)/i);

            if (!sizeMatch && !colorMatch) {
                return;
            }

            const parts = [];

            if (skuMatch) {
                parts.push(
                    '<span class="admin-order-variant-sku">SKU: '
                    + escapeHtml(skuMatch[1].trim())
                    + '</span>'
                );
            }

            if (sizeMatch) {
                parts.push(
                    '<span class="admin-order-variant-chip">Розмір: <strong>'
                    + escapeHtml(sizeMatch[1].trim())
                    + '</strong></span>'
                );
            }

            if (colorMatch) {
                parts.push(
                    '<span class="admin-order-variant-chip is-color">Колір: <strong>'
                    + escapeHtml(colorMatch[1].trim())
                    + '</strong></span>'
                );
            }

            if (quantityMatch) {
                parts.push(
                    '<span class="admin-order-variant-quantity">'
                    + escapeHtml(quantityMatch[1])
                    + ' шт.</span>'
                );
            }

            line.dataset.variantEnhanced = '1';
            line.classList.add('admin-order-variant-line');
            line.innerHTML = parts.join('');
        });
    }

    const style = document.createElement('style');
    style.textContent = [
        '.admin-order-variant-line{display:flex!important;flex-wrap:wrap;align-items:center;gap:7px;margin-top:7px}',
        '.admin-order-variant-sku{color:#8b828f;font-size:12px}',
        '.admin-order-variant-chip{display:inline-flex;align-items:center;gap:4px;padding:5px 8px;border:1px solid #e6d8f0;border-radius:999px;background:#fff;color:#514958;font-size:12px}',
        '.admin-order-variant-chip.is-color{border-color:#d9c2eb;background:#faf5ff;color:#69358b}',
        '.admin-order-variant-quantity{color:#726979;font-size:12px;font-weight:700}'
    ].join('\n');
    document.head.appendChild(style);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', enhance);
    } else {
        enhance();
    }
})();
