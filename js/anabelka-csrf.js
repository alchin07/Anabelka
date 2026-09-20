(function () {
    'use strict';

    const script = document.getElementById('anabelka-csrf-script');

    if (!script) {
        return;
    }

    const token = String(script.dataset.csrfToken || '').trim();
    const headerName = 'X-CSRF-Token';
    const unsafeMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];

    if (token === '') {
        return;
    }

    function isUnsafe(method)
    {
        return unsafeMethods.includes(
            String(method || 'GET').toUpperCase()
        );
    }

    function isSameOrigin(url)
    {
        try {
            return new URL(url, window.location.href).origin
                === window.location.origin;
        } catch (error) {
            return false;
        }
    }

    function ensureFormToken(form)
    {
        if (
            !(form instanceof HTMLFormElement)
            || String(form.method || 'GET').toUpperCase() !== 'POST'
        ) {
            return;
        }

        if (
            form.querySelector(
                'input[name="_csrf"], input[name="csrf_token"]'
            )
        ) {
            return;
        }

        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = '_csrf';
        input.value = token;
        form.appendChild(input);
    }

    function decorateForms()
    {
        document.querySelectorAll('form').forEach(ensureFormToken);
    }

    document.addEventListener(
        'submit',
        function (event) {
            ensureFormToken(event.target);
        },
        true
    );

    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            decorateForms,
            { once: true }
        );
    } else {
        decorateForms();
    }

    if (typeof window.fetch === 'function') {
        const nativeFetch = window.fetch.bind(window);

        window.fetch = function (input, init) {
            const options = init ? Object.assign({}, init) : {};
            const request = input instanceof Request ? input : null;
            const method = String(
                options.method
                || (request ? request.method : 'GET')
            ).toUpperCase();
            const url = request ? request.url : String(input);

            if (!isUnsafe(method) || !isSameOrigin(url)) {
                return nativeFetch(input, init);
            }

            const headers = new Headers(
                options.headers
                || (request ? request.headers : undefined)
            );

            if (!headers.has(headerName)) {
                headers.set(headerName, token);
            }

            options.headers = headers;

            return nativeFetch(input, options);
        };
    }

    if (
        typeof XMLHttpRequest !== 'undefined'
        && XMLHttpRequest.prototype
    ) {
        const nativeOpen = XMLHttpRequest.prototype.open;
        const nativeSend = XMLHttpRequest.prototype.send;

        XMLHttpRequest.prototype.open = function (method, url) {
            this.__anabelkaCsrf =
                isUnsafe(method)
                && isSameOrigin(url);

            return nativeOpen.apply(this, arguments);
        };

        XMLHttpRequest.prototype.send = function () {
            if (this.__anabelkaCsrf) {
                try {
                    this.setRequestHeader(headerName, token);
                } catch (error) {
                    // Request will continue without altering browser behavior.
                }
            }

            return nativeSend.apply(this, arguments);
        };
    }

    window.AnabelkaCsrf = Object.freeze({
        token: token,
        headerName: headerName,
        applyToForm: ensureFormToken
    });
})();
