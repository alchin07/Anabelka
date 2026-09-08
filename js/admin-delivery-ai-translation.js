/*
 * ШІ-переклад для редактора Delivery.
 *
 * ШІ лише пропонує переклад. Збереження виконується існуючою
 * кнопкою форми Delivery, тому ручний режим залишається незалежним.
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
            '}',
            '.delivery-translation-status-label.delivery-translation-status-below {',
            '  display:grid;',
            '  grid-template-columns:minmax(0,1fr) minmax(150px,46%);',
            '  align-items:center;',
            '  gap:12px;',
            '  margin:2px 0 4px;',
            '  padding:11px 12px;',
            '  border:1px solid var(--border-color);',
            '  border-radius:12px;',
            '  background:#faf7ff;',
            '  color:var(--primary-color);',
            '  font-size:13px;',
            '  font-weight:800;',
            '}',
            '.delivery-translation-status-label.delivery-translation-status-below > span {',
            '  color:var(--primary-color);',
            '  font-size:13px;',
            '  font-weight:800;',
            '}',
            '.delivery-translation-status-label.delivery-translation-status-below .delivery-translation-status {',
            '  width:100%;',
            '  min-height:42px;',
            '  box-sizing:border-box;',
            '  padding:0 11px;',
            '  border:1px solid var(--border-color);',
            '  border-radius:10px;',
            '  background:#fff;',
            '  color:var(--text-color);',
            '  font-size:13px;',
            '  font-weight:800;',
            '}',
            '@media (max-width:380px) {',
            '  .delivery-translation-status-label.delivery-translation-status-below {',
            '    grid-template-columns:minmax(0,1fr) minmax(135px,48%);',
            '    gap:8px;',
            '    padding:10px;',
            '  }',
            '}'
        ].join('\n');

        document.head.appendChild(style);
    }


    function moveEntityStatusBelowFields(section)
    {
        const status = section.querySelector(
            '.delivery-translation-status-label'
        );
        const description = section.querySelector(
            '.delivery-translation-description'
        );
        const descriptionGroup = description
            ? description.closest('.delivery-form-group')
            : null;

        if (!status || !descriptionGroup) {
            return;
        }

        const statusTitle = status.querySelector('span');

        if (statusTitle) {
            statusTitle.textContent = 'Стан перекладу';
        }

        status.classList.add('delivery-translation-status-below');
        descriptionGroup.insertAdjacentElement('afterend', status);
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
        button.textContent = 'Перекласти через ШІ';

        button.addEventListener('click', async function () {
            if (
                !window.AnabelkaAITranslation
                || typeof window.AnabelkaAITranslation.suggest !== 'function'
            ) {
                showMessage(
                    'Система ШІ-перекладу ще завантажується. Спробуйте ще раз.'
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
            button.textContent = 'Переклад…';

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

                if (
                    typeof window.setDeliveryTranslationWorkflow
                        === 'function'
                ) {
                    window.setDeliveryTranslationWorkflow(
                        section,
                        'ai',
                        'draft'
                    );
                }

                showMessage(
                    'ШІ-переклад отримано. Перевірте його та натисніть «Зберегти».'
                );

            } catch (error) {
                showMessage(
                    error && error.message
                        ? error.message
                        : 'Не вдалося отримати ШІ-переклад.'
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
        button.textContent = 'Перекласти через ШІ';

        button.addEventListener('click', async function () {
            if (
                !window.AnabelkaAITranslation
                || typeof window.AnabelkaAITranslation.suggest !== 'function'
            ) {
                showMessage(
                    'Система ШІ-перекладу ще завантажується. Спробуйте ще раз.'
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
            button.textContent = 'Переклад…';

            try {
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

                if (
                    typeof window.setDeliveryTranslationWorkflow
                        === 'function'
                ) {
                    window.setDeliveryTranslationWorkflow(
                        section,
                        'ai',
                        'draft'
                    );
                }

                showMessage(
                    'ШІ-переклад поля отримано. Перевірте його та натисніть «Зберегти».'
                );

            } catch (error) {
                showMessage(
                    error && error.message
                        ? error.message
                        : 'Не вдалося отримати ШІ-переклад поля.'
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
        entitySections.forEach(function (section) {
            moveEntityStatusBelowFields(section);
            createEntityButton(section);
        });
        optionInputSections.forEach(createOptionInputButton);
    }


    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
