(function () {
    'use strict';

    const storageKey = 'anabelka-category-success-flash';
    const successDuration = 2800;
    const errorDuration = 4800;
    let messageTimer = null;

    function show(message, isError)
    {
        const element = document.getElementById('site-message');
        const text = String(message || '').trim();

        if (!element || text === '') {
            return;
        }

        element.textContent = text;
        element.classList.toggle('is-error', Boolean(isError));
        element.classList.add('show');
        window.clearTimeout(messageTimer);
        messageTimer = window.setTimeout(function () {
            element.classList.remove('show');
        }, isError ? errorDuration : successDuration);
    }

    function storeSuccess(message)
    {
        const text = String(message || 'Збережено.').trim();

        try {
            window.sessionStorage.setItem(storageKey, text || 'Збережено.');
        } catch (error) {
            // Navigation must still complete when browser storage is disabled.
        }
    }

    function consumeSuccess()
    {
        let message = '';

        try {
            message = window.sessionStorage.getItem(storageKey) || '';
            window.sessionStorage.removeItem(storageKey);
        } catch (error) {
            return;
        }

        if (message !== '') {
            show(message, false);
        }
    }

    window.AdminFlashMessage = {
        show: show,
        storeSuccess: storeSuccess
    };

    consumeSuccess();
}());
