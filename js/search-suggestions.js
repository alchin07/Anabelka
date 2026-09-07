(function () {
    'use strict';

    const form = document.querySelector('.site-search-form');

    if (!form) {
        return;
    }

    const input = form.querySelector('.site-search-input');
    const panel = form.querySelector('.site-search-suggestions');
    const endpoint = form.dataset.searchSuggestEndpoint || '';

    if (!input || !panel || endpoint === '') {
        return;
    }

    const labels = {
        products: form.dataset.searchProductsLabel || 'Товари',
        categories: form.dataset.searchCategoriesLabel || 'Категорії',
        empty: form.dataset.searchEmptyLabel || 'Нічого не знайдено.',
        all: form.dataset.searchAllLabel || 'Показати всі результати'
    };

    let timer = null;
    let controller = null;
    let activeIndex = -1;
    let items = [];
    let lastRenderedQuery = '';

    const closePanel = function () {
        panel.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        activeIndex = -1;
        items = [];
    };

    const openPanel = function () {
        panel.hidden = false;
        input.setAttribute('aria-expanded', 'true');
    };

    const clearPanel = function () {
        panel.replaceChildren();
        activeIndex = -1;
        items = [];
    };

    const sectionTitle = function (text) {
        const title = document.createElement('div');
        title.className = 'site-search-suggestion-title';
        title.textContent = text;
        return title;
    };

    const productItem = function (product) {
        const link = document.createElement('a');
        link.className = 'site-search-suggestion-item is-product';
        link.href = product.url || '#';
        link.setAttribute('role', 'option');

        const imageBox = document.createElement('span');
        imageBox.className = 'site-search-suggestion-image';

        if (product.image) {
            const image = document.createElement('img');
            image.src = product.image;
            image.alt = '';
            image.loading = 'lazy';
            imageBox.appendChild(image);
        } else {
            imageBox.textContent = 'A';
        }

        const copy = document.createElement('span');
        copy.className = 'site-search-suggestion-copy';

        const name = document.createElement('strong');
        name.textContent = product.name || '';
        copy.appendChild(name);

        if (product.sku) {
            const sku = document.createElement('small');
            sku.textContent = 'SKU: ' + product.sku;
            copy.appendChild(sku);
        }

        const price = document.createElement('span');
        price.className = 'site-search-suggestion-price';
        price.textContent = product.price || '';

        link.appendChild(imageBox);
        link.appendChild(copy);
        link.appendChild(price);

        return link;
    };

    const categoryItem = function (category) {
        const link = document.createElement('a');
        link.className = 'site-search-suggestion-item site-search-suggestion-category';
        link.href = category.url || '#';
        link.setAttribute('role', 'option');

        const copy = document.createElement('span');
        copy.className = 'site-search-suggestion-copy';

        const name = document.createElement('strong');
        name.textContent = category.name || '';
        copy.appendChild(name);
        link.appendChild(copy);

        return link;
    };

    const buildSection = function (titleText, resultItems, builder) {
        if (!Array.isArray(resultItems) || resultItems.length === 0) {
            return;
        }

        const section = document.createElement('div');
        section.className = 'site-search-suggestion-section';
        section.appendChild(sectionTitle(titleText));

        resultItems.forEach(function (entry) {
            section.appendChild(builder(entry));
        });

        panel.appendChild(section);
    };

    const refreshSelectableItems = function () {
        items = Array.from(
            panel.querySelectorAll('.site-search-suggestion-item, .site-search-suggestion-all')
        );
        activeIndex = -1;
    };

    const render = function (data, query) {
        if (input.value.trim() !== query) {
            return;
        }

        clearPanel();
        lastRenderedQuery = query;

        const products = Array.isArray(data.products) ? data.products : [];
        const categories = Array.isArray(data.categories) ? data.categories : [];

        buildSection(labels.products, products, productItem);
        buildSection(labels.categories, categories, categoryItem);

        if (products.length === 0 && categories.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'site-search-suggestion-empty';
            empty.textContent = labels.empty;
            panel.appendChild(empty);
        }

        const all = document.createElement('a');
        all.className = 'site-search-suggestion-all';
        all.href = '/Anabelka/search?q=' + encodeURIComponent(query);
        all.textContent = labels.all;
        all.setAttribute('role', 'option');
        panel.appendChild(all);

        refreshSelectableItems();
        openPanel();
    };

    const loadSuggestions = function () {
        const query = input.value.trim();

        if ([...query].length < 2) {
            closePanel();
            return;
        }

        if (controller) {
            controller.abort();
        }

        controller = new AbortController();

        fetch(endpoint + '?q=' + encodeURIComponent(query), {
            method: 'GET',
            headers: {
                'Accept': 'application/json'
            },
            signal: controller.signal,
            credentials: 'same-origin'
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Search suggestion request failed');
                }
                return response.json();
            })
            .then(function (data) {
                if (!data || data.success !== true) {
                    closePanel();
                    return;
                }

                render(data, query);
            })
            .catch(function (error) {
                if (error && error.name === 'AbortError') {
                    return;
                }
                closePanel();
            });
    };

    const setActive = function (index) {
        if (items.length === 0) {
            return;
        }

        items.forEach(function (item) {
            item.classList.remove('is-active');
        });

        if (index < 0) {
            activeIndex = -1;
            return;
        }

        activeIndex = index % items.length;
        if (activeIndex < 0) {
            activeIndex = items.length - 1;
        }

        const activeItem = items[activeIndex];
        activeItem.classList.add('is-active');
        activeItem.scrollIntoView({ block: 'nearest' });
    };

    input.addEventListener('input', function () {
        window.clearTimeout(timer);

        const query = input.value.trim();
        if ([...query].length < 2) {
            closePanel();
            return;
        }

        timer = window.setTimeout(loadSuggestions, 260);
    });

    input.addEventListener('focus', function () {
        const query = input.value.trim();
        if ([...query].length >= 2 && lastRenderedQuery === query && panel.childElementCount > 0) {
            openPanel();
        }
    });

    input.addEventListener('keydown', function (event) {
        if (panel.hidden) {
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActive(activeIndex + 1);
            return;
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActive(activeIndex - 1);
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            closePanel();
            return;
        }

        if (event.key === 'Enter' && activeIndex >= 0 && items[activeIndex]) {
            event.preventDefault();
            items[activeIndex].click();
        }
    });

    document.addEventListener('click', function (event) {
        if (!form.contains(event.target)) {
            closePanel();
        }
    });

    form.addEventListener('submit', function () {
        closePanel();
    });
})();
