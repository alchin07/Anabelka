/*
 * Кнопка переходу з редактора товару до його картки у списку категорії.
 *
 * Сервер сам визначає категорію товару через ProductController,
 * після чого каталог прокручується до потрібної картки та підсвічує її.
 */
(function () {
    'use strict';

    function init()
    {
        const editor = document.getElementById('product-editor');
        const form = document.getElementById('product-editor-form');
        const footer = form
            ? form.querySelector('.product-editor-actions')
            : null;
        const dataElement = document.getElementById('admin-products-data');

        if (!editor || !form || !footer || !dataElement) {
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

        const preview = document.createElement('a');
        preview.className = 'product-editor-preview';
        preview.textContent = 'Перейти до товару';
        preview.hidden = true;
        preview.setAttribute('aria-label', 'Показати товар у його категорії');
        preview.style.minHeight = '43px';
        preview.style.display = 'none';
        preview.style.alignItems = 'center';
        preview.style.justifyContent = 'center';
        preview.style.padding = '9px 16px';
        preview.style.border = '1px solid var(--product-admin-purple)';
        preview.style.borderRadius = '10px';
        preview.style.background = '#fff';
        preview.style.color = 'var(--product-admin-purple)';
        preview.style.fontWeight = '850';
        preview.style.textDecoration = 'none';
        preview.style.textAlign = 'center';
        preview.style.boxSizing = 'border-box';

        const saveButton = footer.querySelector('.product-editor-save');
        footer.insertBefore(preview, saveButton || null);

        function updatePreview()
        {
            const idField = document.getElementById('product-edit-id');
            const productId = idField ? String(idField.value || '') : '';
            const product = productsById.get(productId);
            const slug = product ? String(product.slug || '').trim() : '';
            const isActive = product && Number(product.is_active || 0) === 1;

            if (!product || !slug || !isActive) {
                preview.hidden = true;
                preview.style.display = 'none';
                preview.removeAttribute('href');
                return;
            }

            preview.href = '/Anabelka/product/'
                + encodeURIComponent(slug)
                + '?view=category';
            preview.hidden = false;
            preview.style.display = 'flex';
        }

        const observer = new MutationObserver(function () {
            if (!editor.hidden) {
                window.setTimeout(updatePreview, 0);
            }
        });

        observer.observe(editor, {
            attributes: true,
            attributeFilter: ['hidden']
        });

        document.addEventListener('click', function (event) {
            if (event.target.closest('[data-product-edit]')) {
                window.setTimeout(updatePreview, 60);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
