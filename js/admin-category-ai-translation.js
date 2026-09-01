/*
 * ИИ-перевод для редактора категорий.
 *
 * Украинский текст остаётся исходным. ИИ только предлагает перевод
 * для выбранного языка; сохранение выполняется обычной кнопкой формы.
 */
(function () {
    'use strict';

    function showMessage(text)
    {
        const message = document.getElementById('site-message');

        if (!message) {
            window.alert(text);
            return;
        }

        message.textContent = text;
        message.classList.add('show');

        clearTimeout(window.categoryAiMessageTimer);
        window.categoryAiMessageTimer = setTimeout(function () {
            message.classList.remove('show');
        }, 3000);
    }


    function ensureStyles()
    {
        if (document.getElementById('category-ai-translation-styles')) {
            return;
        }

        const style = document.createElement('style');
        style.id = 'category-ai-translation-styles';
        style.textContent = [
            '.category-language-head {',
            '  display:flex;',
            '  align-items:center;',
            '  justify-content:space-between;',
            '  gap:10px;',
            '  flex-wrap:wrap;',
            '}',
            '.category-ai-translate {',
            '  border:1px solid var(--primary-color);',
            '  border-radius:9px;',
            '  padding:7px 10px;',
            '  background:#fff;',
            '  color:var(--primary-color);',
            '  font-size:13px;',
            '  font-weight:700;',
            '  cursor:pointer;',
            '}',
            '.category-ai-translate:disabled {',
            '  opacity:.55;',
            '  cursor:wait;',
            '}'
        ].join('\n');

        document.head.appendChild(style);
    }


    function createButton(section)
    {
        const languageCode =
            (section.dataset.categoryLanguage || '').trim();

        if (!languageCode) {
            return;
        }

        const head = section.querySelector('.category-language-head');

        if (!head || head.querySelector('[data-category-ai-translate]')) {
            return;
        }

        const title = document.createElement('span');

        while (head.firstChild) {
            title.appendChild(head.firstChild);
        }

        head.appendChild(title);

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'category-ai-translate';
        button.dataset.categoryAiTranslate = '1';
        button.dataset.targetLanguage = languageCode;
        button.textContent = 'Перевести через ИИ';

        button.addEventListener('click', async function () {
            if (
                !window.AnabelkaAITranslation
                || typeof window.AnabelkaAITranslation.suggest !== 'function'
            ) {
                showMessage(
                    'Система ИИ-перевода ещё загружается. Попробуйте ещё раз.'
                );
                return;
            }

            const sourceName =
                document.getElementById('category-edit-name');
            const sourceDescription =
                document.getElementById('category-edit-description');
            const translationName =
                section.querySelector('.category-translation-name');
            const translationDescription =
                section.querySelector('.category-translation-description');

            const originalText = button.textContent;

            button.disabled = true;
            button.textContent = 'Перевод…';

            try {
                const translation =
                    await window.AnabelkaAITranslation.suggest({
                        targetLanguage: languageCode,
                        name: sourceName ? sourceName.value : '',
                        description: sourceDescription
                            ? sourceDescription.value
                            : '',
                        context: 'category'
                    });

                if (translationName) {
                    translationName.value = translation.name || '';
                }

                if (translationDescription) {
                    translationDescription.value =
                        translation.description || '';
                }

                showMessage(
                    'ИИ-перевод получен. Проверьте его и нажмите «Сохранить».'
                );

            } catch (error) {
                showMessage(
                    error.message || 'Не удалось получить ИИ-перевод.'
                );
            } finally {
                button.disabled = false;
                button.textContent = originalText;
            }
        });

        head.appendChild(button);
    }


    function init()
    {
        const sections =
            document.querySelectorAll('[data-category-language]');

        if (!sections.length) {
            return;
        }

        ensureStyles();
        sections.forEach(createButton);
    }


    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
