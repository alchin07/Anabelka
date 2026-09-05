(function () {
    'use strict';

    function updateProductLinks(card, colorKey)
    {
        const baseUrl = String(card.dataset.productUrl || '').trim();

        if (baseUrl === '') {
            return;
        }

        let nextUrl = baseUrl;

        if (colorKey) {
            nextUrl += '?color_key=' + encodeURIComponent(colorKey);
        }

        card.querySelectorAll('[data-product-color-link]').forEach(function (link) {
            link.href = nextUrl;
        });
    }


    document.querySelectorAll('[data-product-color-card]').forEach(function (card) {
        const image = card.querySelector('[data-product-card-image]');
        const swatches = Array.from(
            card.querySelectorAll('[data-product-color-swatch]')
        );

        if (swatches.length === 0) {
            return;
        }

        function selectSwatch(swatch)
        {
            const nextImage = String(
                swatch.dataset.imageSrc || ''
            ).trim();
            const colorKey = String(
                swatch.dataset.colorKey || ''
            ).trim();

            if (image && nextImage !== '') {
                image.src = nextImage;
            }

            swatches.forEach(function (item) {
                const isSelected = item === swatch;
                item.classList.toggle('is-active', isSelected);
                item.setAttribute(
                    'aria-pressed',
                    isSelected ? 'true' : 'false'
                );
            });

            updateProductLinks(card, colorKey);
        }

        swatches.forEach(function (swatch) {
            swatch.addEventListener('click', function () {
                selectSwatch(swatch);
            });
        });

        const initiallySelected = card.querySelector(
            '[data-product-color-swatch].is-active'
        );

        if (initiallySelected) {
            selectSwatch(initiallySelected);
        }
    });


    function highlightRequestedProduct()
    {
        const params = new URLSearchParams(window.location.search);
        const slug = String(params.get('highlight_product') || '').trim();

        if (slug === '') {
            return;
        }

        const expectedPath = '/Anabelka/product/' + encodeURIComponent(slug);
        const cards = Array.from(document.querySelectorAll('.product-card'));
        const card = cards.find(function (item) {
            return Array.from(item.querySelectorAll('a[href]')).some(function (link) {
                try {
                    return new URL(link.href, window.location.origin).pathname
                        === expectedPath;
                } catch (error) {
                    return false;
                }
            });
        });

        if (!card) {
            return;
        }

        card.classList.add('is-admin-highlight');
        card.style.outline = '3px solid var(--primary-color)';
        card.style.outlineOffset = '4px';
        card.style.boxShadow = '0 10px 28px rgba(138, 43, 226, 0.22)';
        card.style.scrollMargin = '110px';

        window.setTimeout(function () {
            card.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
        }, 120);
    }


    highlightRequestedProduct();
})();
