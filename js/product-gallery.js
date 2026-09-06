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

    const popupTranslations = {
        'Выберите хотя бы один размер.': 'Оберіть хоча б один розмір.',
        'Ошибка PHP. Смотри текст ниже.': 'Помилка PHP. Дивіться текст нижче.',
        'Не удалось добавить товар в корзину.': 'Не вдалося додати товар до кошика.',
        '✓ Товар добавлен в корзину': '✓ Товар додано до кошика',
        'Не удалось добавить товар.': 'Не вдалося додати товар.'
    };

    if (typeof window.showMessage === 'function') {
        const originalShowMessage = window.showMessage;

        window.showMessage = function (text) {
            const normalizedText = String(text || '');
            const translatedText = popupTranslations[normalizedText] || normalizedText;
            return originalShowMessage(translatedText);
        };
    }
})();
