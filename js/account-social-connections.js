(function () {
    'use strict';

    const endpoint = '/Anabelka/account/social-connections/status';

    function addStylesheet() {
        if (document.querySelector('link[data-account-social-connections]')) {
            return;
        }

        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = '/Anabelka/css/account-social-connections.css?v=1';
        link.setAttribute('data-account-social-connections', '1');
        document.head.appendChild(link);
    }

    function element(tag, className, text) {
        const node = document.createElement(tag);

        if (className) {
            node.className = className;
        }

        if (text !== undefined && text !== null) {
            node.textContent = String(text);
        }

        return node;
    }

    function providerCard(provider, strings, csrfToken) {
        const linked = Boolean(provider.linked);
        const available = Boolean(provider.available);
        const card = element(
            'article',
            'account-social-provider' + (available || linked ? '' : ' is-unavailable')
        );
        const mark = element('span', 'account-social-provider-mark', provider.mark || '•');
        mark.setAttribute('aria-hidden', 'true');

        const copy = element('div', 'account-social-provider-copy');
        const title = element('div', 'account-social-provider-title');
        const name = element('strong', '', provider.label || provider.code || '');
        const state = element(
            'span',
            'account-social-provider-state ' + (linked ? 'is-linked' : 'is-unlinked'),
            linked ? strings.connected : strings.not_connected
        );
        title.append(name, state);
        copy.appendChild(title);

        if (linked && provider.linked_email) {
            copy.appendChild(
                element('span', 'account-social-provider-email', provider.linked_email)
            );
        } else if (!provider.configured) {
            copy.appendChild(
                element('span', 'account-social-provider-note', strings.not_configured)
            );
        } else if (!provider.enabled) {
            copy.appendChild(
                element('span', 'account-social-provider-note', strings.unavailable)
            );
        }

        const actions = element('div', 'account-social-provider-actions');

        if (!linked && provider.connect_url) {
            const connect = element('a', 'account-social-connect', strings.connect);
            connect.href = provider.connect_url;
            actions.appendChild(connect);
        }

        if (linked && provider.can_disconnect) {
            const form = document.createElement('form');
            form.method = 'post';
            form.action = '/Anabelka/account/social-disconnect';

            const csrf = document.createElement('input');
            csrf.type = 'hidden';
            csrf.name = '_csrf';
            csrf.value = csrfToken || '';

            const providerInput = document.createElement('input');
            providerInput.type = 'hidden';
            providerInput.name = 'provider';
            providerInput.value = provider.code || '';

            const button = element(
                'button',
                'account-social-disconnect',
                strings.disconnect
            );
            button.type = 'submit';
            form.append(csrf, providerInput, button);
            actions.appendChild(form);
        } else if (linked && !provider.can_disconnect) {
            copy.appendChild(
                element('span', 'account-social-provider-note', strings.last_method)
            );
        }

        card.append(mark, copy, actions);
        return card;
    }

    function render(data) {
        if (!data || !data.authenticated || !Array.isArray(data.providers)) {
            return;
        }

        if (document.querySelector('.account-social-connections')) {
            return;
        }

        addStylesheet();

        const strings = data.strings || {};
        const section = element('section', 'account-social-connections');
        const head = element('div', 'account-social-connections-head');
        const headCopy = document.createElement('div');
        headCopy.append(
            element('h3', '', strings.title || 'Способи входу'),
            element(
                'p',
                '',
                strings.hint || 'Підключайте додаткові способи входу до одного акаунта Анабельки.'
            )
        );
        head.appendChild(headCopy);
        section.appendChild(head);

        const list = element('div', 'account-social-connections-list');
        data.providers.forEach(function (provider) {
            list.appendChild(
                providerCard(provider, strings, data.csrf_token || '')
            );
        });
        section.appendChild(list);

        const links = document.querySelector('.account-links');
        const grid = document.querySelector('.account-grid');

        if (links && links.parentNode) {
            links.insertAdjacentElement('afterend', section);
        } else if (grid && grid.parentNode) {
            grid.parentNode.insertBefore(section, grid);
        } else {
            const page = document.querySelector('.account-page');
            if (page) {
                page.appendChild(section);
            }
        }
    }

    function load() {
        fetch(endpoint, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('status');
                }
                return response.json();
            })
            .then(render)
            .catch(function () {
                // Блок способів входу не повинен ламати сторінку акаунта.
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', load);
    } else {
        load();
    }
})();
