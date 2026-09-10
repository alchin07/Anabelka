(function () {
    'use strict';

    const form = document.querySelector('[data-register-form]');

    if (!form) {
        return;
    }

    const password = form.querySelector('input[name="password"]');
    const confirmation = form.querySelector('input[name="password_confirmation"]');
    const match = form.querySelector('[data-password-match]');
    const matchText = form.dataset.passwordsMatch || 'Паролі збігаються.';
    const mismatchText = form.dataset.passwordsMismatch || 'Паролі не збігаються.';
    const showText = form.dataset.showPassword || 'Показати пароль';
    const hideText = form.dataset.hidePassword || 'Сховати пароль';

    function validateMatch()
    {
        if (!password || !confirmation || !match) {
            return true;
        }

        if (confirmation.value === '') {
            confirmation.setCustomValidity('');
            match.textContent = '';
            match.className = 'auth-password-match';
            return true;
        }

        const equal = password.value === confirmation.value;
        confirmation.setCustomValidity(equal ? '' : mismatchText);
        match.textContent = equal ? matchText : mismatchText;
        match.className = 'auth-password-match ' + (equal ? 'is-ok' : 'is-error');
        return equal;
    }

    [password, confirmation].forEach(function (field) {
        if (field) {
            field.addEventListener('input', validateMatch);
        }
    });

    form.querySelectorAll('[data-password-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            const targetName = button.dataset.passwordToggle || '';
            const field = form.querySelector('input[name="' + targetName + '"]');

            if (!field) {
                return;
            }

            const show = field.type === 'password';
            field.type = show ? 'text' : 'password';
            button.setAttribute('aria-pressed', show ? 'true' : 'false');
            button.setAttribute('aria-label', show ? hideText : showText);
            button.title = show ? hideText : showText;
        });
    });

    form.addEventListener('submit', function (event) {
        if (!validateMatch()) {
            event.preventDefault();
            confirmation.reportValidity();
        }
    });
})();
