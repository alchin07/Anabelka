(function () {
    'use strict';

    function show(message, isError)
    {
        if (
            !window.AnabelkaNotify
            || typeof window.AnabelkaNotify.show !== 'function'
        ) {
            return false;
        }

        return window.AnabelkaNotify.show(
            isError ? 'error' : 'success',
            message
        );
    }

    function storeSuccess(message)
    {
        const text = String(message || 'Збережено.').trim();

        if (
            !window.AnabelkaNotify
            || typeof window.AnabelkaNotify.store !== 'function'
        ) {
            return false;
        }

        return window.AnabelkaNotify.store(
            'success',
            text || 'Збережено.'
        );
    }

    window.AdminFlashMessage = {
        show: show,
        storeSuccess: storeSuccess
    };
}());
