(function () {
    'use strict';

    if (window.AnabelkaAdminBack) {
        return;
    }

    const handlers = [];
    let sequence = 0;
    let armed = false;
    let navigatingAway = false;


    function currentUrl()
    {
        return window.location.pathname
            + window.location.search
            + window.location.hash;
    }


    function arm()
    {
        if (navigatingAway || armed) {
            return;
        }

        const state = history.state
            && typeof history.state === 'object'
            ? Object.assign({}, history.state)
            : {};

        state.__anabelkaAdminBackGuard = 1;
        history.pushState(state, '', currentUrl());
        armed = true;
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

        return function unregister()
        {
            const index = handlers.indexOf(handler);

            if (index >= 0) {
                handlers.splice(index, 1);
            }
        };
    }


    function handlePopState()
    {
        if (!armed || navigatingAway) {
            return;
        }

        armed = false;

        const handler = activeHandler();

        if (handler) {
            try {
                handler.close({
                    source: 'android-back',
                    key: handler.key
                });
            } finally {
                window.setTimeout(arm, 0);
            }
            return;
        }

        /*
         * The guard was the only synthetic history entry. With no active
         * admin layer left to close, continue to the real previous page.
         */
        navigatingAway = true;
        window.setTimeout(function () {
            history.back();
        }, 0);
    }


    window.addEventListener('popstate', handlePopState);

    window.addEventListener('pageshow', function () {
        navigatingAway = false;

        if (
            history.state
            && history.state.__anabelkaAdminBackGuard === 1
        ) {
            armed = true;
            return;
        }

        armed = false;
        arm();
    });


    window.AnabelkaAdminBack = {
        register: register,
        arm: arm,
        top: function () {
            const handler = activeHandler();

            return handler ? handler.key : '';
        }
    };


    if (
        history.state
        && history.state.__anabelkaAdminBackGuard === 1
    ) {
        armed = true;
    } else {
        arm();
    }
}());
