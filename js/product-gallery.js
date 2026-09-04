(function () {
    'use strict';

    document.querySelectorAll('[data-product-gallery]').forEach(function (gallery) {
        const main = gallery.querySelector('[data-product-gallery-main]');

        if (!main) {
            return;
        }

        gallery.querySelectorAll('[data-product-gallery-thumb]').forEach(function (button) {
            button.addEventListener('click', function () {
                const source = String(button.dataset.imageSrc || '');

                if (!source) {
                    return;
                }

                main.src = source;

                gallery.querySelectorAll('[data-product-gallery-thumb]').forEach(function (item) {
                    const active = item === button;
                    item.classList.toggle('is-active', active);
                    item.setAttribute('aria-pressed', active ? 'true' : 'false');
                });
            });
        });
    });
})();
