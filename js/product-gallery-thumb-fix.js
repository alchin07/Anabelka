(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        const thumb = event.target.closest('[data-product-gallery-thumb]');

        if (!thumb) {
            return;
        }

        const source = String(thumb.dataset.imageSrc || '').trim();
        const main = document.querySelector('[data-product-gallery-main]');
        const thumbs = document.querySelectorAll('[data-product-gallery-thumb]');

        if (!main || source === '') {
            return;
        }

        main.src = source;

        thumbs.forEach(function (item) {
            const selected = item === thumb;
            item.classList.toggle('is-active', selected);
            item.setAttribute('aria-pressed', selected ? 'true' : 'false');
        });
    }, true);
})();
