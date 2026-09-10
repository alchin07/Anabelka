(function () {
    'use strict';

    if (window.location.pathname.replace(/\/$/, '') !== '/Anabelka/account') {
        return;
    }

    function ensureStyles()
    {
        if (document.querySelector('link[data-account-email-verification]')) {
            return;
        }

        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = '/Anabelka/css/account-email-verification.css?v=1';
        link.dataset.accountEmailVerification = '1';
        document.head.appendChild(link);
    }


    function csrfToken()
    {
        const field = document.querySelector('input[name="_csrf"]');
        return field ? String(field.value || '') : '';
    }


    function element(tag, className, text)
    {
        const node = document.createElement(tag);

        if (className) {
            node.className = className;
        }

        if (text !== undefined && text !== null) {
            node.textContent = String(text);
        }

        return node;
    }


    function insertCard(card)
    {
        const notifications = document.querySelector('.account-notifications');
        const links = document.querySelector('.account-links');
        const hero = document.querySelector('.account-hero');

        if (notifications && notifications.parentNode) {
            notifications.parentNode.insertBefore(card, notifications);
            return;
        }

        if (links && links.parentNode) {
            links.parentNode.insertBefore(card, links);
            return;
        }

        if (hero && hero.parentNode) {
            hero.parentNode.insertBefore(card, hero.nextSibling);
        }
    }


    function renderPreview(card, url, labels)
    {
        let preview = card.querySelector('.account-email-verification-preview');

        if (!url) {
            if (preview) {
                preview.remove();
            }
            return;
        }

        if (!preview) {
            preview = element('div', 'account-email-verification-preview');
            card.appendChild(preview);
        }

        preview.innerHTML = '';

        const link = element(
            'a',
            'account-email-verification-preview-link',
            labels.local_link || 'Відкрити тестове посилання підтвердження'
        );
        link.href = url;
        preview.appendChild(link);

        preview.appendChild(element(
            'small',
            '',
            labels.local_hint || ''
        ));
    }


    function showMessage(card, text, isError)
    {
        let message = card.querySelector('.account-email-verification-message');

        if (!message) {
            message = element('div', 'account-email-verification-message');
            card.appendChild(message);
        }

        message.textContent = String(text || '');
        message.classList.toggle('is-error', !!isError);
    }


    function startCooldown(button, seconds, labels)
    {
        let remaining = Math.max(0, parseInt(seconds, 10) || 0);

        if (remaining <= 0) {
            button.disabled = false;
            return;
        }

        button.disabled = true;
        const originalText = button.dataset.defaultText || button.textContent;
        button.dataset.defaultText = originalText;

        function tick()
        {
            if (remaining <= 0) {
                button.disabled = false;
                button.textContent = originalText;
                return;
            }

            button.textContent = (labels.resend || originalText) + ' · ' + remaining + 'с';
            remaining--;
            window.setTimeout(tick, 1000);
        }

        tick();
    }


    function render(data)
    {
        if (!data || data.authenticated === false) {
            return;
        }

        const labels = data.labels || {};
        const card = element(
            'section',
            'account-email-verification' + (data.verified ? ' is-verified' : '')
        );
        card.setAttribute('aria-label', labels.title || 'Email verification');

        const head = element('div', 'account-email-verification-head');
        const copy = element('div');
        copy.appendChild(element('h3', '', labels.title || 'Підтвердження email'));
        copy.appendChild(element(
            'span',
            'account-email-verification-email',
            data.email || ''
        ));
        head.appendChild(copy);
        head.appendChild(element(
            'span',
            'account-email-verification-status',
            data.verified
                ? (labels.verified || 'Email підтверджено')
                : (labels.unverified || 'Email не підтверджено')
        ));
        card.appendChild(head);

        card.appendChild(element(
            'p',
            'account-email-verification-copy',
            data.verified
                ? (labels.verified_hint || '')
                : (labels.unverified_hint || '')
        ));

        if (!data.verified) {
            const actions = element('div', 'account-email-verification-actions');
            const button = element(
                'button',
                '',
                data.preview_url
                    ? (labels.resend || 'Надіслати ще раз')
                    : (labels.send || 'Надіслати лист підтвердження')
            );
            button.type = 'button';
            button.dataset.defaultText = labels.resend || 'Надіслати ще раз';
            actions.appendChild(button);
            card.appendChild(actions);

            renderPreview(card, data.preview_url || '', labels);

            if (!data.can_resend) {
                startCooldown(button, data.retry_after || 0, labels);
            }

            button.addEventListener('click', async function () {
                if (button.disabled) {
                    return;
                }

                const csrf = csrfToken();

                if (!csrf) {
                    showMessage(card, 'Сесію форми не знайдено. Оновіть сторінку.', true);
                    return;
                }

                button.disabled = true;

                try {
                    const formData = new FormData();
                    formData.append('_csrf', csrf);

                    const response = await fetch(
                        '/Anabelka/account/email-verification/resend',
                        {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: formData
                        }
                    );
                    const result = await response.json();

                    if (!response.ok || !result.ok) {
                        throw new Error(result.error || 'Не вдалося надіслати підтвердження.');
                    }

                    showMessage(card, result.message || '', false);
                    renderPreview(card, result.preview_url || '', result.labels || labels);
                    startCooldown(
                        button,
                        result.retry_after || 60,
                        result.labels || labels
                    );
                } catch (error) {
                    button.disabled = false;
                    showMessage(
                        card,
                        error && error.message
                            ? error.message
                            : 'Не вдалося надіслати підтвердження.',
                        true
                    );
                }
            });
        }

        insertCard(card);
    }


    async function init()
    {
        ensureStyles();

        try {
            const response = await fetch(
                '/Anabelka/account/email-verification/status',
                {
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                }
            );

            if (!response.ok) {
                return;
            }

            render(await response.json());
        } catch (error) {
            // Профіль має залишатися доступним навіть якщо модуль пошти недоступний.
        }
    }


    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
