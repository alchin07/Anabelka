/*
 * Оформление списка категорий по правилам Анабельки.
 *
 * В карточке показываем только название и краткое описание.
 * Slug остаётся техническим полем и в списке не выводится.
 */
(function () {
    'use strict';

    const DESCRIPTION_LIMIT = 80;

    function shorten(text, limit)
    {
        const normalized = String(text || '')
            .replace(/\s+/g, ' ')
            .trim();

        const characters = Array.from(normalized);

        if (characters.length <= limit) {
            return normalized;
        }

        return characters.slice(0, limit).join('').trimEnd() + '…';
    }

    function init()
    {
        document.querySelectorAll('.category-admin-row').forEach(function (row) {
            const slug = row.querySelector('.category-admin-slug');

            if (slug) {
                slug.remove();
            }

            const description = row.querySelector('.category-admin-description');

            if (!description) {
                return;
            }

            const fullText = String(description.textContent || '')
                .replace(/\s+/g, ' ')
                .trim();

            if (!fullText) {
                description.remove();
                return;
            }

            description.textContent = shorten(fullText, DESCRIPTION_LIMIT);

            if (Array.from(fullText).length > DESCRIPTION_LIMIT) {
                description.title = fullText;
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
