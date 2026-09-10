(function () {
    'use strict';

    const selector = 'input, textarea, select';

    function isFormField(element) {
        return element instanceof HTMLElement && element.matches(selector);
    }

    function scrollFieldIntoView(element, delay) {
        window.setTimeout(function () {
            if (!isFormField(element) || document.activeElement !== element) {
                return;
            }

            element.scrollIntoView({
                behavior: 'smooth',
                block: 'center',
                inline: 'nearest'
            });
        }, delay);
    }

    function keepActiveFieldVisible() {
        const active = document.activeElement;

        if (!isFormField(active)) {
            return;
        }

        scrollFieldIntoView(active, 80);
        scrollFieldIntoView(active, 280);
    }

    function loadAccountModules() {
        if (window.location.pathname.replace(/\/$/, '') !== '/Anabelka/account') {
            return;
        }

        if (document.querySelector('script[data-account-email-verification]')) {
            return;
        }

        const script = document.createElement('script');
        script.src = '/Anabelka/js/account-email-verification.js?v=1';
        script.dataset.accountEmailVerification = '1';
        document.body.appendChild(script);
    }

    document.addEventListener('focusin', function (event) {
        if (!isFormField(event.target)) {
            return;
        }

        scrollFieldIntoView(event.target, 120);
        scrollFieldIntoView(event.target, 360);
    });

    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', keepActiveFieldVisible);
        window.visualViewport.addEventListener('scroll', keepActiveFieldVisible);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', loadAccountModules);
    } else {
        loadAccountModules();
    }
})();
