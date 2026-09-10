(function () {
    'use strict';

    const page = document.querySelector('.account-page');

    if (!page) {
        return;
    }

    const language = (document.documentElement.lang || 'uk').toLowerCase();
    const labels = {
        uk: {
            show: 'Показати пароль',
            hide: 'Сховати пароль',
            hint: 'Щонайменше 10 символів'
        },
        ru: {
            show: 'Показать пароль',
            hide: 'Скрыть пароль',
            hint: 'Не менее 10 символов'
        },
        en: {
            show: 'Show password',
            hide: 'Hide password',
            hint: 'At least 10 characters'
        }
    };
    const text = labels[language] || labels.uk;

    function createEyeIcon() {
        return '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2">'
            + '<path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/>'
            + '<circle cx="12" cy="12" r="2.5"/>'
            + '</svg>';
    }

    page.querySelectorAll('input[type="password"]').forEach(function (input) {
        if (input.closest('.account-password-wrap')) {
            return;
        }

        const parent = input.parentNode;

        if (!parent) {
            return;
        }

        const wrapper = document.createElement('span');
        wrapper.className = 'account-password-wrap';
        parent.insertBefore(wrapper, input);
        wrapper.appendChild(input);

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'account-password-toggle';
        button.setAttribute('aria-label', text.show);
        button.setAttribute('aria-pressed', 'false');
        button.title = text.show;
        button.innerHTML = createEyeIcon();

        button.addEventListener('click', function () {
            const shouldShow = input.type === 'password';
            input.type = shouldShow ? 'text' : 'password';
            button.setAttribute('aria-pressed', shouldShow ? 'true' : 'false');
            button.setAttribute('aria-label', shouldShow ? text.hide : text.show);
            button.title = shouldShow ? text.hide : text.show;
        });

        wrapper.appendChild(button);
    });

    const newPassword = page.querySelector('input[name="new_password"]');
    const confirmation = page.querySelector('input[name="new_password_confirmation"]');

    if (newPassword) {
        newPassword.minLength = 10;
    }

    if (confirmation) {
        confirmation.minLength = 10;
    }

    if (newPassword) {
        const card = newPassword.closest('.account-card');
        const hint = card ? card.querySelector('.account-card-head p') : null;

        if (hint) {
            hint.textContent = text.hint;
        }
    }
})();
