(function () {
    'use strict';

    function notify(type, message) {
        if (
            window.AnabelkaNotify
            && typeof window.AnabelkaNotify[type] === 'function'
        ) {
            window.AnabelkaNotify[type](message);
            return;
        }

        const siteMessage = document.getElementById('site-message');

        if (siteMessage) {
            siteMessage.textContent = message;
            siteMessage.classList.add('show');

            window.setTimeout(function () {
                siteMessage.classList.remove('show');
            }, 3200);
            return;
        }

        window.alert(message);
    }

    function setWorkflow(fieldset, sourceValue, statusValue) {
        const source = fieldset.querySelector(
            '[data-mobile-navigation-translation-source]'
        );
        const status = fieldset.querySelector(
            '[data-mobile-navigation-translation-status]'
        );

        if (source) {
            source.value = sourceValue;
        }

        if (
            status
            && Array.from(status.options || []).some(function (option) {
                return option.value === statusValue;
            })
        ) {
            status.value = statusValue;
        }
    }

    function bindFieldset(fieldset) {
        const button = fieldset.querySelector(
            '[data-mobile-navigation-ai-translate]'
        );
        const translationName = fieldset.querySelector(
            '[data-mobile-navigation-translation-name]'
        );
        const source = fieldset.querySelector(
            '[data-mobile-navigation-translation-source]'
        );

        if (translationName && source) {
            translationName.addEventListener('input', function () {
                source.value = 'manual';
            });
        }

        if (!button || !translationName) {
            return;
        }

        button.addEventListener('click', async function () {
            if (
                !window.AnabelkaAITranslation
                || typeof window.AnabelkaAITranslation.suggest !== 'function'
            ) {
                notify(
                    'warning',
                    'Плаваючий ШІ-перекладач ще завантажується. Спробуйте ще раз.'
                );
                return;
            }

            const form = fieldset.closest('form');
            const sourceName = form
                ? form.querySelector('input[name="name_uk"]')
                : null;
            const targetLanguage = String(
                button.dataset.targetLanguage
                || fieldset.dataset.mobileNavigationLanguage
                || ''
            ).trim();

            if (!sourceName || !sourceName.value.trim()) {
                notify(
                    'warning',
                    'Спочатку вкажіть українську назву пункту.'
                );
                sourceName?.focus();
                return;
            }

            if (!targetLanguage) {
                notify('error', 'Не вдалося визначити мову перекладу.');
                return;
            }

            const originalText = button.textContent;
            button.disabled = true;
            button.textContent = 'Переклад…';

            try {
                const translation = await window.AnabelkaAITranslation.suggest({
                    targetLanguage: targetLanguage,
                    name: sourceName.value,
                    description: '',
                    context: 'mobile_navigation'
                });

                translationName.value = translation.name || '';
                setWorkflow(fieldset, 'ai', 'draft');
                translationName.focus();

                notify(
                    'success',
                    'ШІ-переклад отримано. Перевірте текст і статус перед збереженням.'
                );
            } catch (error) {
                notify(
                    'error',
                    error && error.message
                        ? error.message
                        : 'Не вдалося отримати ШІ-переклад.'
                );
            } finally {
                button.disabled = false;
                button.textContent = originalText;
            }
        });
    }

    function syncFloatingPanelVisibility() {
        const floatingRoot = document.getElementById('admin-ai-top-slot');

        if (!floatingRoot) {
            return;
        }

        const hasOpenEditor = Array.from(
            document.querySelectorAll('.mobile-navigation-editor')
        ).some(function (editor) {
            return Boolean(editor.open);
        });

        floatingRoot.classList.toggle(
            'is-mobile-navigation-ai-inactive',
            !hasOpenEditor
        );
        floatingRoot.setAttribute(
            'aria-hidden',
            hasOpenEditor ? 'false' : 'true'
        );
    }

    function init() {
        document
            .querySelectorAll('[data-mobile-navigation-language]')
            .forEach(bindFieldset);

        document
            .querySelectorAll('.mobile-navigation-editor')
            .forEach(function (editor) {
                editor.addEventListener(
                    'toggle',
                    syncFloatingPanelVisibility
                );
            });

        syncFloatingPanelVisibility();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
