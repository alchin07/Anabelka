(function () {
    'use strict';

    if (window.AnabelkaAdminBack) {
        return;
    }

    const handlers = [];
    let sequence = 0;
    let armed = false;
    let suppressNextPop = false;
    let pendingNavigationUrl = '';
    let syncFrame = 0;


    function currentUrl()
    {
        return window.location.pathname
            + window.location.search
            + window.location.hash;
    }


    function sortedHandlers()
    {
        return handlers
            .slice()
            .sort(function (first, second) {
                if (second.priority !== first.priority) {
                    return second.priority - first.priority;
                }

                return second.sequence - first.sequence;
            });
    }


    function activeHandler()
    {
        return sortedHandlers().find(function (handler) {
            try {
                return handler.isActive();
            } catch (error) {
                return false;
            }
        }) || null;
    }


    function arm()
    {
        if (armed || suppressNextPop || !activeHandler()) {
            return false;
        }

        const state = history.state
            && typeof history.state === 'object'
            ? Object.assign({}, history.state)
            : {};

        state.__anabelkaAdminBackGuard = 1;
        history.pushState(state, '', currentUrl());
        armed = true;
        return true;
    }


    function disarmIfIdle()
    {
        if (!armed || activeHandler() || suppressNextPop) {
            return false;
        }

        armed = false;
        suppressNextPop = true;
        history.back();
        return true;
    }


    function syncGuard()
    {
        syncFrame = 0;

        if (suppressNextPop) {
            return;
        }

        if (activeHandler()) {
            arm();
        } else {
            disarmIfIdle();
        }
    }


    function queueSync()
    {
        if (syncFrame) {
            return;
        }

        syncFrame = window.requestAnimationFrame(syncGuard);
    }


    function register(options)
    {
        const settings = options && typeof options === 'object'
            ? options
            : {};
        const key = String(settings.key || '').trim();

        if (
            key === ''
            || typeof settings.isActive !== 'function'
            || typeof settings.close !== 'function'
        ) {
            return function () {};
        }

        sequence += 1;

        const handler = {
            key: key,
            priority: Number(settings.priority || 0),
            sequence: sequence,
            isActive: settings.isActive,
            close: settings.close
        };

        handlers.push(handler);
        queueSync();

        return function unregister()
        {
            const index = handlers.indexOf(handler);

            if (index >= 0) {
                handlers.splice(index, 1);
                queueSync();
            }
        };
    }


    function handlePopState()
    {
        if (suppressNextPop) {
            suppressNextPop = false;

            if (pendingNavigationUrl !== '') {
                const url = pendingNavigationUrl;
                pendingNavigationUrl = '';
                window.location.assign(url);
                return;
            }

            queueSync();
            return;
        }

        if (!armed) {
            return;
        }

        armed = false;

        const handler = activeHandler();

        if (!handler) {
            /*
             * A layer disappeared before the browser delivered popstate.
             * Continue with the real browser history instead of trapping Back.
             */
            history.back();
            return;
        }

        handler.close({
            source: 'android-back',
            key: handler.key
        });

        window.setTimeout(queueSync, 0);
    }


    function anchorNavigationUrl(event)
    {
        if (
            event.defaultPrevented
            || event.button !== 0
            || event.metaKey
            || event.ctrlKey
            || event.shiftKey
            || event.altKey
        ) {
            return '';
        }

        const anchor = event.target.closest('a[href]');

        if (
            !anchor
            || anchor.hasAttribute('download')
            || String(anchor.target || '').toLowerCase() === '_blank'
        ) {
            return '';
        }

        const href = String(anchor.getAttribute('href') || '').trim();

        if (
            href === ''
            || href.charAt(0) === '#'
            || /^javascript:/i.test(href)
            || /^mailto:/i.test(href)
            || /^tel:/i.test(href)
        ) {
            return '';
        }

        try {
            const url = new URL(anchor.href, window.location.href);

            if (url.origin !== window.location.origin) {
                return '';
            }

            return url.href;
        } catch (error) {
            return '';
        }
    }


    document.addEventListener(
        'click',
        function (event) {
            const url = anchorNavigationUrl(event);

            if (url === '' || !armed || suppressNextPop) {
                return;
            }

            event.preventDefault();
            pendingNavigationUrl = url;
            armed = false;
            suppressNextPop = true;
            history.back();
        },
        true
    );


    window.addEventListener('popstate', handlePopState);
    window.addEventListener('pageshow', function () {
        pendingNavigationUrl = '';
        queueSync();
    });

    const observer = new MutationObserver(queueSync);

    observer.observe(document.documentElement, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: [
            'hidden',
            'class',
            'open',
            'aria-expanded'
        ]
    });


    window.AnabelkaAdminBack = {
        register: register,
        sync: queueSync,
        top: function () {
            const handler = activeHandler();

            return handler ? handler.key : '';
        }
    };
}());
