/*
 * ИИ-перевод для редактора Delivery.
 *
 * ИИ только предлагает перевод. Сохранение выполняется существующей
 * кнопкой формы Delivery, поэтому ручной режим остаётся независимым.
 */
(function () {
    'use strict';

    function showMessage(text)
    {
        if (typeof window.showMessage === 'function') {
            window.showMessage(text);
            return;
        }

        const message = document.getElementById('site-message');

        if (!message) {
            window.alert(text);
            return;
        }

        message.textContent = text;
        message.classList.add('show');

        clearTimeout(window.deliveryAiMessageTimer);
        window.deliveryAiMessageTimer = setTimeout(function () {
            message.classList.remove('show');
        }, 3000);
    }


    function ensureStyles()
    {
        if (document.getElementById('delivery-ai-translation-styles')) {
            return;
        }

        const style = document.createElement('style');
        style.id = 'delivery-ai-translation-styles';
        style.textContent = [
            '.delivery-ai-language-head {',
            '  display:flex;',
            '  align-items:center;',
            '  justify-content:space-between;',
            '  gap:10px;',
            '  flex-wrap:wrap;',
            '}',
            '.delivery-ai-translate {',
            '  border:1px solid var(--primary-color);',
            '  border-radius:9px;',
            '  padding:7px 10px;',
            '  background:#fff;',
            '  color:var(--primary-color);',
            '  font-size:13px;',
            '  font-weight:700;',
            '  cursor:pointer;',
            '}',
            '.delivery-ai-translate:disabled {',
            '  opacity:.55;',
            '  cursor:wait;',
            '}'
        ].join('\n');

        document.head.appendChild(style);
    }


    function prepareHead(section, marker)
    {
        const head = section.firstElementChild;

        if (!head || head.querySelector(marker)) {
            return null;
        }

        const titleText = (head.textContent || '').trim();
        const title = document.createElement('span');
        title.textContent = titleText;

        head.textContent = '';
        head.classList.add('delivery-ai-language-head');
        head.appendChild(title);

        return head;
    }


    function createEntityButton(section)
    {
        const languageCode =
            (section.dataset.languageCode || '').trim();

        if (!languageCode) {
            return;
        }

        const head = prepareHead(
            section,
            '[data-delivery-ai-translate]'
        );

        if (!head) {
            return;
        }

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'delivery-ai-translate';
        button.dataset.deliveryAiTranslate = '1';
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

            const sourceName = document.getElementById('edit-name');
            const sourceDescription =
                document.getElementById('edit-description');
            const editType = document.getElementById('edit-type');

            const translationName = section.querySelector(
                '.delivery-translation-name'
            );
            const translationDescription = section.querySelector(
                '.delivery-translation-description'
            );

            const type = editType ? editType.value : '';
            const context = type
                ? 'delivery_' + type
                : 'delivery';

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
                        context: context
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
                    error && error.message
                        ? error.message
                        : 'Не удалось получить ИИ-перевод.'
                );
            } finally {
                button.disabled = false;
                button.textContent = originalText;
            }
        });

        head.appendChild(button);
    }


    function createOptionInputButton(section)
    {
        const languageCode =
            (section.dataset.languageCode || '').trim();

        if (!languageCode) {
            return;
        }

        const head = prepareHead(
            section,
            '[data-delivery-option-input-ai-translate]'
        );

        if (!head) {
            return;
        }

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'delivery-ai-translate';
        button.dataset.deliveryOptionInputAiTranslate = '1';
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

            const sourceLabel = document.getElementById(
                'edit-option-customer-input-label'
            );
            const sourcePlaceholder = document.getElementById(
                'edit-option-customer-input-placeholder'
            );

            const translationLabel = section.querySelector(
                '.delivery-option-input-translation-label'
            );
            const translationPlaceholder = section.querySelector(
                '.delivery-option-input-translation-placeholder'
            );

            const originalText = button.textContent;
            button.disabled = true;
            button.textContent = 'Перевод…';

            try {
                /*
                 * Универсальный сервис переводит пары name/description.
                 * Для поля покупателя используем их как label/placeholder.
                 */
                const translation =
                    await window.AnabelkaAITranslation.suggest({
                        targetLanguage: languageCode,
                        name: sourceLabel ? sourceLabel.value : '',
                        description: sourcePlaceholder
                            ? sourcePlaceholder.value
                            : '',
                        context: 'delivery_option_input'
                    });

                if (translationLabel) {
                    translationLabel.value = translation.name || '';
                }

                if (translationPlaceholder) {
                    translationPlaceholder.value =
                        translation.description || '';
                }

                showMessage(
                    'ИИ-перевод поля получен. Проверьте его и нажмите «Сохранить».'
                );

            } catch (error) {
                showMessage(
                    error && error.message
                        ? error.message
                        : 'Не удалось получить ИИ-перевод поля.'
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
        const entitySections = document.querySelectorAll(
            '.delivery-translation-section'
        );
        const optionInputSections = document.querySelectorAll(
            '.delivery-option-input-translation-section'
        );

        if (!entitySections.length && !optionInputSections.length) {
            return;
        }

        ensureStyles();
        entitySections.forEach(createEntityButton);
        optionInputSections.forEach(createOptionInputButton);
    }


    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
