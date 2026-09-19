(function () {
    'use strict';

    const dataElement = document.getElementById('admin-products-data');
    const list = document.getElementById('product-color-list');
    const addButton = document.querySelector('[data-product-color-add]');

    if (!dataElement || !list || !addButton) {
        return;
    }

    let products = [];

    try {
        products = JSON.parse(dataElement.textContent || '[]');
    } catch (error) {
        products = [];
    }

    const productsById = new Map();

    (Array.isArray(products) ? products : []).forEach(function (product) {
        productsById.set(
            String(product.id || ''),
            product
        );
    });


    function normalizedHex(value)
    {
        const hex = String(value || '').trim().toLowerCase();

        return /^#[0-9a-f]{6}$/.test(hex)
            ? hex
            : '#b8b0bd';
    }


    function refreshRow(group)
    {
        if (!group) {
            return;
        }

        const nameInput = group.querySelector('[data-image-color-name]');
        const hexInput = group.querySelector('[data-image-color-hex]');
        const button = group.querySelector('[data-image-color-open]');
        const dot = group.querySelector('[data-image-color-dot]');
        const label = group.querySelector('[data-image-color-label]');

        if (!nameInput || !hexInput || !button || !dot || !label) {
            return;
        }

        const name = String(nameInput.value || '').trim();

        group.classList.toggle('has-color', name !== '');

        if (name === '') {
            dot.style.removeProperty('--image-color');
            label.textContent = 'Вибрати колір';
            button.setAttribute(
                'aria-label',
                'Вибрати колір товару'
            );
            return;
        }

        dot.style.setProperty(
            '--image-color',
            normalizedHex(hexInput.value)
        );
        label.textContent = name;
        button.setAttribute(
            'aria-label',
            'Змінити колір: ' + name
        );
    }


    function announceChange()
    {
        document.dispatchEvent(new CustomEvent(
            'anabelka:product-colors-change'
        ));
    }


    function createRow(color)
    {
        color = color && typeof color === 'object'
            ? color
            : {};

        const row = document.createElement('div');
        const fields = document.createElement('div');
        const name = document.createElement('input');
        const hex = document.createElement('input');
        const button = document.createElement('button');
        const dot = document.createElement('span');
        const label = document.createElement('span');
        const remove = document.createElement('button');

        row.className =
            'product-manual-color-row product-image-color-fields';
        row.dataset.productManualColor = '';

        fields.className = 'product-manual-color-fields';

        name.type = 'hidden';
        name.name = 'product_color_name[]';
        name.value = String(color.name || '');
        name.dataset.imageColorName = '';

        hex.type = 'hidden';
        hex.name = 'product_color_hex[]';
        hex.value = normalizedHex(color.hex || '');
        hex.dataset.imageColorHex = '';

        button.type = 'button';
        button.className = 'product-manual-color-button';
        button.dataset.imageColorOpen = '';

        dot.className = 'product-image-color-dot';
        dot.dataset.imageColorDot = '';
        dot.setAttribute('aria-hidden', 'true');

        label.className = 'product-image-color-label';
        label.dataset.imageColorLabel = '';

        remove.type = 'button';
        remove.className = 'product-manual-color-remove';
        remove.dataset.productColorRemove = '';
        remove.textContent = '×';
        remove.setAttribute('aria-label', 'Видалити колір');

        button.appendChild(dot);
        button.appendChild(label);
        fields.appendChild(name);
        fields.appendChild(hex);
        fields.appendChild(button);
        row.appendChild(fields);
        row.appendChild(remove);

        remove.addEventListener('click', function () {
            row.remove();
            announceChange();
        });

        refreshRow(row);

        return row;
    }


    function render(colors)
    {
        list.replaceChildren();

        (Array.isArray(colors) ? colors : []).forEach(function (color) {
            list.appendChild(createRow(color));
        });

        if (list.children.length === 0) {
            const empty = document.createElement('p');

            empty.className = 'product-color-list-empty';
            empty.dataset.productColorEmpty = '';
            empty.textContent =
                'Кольори ще не додані. Фото для цього не потрібне.';
            list.appendChild(empty);
        }

        announceChange();
    }


    function addColor()
    {
        const empty = list.querySelector('[data-product-color-empty]');

        if (empty) {
            empty.remove();
        }

        const row = createRow({
            name: '',
            hex: '#b8b0bd'
        });

        list.appendChild(row);
        announceChange();

        const trigger = row.querySelector('[data-image-color-open]');

        if (trigger) {
            trigger.click();
        }
    }


    addButton.addEventListener('click', addColor);


    document.addEventListener('click', function (event) {
        const edit = event.target.closest('[data-product-edit]');
        const create = event.target.closest('[data-product-create]');

        if (edit) {
            const product = productsById.get(
                String(edit.dataset.productId || '')
            );

            render(product && Array.isArray(product.colors)
                ? product.colors
                : []);
            return;
        }

        if (create) {
            render([]);
        }
    });


    document.addEventListener(
        'anabelka:product-color-change',
        function (event) {
            const group = event.detail
                ? event.detail.group
                : null;

            if (
                !group
                || !group.matches('[data-product-manual-color]')
            ) {
                return;
            }

            const nameInput = group.querySelector(
                '[data-image-color-name]'
            );

            if (
                !nameInput
                || String(nameInput.value || '').trim() === ''
            ) {
                group.remove();

                if (list.children.length === 0) {
                    render([]);
                    return;
                }
            } else {
                refreshRow(group);
            }

            announceChange();
        }
    );


    render([]);
}());
