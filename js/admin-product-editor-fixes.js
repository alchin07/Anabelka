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

        form.addEventListener('focusin', function (event) {
            const input = event.target;

            if (!input.matches('[data-size-stock]') || input.readOnly) {
                return;
            }

            if (input.value === '0') {
                input.value = '';
            }
        });

        form.addEventListener('focusout', function (event) {
            const input = event.target;

            if (!input.matches('[data-size-stock]') || input.readOnly) {
                return;
            }

            if (String(input.value || '').trim() === '') {
                input.value = '0';
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
