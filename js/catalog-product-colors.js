(function () {
    'use strict';

    document.querySelectorAll('[data-product-color-card]').forEach(function (card) {
        const image = card.querySelector('[data-product-card-image]');
        const swatches = Array.from(
            card.querySelectorAll('[data-product-color-swatch]')
        );

        if (!image || swatches.length === 0) {
            return;
        }

        swatches.forEach(function (swatch) {
            swatch.addEventListener('click', function () {
                const nextImage = String(
                    swatch.dataset.imageSrc || ''
                ).trim();

                if (nextImage === '') {
                    return;
                }

                image.src = nextImage;

                swatches.forEach(function (item) {
                    const isSelected = item === swatch;
                    item.classList.toggle('is-active', isSelected);
                    item.setAttribute(
                        'aria-pressed',
                        isSelected ? 'true' : 'false'
                    );
                });
            });
        });
    });
})();
