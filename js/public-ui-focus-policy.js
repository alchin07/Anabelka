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
})();
