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

    function appendAccountScript(src, dataAttribute) {
        if (document.querySelector('script[' + dataAttribute + ']')) {
            return;
        }

        const script = document.createElement('script');
        script.src = src;
        script.setAttribute(dataAttribute, '1');
        document.body.appendChild(script);
    }

    function arrangeAccountPrimaryBlocks() {
        const accountGrid = document.querySelector('.account-grid');
        const addressSection = document.querySelector('.account-address-section');
        const notifications = document.querySelector('.account-notifications');

        if (!accountGrid || !addressSection) {
            return;
        }

        const cards = Array.from(accountGrid.querySelectorAll(':scope > .account-card'));
        const profileCard = cards.find(function (card) {
            return Boolean(card.querySelector('form[action="/Anabelka/account/profile"]'));
        });
        const passwordCard = cards.find(function (card) {
            return Boolean(card.querySelector('form[action="/Anabelka/account/password"]'));
        });

        if (!profileCard) {
            return;
        }

        profileCard.classList.add('account-primary-profile');
        addressSection.classList.add('account-primary-addresses');

        if (passwordCard) {
            passwordCard.classList.add('account-primary-password');
            accountGrid.insertBefore(addressSection, passwordCard);
        } else {
            profileCard.insertAdjacentElement('afterend', addressSection);
        }

        if (notifications) {
            notifications.insertAdjacentElement('afterend', accountGrid);
        }

        accountGrid.classList.add('account-grid-primary');

        if (!document.getElementById('account-primary-layout-style')) {
            const style = document.createElement('style');
            style.id = 'account-primary-layout-style';
            style.textContent = [
                '.account-grid-primary>.account-primary-addresses{margin-top:0}',
                '@media(min-width:701px){',
                '.account-grid-primary>.account-primary-profile{grid-column:1;grid-row:1}',
                '.account-grid-primary>.account-primary-password{grid-column:2;grid-row:1}',
                '.account-grid-primary>.account-primary-addresses{grid-column:1/-1;grid-row:2}',
                '}'
            ].join('');
            document.head.appendChild(style);
        }
    }

    function loadAccountModules() {
        if (window.location.pathname.replace(/\/$/, '') !== '/Anabelka/account') {
            return;
        }

        arrangeAccountPrimaryBlocks();

        appendAccountScript(
            '/Anabelka/js/account-email-verification.js?v=1',
            'data-account-email-verification'
        );
        appendAccountScript(
            '/Anabelka/js/account-password-visibility.js?v=1',
            'data-account-password-visibility'
        );
        appendAccountScript(
            '/Anabelka/js/account-social-connections.js?v=1',
            'data-account-social-connections'
        );
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
