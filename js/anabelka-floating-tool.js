(function () {
    'use strict';

    const instances = new Map();
    const storagePrefix = 'anabelka-floating-tool:';

    function resolveElement(target) {
        if (!target) {
            return null;
        }

        if (typeof target === 'string') {
            return document.querySelector(target);
        }

        return target;
    }

    function storageKeyFor(root, options) {
        const customKey = String(
            options.storageKey
            || root.dataset.anabelkaFloatingKey
            || root.id
            || 'tool'
        ).trim();

        return storagePrefix + customKey;
    }

    function viewportSize() {
        const documentElement = document.documentElement;

        return {
            width: Math.max(
                0,
                documentElement ? documentElement.clientWidth : 0,
                window.innerWidth || 0
            ),
            height: Math.max(
                0,
                documentElement ? documentElement.clientHeight : 0,
                window.innerHeight || 0
            )
        };
    }

    function clamp(value, min, max) {
        return Math.min(Math.max(value, min), Math.max(min, max));
    }

    function clampPosition(root, left, top, margin) {
        const rect = root.getBoundingClientRect();
        const viewport = viewportSize();
        const safeMargin = Math.max(0, Number(margin) || 0);
        const maxLeft = viewport.width - rect.width - safeMargin;
        const maxTop = viewport.height - rect.height - safeMargin;

        return {
            left: clamp(Number(left) || 0, safeMargin, maxLeft),
            top: clamp(Number(top) || 0, safeMargin, maxTop)
        };
    }

    function readStoredPosition(storageKey) {
        try {
            const raw = window.localStorage.getItem(storageKey);

            if (!raw) {
                return null;
            }

            const parsed = JSON.parse(raw);

            if (
                !parsed
                || !Number.isFinite(Number(parsed.left))
                || !Number.isFinite(Number(parsed.top))
            ) {
                return null;
            }

            return {
                left: Number(parsed.left),
                top: Number(parsed.top)
            };
        } catch (error) {
            return null;
        }
    }

    function writeStoredPosition(storageKey, position) {
        try {
            window.localStorage.setItem(
                storageKey,
                JSON.stringify({
                    left: Math.round(position.left),
                    top: Math.round(position.top)
                })
            );
        } catch (error) {
            // Storage may be unavailable in private/restricted browser modes.
        }
    }

    function removeStoredPosition(storageKey) {
        try {
            window.localStorage.removeItem(storageKey);
        } catch (error) {
            // Keep reset functional even when storage access is restricted.
        }
    }

    function register(target, options) {
        const root = resolveElement(target);
        const settings = options || {};

        if (!root) {
            return null;
        }

        if (instances.has(root)) {
            return instances.get(root);
        }

        const handleSelector = settings.handleSelector
            || '[data-anabelka-drag-handle]';
        const handle = root.querySelector(handleSelector);

        if (!handle) {
            return null;
        }

        const initialRect = root.getBoundingClientRect();
        if (initialRect.width <= 0 && initialRect.height <= 0) {
            return null;
        }

        const storageKey = storageKeyFor(root, settings);
        const margin = Math.max(0, Number(settings.margin) || 6);
        let activePointerId = null;
        let startPointerX = 0;
        let startPointerY = 0;
        let startLeft = 0;
        let startTop = 0;
        let hasCustomPosition = false;

        function applyPosition(left, top, persist) {
            const position = clampPosition(root, left, top, margin);

            root.style.left = position.left + 'px';
            root.style.top = position.top + 'px';
            root.style.right = 'auto';
            root.style.bottom = 'auto';
            root.classList.add('anabelka-floating-positioned');
            hasCustomPosition = true;

            if (persist) {
                writeStoredPosition(storageKey, position);
            }

            return position;
        }

        function currentPosition() {
            const rect = root.getBoundingClientRect();

            return {
                left: rect.left,
                top: rect.top
            };
        }

        function refresh() {
            if (!hasCustomPosition) {
                return;
            }

            const position = currentPosition();
            applyPosition(position.left, position.top, true);
        }

        function reset() {
            removeStoredPosition(storageKey);
            hasCustomPosition = false;
            root.classList.remove('anabelka-floating-positioned');
            root.style.removeProperty('left');
            root.style.removeProperty('top');
            root.style.removeProperty('right');
            root.style.removeProperty('bottom');
        }

        function finishPointer(event) {
            if (
                activePointerId === null
                || event.pointerId !== activePointerId
            ) {
                return;
            }

            if (
                typeof handle.hasPointerCapture === 'function'
                && handle.hasPointerCapture(activePointerId)
                && typeof handle.releasePointerCapture === 'function'
            ) {
                handle.releasePointerCapture(activePointerId);
            }

            activePointerId = null;
            root.classList.remove('is-anabelka-floating-dragging');
            const position = currentPosition();
            applyPosition(position.left, position.top, true);
        }

        handle.addEventListener('pointerdown', function (event) {
            if (
                activePointerId !== null
                || (event.pointerType === 'mouse' && event.button !== 0)
            ) {
                return;
            }

            const rect = root.getBoundingClientRect();
            activePointerId = event.pointerId;
            startPointerX = event.clientX;
            startPointerY = event.clientY;
            startLeft = rect.left;
            startTop = rect.top;
            root.classList.add('is-anabelka-floating-dragging');

            if (typeof handle.setPointerCapture === 'function') {
                handle.setPointerCapture(activePointerId);
            }

            event.preventDefault();
        });

        handle.addEventListener('pointermove', function (event) {
            if (event.pointerId !== activePointerId) {
                return;
            }

            applyPosition(
                startLeft + event.clientX - startPointerX,
                startTop + event.clientY - startPointerY,
                false
            );
            event.preventDefault();
        });

        handle.addEventListener('pointerup', finishPointer);
        handle.addEventListener('pointercancel', finishPointer);

        handle.addEventListener('keydown', function (event) {
            if (event.key === 'Home') {
                event.preventDefault();
                reset();
                return;
            }

            const directions = {
                ArrowLeft: [-1, 0],
                ArrowRight: [1, 0],
                ArrowUp: [0, -1],
                ArrowDown: [0, 1]
            };
            const direction = directions[event.key];

            if (!direction) {
                return;
            }

            event.preventDefault();
            const step = event.shiftKey ? 20 : 10;
            const position = currentPosition();

            applyPosition(
                position.left + direction[0] * step,
                position.top + direction[1] * step,
                true
            );
        });

        const controller = {
            root: root,
            handle: handle,
            reset: reset,
            refresh: refresh,
            moveTo: function (left, top) {
                return applyPosition(left, top, true);
            }
        };

        instances.set(root, controller);

        const stored = readStoredPosition(storageKey);
        if (stored) {
            window.requestAnimationFrame(function () {
                applyPosition(stored.left, stored.top, false);
            });
        }

        window.addEventListener('resize', refresh, {passive: true});

        return controller;
    }

    function registerAll(root) {
        const scope = root || document;
        const controllers = [];

        scope.querySelectorAll('[data-anabelka-floating-tool]')
            .forEach(function (element) {
                const controller = register(element);

                if (controller) {
                    controllers.push(controller);
                }
            });

        return controllers;
    }

    function controllerFor(target) {
        const root = resolveElement(target);
        return root ? instances.get(root) || null : null;
    }

    window.AnabelkaFloatingTool = {
        register: register,
        registerAll: registerAll,
        reset: function (target) {
            controllerFor(target)?.reset();
        },
        refresh: function (target) {
            controllerFor(target)?.refresh();
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            registerAll(document);
        });
    } else {
        registerAll(document);
    }
})();
