/*
 * Оформлення списку категорій за правилами Анабельки.
 *
 * У картці показуємо лише назву та короткий опис.
 * Slug залишається технічним полем і в списку не виводиться.
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


    function ensureHighlightStyles()
    {
        if (document.getElementById('category-highlight-styles')) {
            return;
        }

        const style = document.createElement('style');
        style.id = 'category-highlight-styles';
        style.textContent = [
            '.category-admin-row.is-translation-target .category-admin-card {',
            '  box-shadow: 0 0 0 3px var(--primary-color), 0 8px 24px rgba(138,43,226,.18);',
            '}',
            '.category-admin-row.is-translation-target .category-edit-button {',
            '  box-shadow: 0 0 0 3px var(--primary-color);',
            '}',
            '.category-admin-row.is-translation-target {',
            '  scroll-margin-top: 120px;',
            '}'
        ].join('\n');

        document.head.appendChild(style);
    }


    function highlightRequestedCategory()
    {
        const params = new URLSearchParams(window.location.search);
        const categoryId = String(params.get('highlight') || '').trim();

        if (!/^\d+$/.test(categoryId)) {
            return;
        }

        const button = document.querySelector(
            '.category-edit-button[data-category-id="' + categoryId + '"]'
        );

        const row = button
            ? button.closest('.category-admin-row')
            : null;

        if (!row) {
            return;
        }

        ensureHighlightStyles();
        row.classList.add('is-translation-target');

        window.setTimeout(function () {
            row.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
        }, 120);
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

        highlightRequestedCategory();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
