/* VC-01: refresh only the rankings while preserving the page position.
 * The ordinary GET form remains a no-JavaScript fallback. */
(function () {
    'use strict';

    const section = document.getElementById('visitor-popularity');
    if (!section || typeof window.fetch !== 'function' ||
        typeof window.DOMParser !== 'function') {
        return;
    }

    const form = section.querySelector('.visitor-period-form');
    const select = form && form.querySelector('#visitor-period');
    const button = form && form.querySelector('button[type="submit"]');
    if (!form || !select || !button) { return; }

    const status = document.createElement('p');
    status.className = 'visitor-period-error';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    status.hidden = true;
    form.appendChild(status);

    let pending = false;
    let lastConfirmed = select.value;

    form.addEventListener('submit', async function (event) {
        if (pending) {
            event.preventDefault();
            return;
        }

        const chosen = select.value;
        if (!['7', '30', '90'].includes(chosen)) { return; }
        event.preventDefault();

        const requestUrl = new URL(form.action, window.location.href);
        if (requestUrl.origin !== window.location.origin ||
            requestUrl.pathname !== window.location.pathname) {
            status.hidden = false;
            status.textContent = 'Не вдалося змінити період. Оновіть сторінку.';
            return;
        }

        requestUrl.searchParams.set('period', chosen);
        requestUrl.hash = '';
        pending = true;
        form.setAttribute('aria-busy', 'true');
        button.disabled = true;
        status.hidden = true;
        status.textContent = '';

        try {
            const response = await window.fetch(requestUrl.href, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {'Accept': 'text/html'}
            });
            if (!response.ok) { throw new Error('HTTP ' + response.status); }

            const html = await response.text();
            const parsed = new window.DOMParser().parseFromString(html, 'text/html');
            const incoming = parsed.querySelector('#visitor-popularity');
            const incomingGrid = incoming && incoming.querySelector('.visitor-popularity-grid');
            const incomingSelect = incoming && incoming.querySelector('#visitor-period');
            const existingGrid = section.querySelector('.visitor-popularity-grid');
            if (!incomingGrid || !incomingSelect || !existingGrid ||
                incomingSelect.value !== chosen || incomingGrid.querySelector('script')) {
                throw new Error('Unexpected report response');
            }

            existingGrid.replaceWith(document.importNode(incomingGrid, true));
            const nextUrl = new URL(window.location.href);
            nextUrl.searchParams.set('period', chosen);
            nextUrl.hash = 'visitor-popularity';
            window.history.replaceState(
                window.history.state, '', nextUrl.pathname + nextUrl.search + nextUrl.hash
            );
            lastConfirmed = chosen;
        } catch (error) {
            // Keep the last successfully loaded results visible.
            select.value = lastConfirmed;
            select.dispatchEvent(new Event('change', {bubbles: true}));
            status.hidden = false;
            status.textContent = 'Не вдалося оновити рейтинг. Спробуйте ще раз.';
        } finally {
            pending = false;
            button.disabled = false;
            form.removeAttribute('aria-busy');
        }
    });
}());
