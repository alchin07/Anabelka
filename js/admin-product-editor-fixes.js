(function () {
    'use strict';

    function init()
    {
        const form = document.getElementById('product-editor-form');

        if (!form) {
            return;
        }

        function moveTranslationStatuses()
        {
            form.querySelectorAll('[data-product-language]').forEach(function (section) {
                const status = section.querySelector('.product-translation-status');
                const description = section.querySelector('.product-translation-description');
                const descriptionField = description
                    ? description.closest('.product-form-field')
                    : null;

                if (!status || !descriptionField) {
                    return;
                }

                let row = section.querySelector('.product-translation-status-row');

                if (!row) {
                    row = document.createElement('div');
                    row.className = 'product-translation-status-row';
                    descriptionField.insertAdjacentElement('afterend', row);
                }

                const label = status.querySelector('span');

                if (label) {
                    label.textContent = 'Стан перекладу';
                }

                row.appendChild(status);
            });
        }

        moveTranslationStatuses();

        const sizeList = document.getElementById('product-size-list');

        function prepareSizeRow(row)
        {
            if (!row || !row.matches || !row.matches('.product-size-row')) {
                return;
            }

            const name = row.querySelector('[data-size-name]');
            const stock = row.querySelector('[data-size-stock]');

            if (!name || !stock) {
                return;
            }

            stock.placeholder = '0';

            if (name.value.trim() === '' && stock.value === '0') {
                stock.value = '';
            }
        }

        if (sizeList) {
            Array.from(sizeList.children).forEach(prepareSizeRow);

            const sizeObserver = new MutationObserver(function (mutations) {
                mutations.forEach(function (mutation) {
                    mutation.addedNodes.forEach(function (node) {
                        if (node.nodeType !== 1) {
                            return;
                        }

                        if (node.matches('.product-size-row')) {
                            prepareSizeRow(node);
                        }

                        node.querySelectorAll('.product-size-row').forEach(prepareSizeRow);
                    });
                });
            });

            sizeObserver.observe(sizeList, {
                childList: true,
                subtree: true
            });
        }

        const productIdField = document.getElementById('product-edit-id');
        const variantTable = form.querySelector('[data-variant-table]');
        let activeVariantKey = '';
        let restoreFocusUntil = 0;
        let clearFocusTimer = null;

        function prepareVariantInput(input)
        {
            if (!input || !input.matches('[data-variant-stock-input]')) {
                return;
            }

            const isNewProduct = productIdField
                && Number(productIdField.value || 0) <= 0;

            if (isNewProduct && input.value === '0' && !input.dataset.mobilePrepared) {
                input.value = '';
                input.placeholder = '0';
            }

            input.dataset.mobilePrepared = '1';
            input.type = 'text';
            input.inputMode = 'numeric';
            input.pattern = '[0-9]*';
            input.autocomplete = 'off';
            input.enterKeyHint = 'next';
            input.removeAttribute('min');
            input.removeAttribute('step');
        }

        function prepareVariantInputs(root)
        {
            if (!root) {
                return;
            }

            root.querySelectorAll('[data-variant-stock-input]').forEach(prepareVariantInput);
        }

        function restoreVariantFocus()
        {
            if (
                !variantTable
                || !activeVariantKey
                || Date.now() > restoreFocusUntil
            ) {
                return;
            }

            const active = document.activeElement;

            if (active && active.matches && active.matches('[data-variant-stock-input]')) {
                return;
            }

            const input = Array.from(
                variantTable.querySelectorAll('[data-variant-stock-input]')
            ).find(function (item) {
                return String(item.dataset.variantKey || '') === activeVariantKey;
            });

            if (!input) {
                return;
            }

            window.setTimeout(function () {
                try {
                    input.focus({ preventScroll: true });
                } catch (error) {
                    input.focus();
                }
            }, 0);
        }

        if (variantTable) {
            prepareVariantInputs(variantTable);

            const variantObserver = new MutationObserver(function () {
                prepareVariantInputs(variantTable);
                restoreVariantFocus();
            });

            variantObserver.observe(variantTable, {
                childList: true,
                subtree: true
            });
        }

        form.addEventListener('focusin', function (event) {
            if (!event.target.matches('[data-variant-stock-input]')) {
                return;
            }

            window.clearTimeout(clearFocusTimer);
            activeVariantKey = String(event.target.dataset.variantKey || '');
            restoreFocusUntil = Date.now() + 1500;
        });

        form.addEventListener('focusout', function (event) {
            if (!event.target.matches('[data-variant-stock-input]')) {
                return;
            }

            window.clearTimeout(clearFocusTimer);
            clearFocusTimer = window.setTimeout(function () {
                const active = document.activeElement;

                if (!active || !active.matches || !active.matches('[data-variant-stock-input]')) {
                    activeVariantKey = '';
                    restoreFocusUntil = 0;
                }
            }, 180);
        });

        form.addEventListener('input', function (event) {
            const input = event.target;

            if (!input.matches('[data-variant-stock-input]')) {
                return;
            }

            const cleaned = String(input.value || '').replace(/[^0-9]/g, '');

            if (cleaned !== input.value) {
                input.value = cleaned;
            }

            activeVariantKey = String(input.dataset.variantKey || '');
            restoreFocusUntil = Date.now() + 1500;
        }, true);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
