/*
 * Редактор одного текстового ключа інтерфейсу.
 * ШІ лише заповнює поле; остаточне збереження завжди ручне.
 */
(function () {
    'use strict';

    const form = document.getElementById(
        'interface-translation-form'
    );

    if (!form) {
        return;
    }

    const sourceField = document.getElementById(
        'interface-source-value'
    );

    const message = document.getElementById('site-message');
    const saveButton = form.querySelector('.interface-editor-save');


    function showMessage(text)
    {
        if (!message) {
            window.alert(text);
            return;
        }

        message.textContent = text;
        message.classList.add('show');

        clearTimeout(window.interfaceTranslationMessageTimer);
        window.interfaceTranslationMessageTimer = setTimeout(
            function () {
                message.classList.remove('show');
            },
            3500
        );
    }


    function findLanguageSection(languageCode)
    {
        const normalized = String(languageCode || '')
            .trim()
            .toLowerCase();

        return Array.from(
            document.querySelectorAll('[data-interface-language]')
        ).find(function (section) {
            return String(
                section.dataset.interfaceLanguage || ''
            ).trim().toLowerCase() === normalized;
        }) || null;
    }


    function focusRequestedLanguage()
    {
        const section = findLanguageSection(
            form.dataset.focusLanguage || ''
        );

        if (!section) {
            return;
        }

        const field = section.querySelector(
            '.interface-translation-value'
        );

        if (!field) {
            return;
        }

        section.classList.add('is-focus-target');
        field.focus();
        field.select();

        window.setTimeout(function () {
            field.scrollIntoView({
                block: 'center'
            });
        }, 120);
    }


    function getPlaceholders(text)
    {
        const matches = String(text || '').match(
            /\{[a-zA-Z0-9_.-]+\}/g
        ) || [];

        return matches.sort();
    }


    function preservesPlaceholders(source, translation)
    {
        return JSON.stringify(getPlaceholders(source))
            === JSON.stringify(getPlaceholders(translation));
    }


    function updateLanguageState(field)
    {
        const section = field.closest('[data-interface-language]');

        if (!section) {
            return;
        }

        const isMissing = field.value.trim() === '';
        let note = section.querySelector('.interface-missing-note');

        section.classList.toggle('is-missing', isMissing);

        if (isMissing && !note) {
            note = document.createElement('span');
            note.className = 'interface-missing-note';
            note.textContent = 'Переклад відсутній';
            section.appendChild(note);
        }

        if (note) {
            note.hidden = !isMissing;
        }
    }


    document
        .querySelectorAll('.interface-translation-value')
        .forEach(function (field) {
            field.addEventListener('input', function () {
                updateLanguageState(field);
            });
        });


    document
        .querySelectorAll('[data-interface-ai-translate]')
        .forEach(function (button) {
            button.addEventListener('click', async function () {
                const section = button.closest(
                    '[data-interface-language]'
                );

                const targetField = section
                    ? section.querySelector(
                        '.interface-translation-value'
                    )
                    : null;

                if (!sourceField || !targetField) {
                    return;
                }

                if (
                    !window.AnabelkaAITranslation
                    || typeof window.AnabelkaAITranslation.suggest
                        !== 'function'
                ) {
                    showMessage(
                        'Система ШІ-перекладу ще завантажується. '
                        + 'Спробуйте ще раз.'
                    );
                    return;
                }

                const originalText = button.textContent;
                const keyField = form.elements.translation_key;

                button.disabled = true;
                button.textContent = 'Переклад…';

                try {
                    const translation =
                        await window.AnabelkaAITranslation.suggest({
                            targetLanguage:
                                button.dataset.targetLanguage || '',
                            name: sourceField.value,
                            description: '',
                            context:
                                'interface text; preserve placeholders '
                                + 'in braces exactly; key:'
                                + (keyField ? keyField.value : '')
                        });

                    const translatedValue =
                        translation.name
                        || translation.description
                        || '';

                    if (translatedValue.trim() === '') {
                        throw new Error(
                            'ШІ не повернув текст перекладу.'
                        );
                    }

                    if (!preservesPlaceholders(
                        sourceField.value,
                        translatedValue
                    )) {
                        throw new Error(
                            'ШІ змінив службову вставку в дужках. '
                            + 'Переклад не застосовано.'
                        );
                    }

                    targetField.value = translatedValue;
                    updateLanguageState(targetField);

                    targetField.focus();
                    targetField.select();

                    showMessage(
                        'ШІ-переклад отримано. Перевірте його '
                        + 'та натисніть «Зберегти».'
                    );

                } catch (error) {
                    showMessage(
                        error instanceof Error
                            ? error.message
                            : 'Не вдалося отримати ШІ-переклад.'
                    );
                } finally {
                    button.disabled = false;
                    button.textContent = originalText;
                }
            });
        });


    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        if (saveButton) {
            saveButton.disabled = true;
            saveButton.textContent = 'Збереження…';
        }

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(
                    data.message
                    || 'Не вдалося зберегти переклади.'
                );
            }

            showMessage(data.message || 'Збережено.');

            window.setTimeout(function () {
                window.location.href = data.return_url
                    || '/Anabelka/admin/translations/missing?section=interface';
            }, 450);

        } catch (error) {
            showMessage(
                error instanceof Error
                    ? error.message
                    : 'Не вдалося зберегти переклади.'
            );

            if (saveButton) {
                saveButton.disabled = false;
                saveButton.textContent = 'Зберегти';
            }
        }
    });


    window.setTimeout(focusRequestedLanguage, 80);
})();
