(function () {
    'use strict';

    if (!document.querySelector('link[data-admin-rank-translations]')) {
        const stylesheet = document.createElement('link');
        stylesheet.rel = 'stylesheet';
        stylesheet.href = '/Anabelka/css/admin-rank-translations.css?v=1';
        stylesheet.dataset.adminRankTranslations = '1';
        document.head.appendChild(stylesheet);
    }

    function showMessage(text, type = 'info')
    {
        const notify = window.AnabelkaNotify;

        return notify && typeof notify.show === 'function'
            ? notify.show(type, text)
            : false;
    }


    function setWorkflow(block, source, status)
    {
        const sourceField = block.querySelector('[data-rank-translation-source]');
        const sourceLabel = block.querySelector('[data-rank-translation-origin]');
        const statusField = block.querySelector('[data-rank-translation-status]');

        if (sourceField) {
            sourceField.value = source === 'ai' ? 'ai' : 'manual';
        }

        if (sourceLabel) {
            sourceLabel.textContent = source === 'ai'
                ? 'Створено ШІ'
                : 'Ручний переклад';
        }

        if (statusField && status) {
            statusField.value = status;
        }
    }


    document.addEventListener('click', async function (event) {
        const button = event.target.closest('[data-rank-ai-translate]');

        if (!button) {
            return;
        }

        const card = button.closest('.admin-rank-card');
        const block = button.closest('[data-rank-translation]');
        const sourceInput = card
            ? card.querySelector('.admin-rank-source-name')
            : null;
        const targetInput = block
            ? block.querySelector('[data-rank-translation-name]')
            : null;

        if (!card || !block || !sourceInput || !targetInput) {
            return;
        }

        const sourceName = String(sourceInput.value || '').trim();

        if (!sourceName) {
            showMessage('Спочатку вкажіть українську назву рангу.', 'warning');
            return;
        }

        if (
            !window.AnabelkaAITranslation
            || typeof window.AnabelkaAITranslation.suggest !== 'function'
        ) {
            showMessage('Система ШІ-перекладу ще завантажується. Спробуйте ще раз.', 'warning');
            return;
        }

        const originalText = button.textContent;
        button.disabled = true;
        button.textContent = 'Переклад…';

        try {
            const translation = await window.AnabelkaAITranslation.suggest({
                targetLanguage: button.dataset.targetLanguage || '',
                name: sourceName,
                description: '',
                context: 'user_rank'
            });

            targetInput.value = String(translation.name || '').trim();
            setWorkflow(block, 'ai', 'draft');
            showMessage('ШІ-переклад отримано. Перевірте назву та натисніть «Зберегти».', 'info');
        } catch (error) {
            showMessage(error && error.message
                ? error.message
                : 'Не вдалося перекласти назву рангу.', 'error');
        } finally {
            button.disabled = false;
            button.textContent = originalText;
        }
    });


    document.addEventListener('input', function (event) {
        const input = event.target.closest('[data-rank-translation-name]');

        if (!input) {
            return;
        }

        const block = input.closest('[data-rank-translation]');

        if (!block) {
            return;
        }

        const status = block.querySelector('[data-rank-translation-status]');
        const hasContent = String(input.value || '').trim() !== '';

        setWorkflow(
            block,
            'manual',
            hasContent && status && status.value === 'draft'
                ? 'approved'
                : (status ? status.value : 'approved')
        );
    });
})();
