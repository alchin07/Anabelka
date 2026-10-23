/* Анабелька — приватний переглядач фотографій у чаті.
   Всі зображення відкриваються лише через серверну перевірку членства. */
(function () {
    'use strict';

    const thread = document.getElementById('chat-messages');
    const viewer = document.getElementById('chat-photo-viewer');
    if (!thread || !viewer) { return; }

    const stage = document.getElementById('chat-photo-stage');
    const image = document.getElementById('chat-photo-image');
    const status = document.getElementById('chat-photo-status');
    const title = document.getElementById('chat-photo-title');
    const counter = document.getElementById('chat-photo-counter');
    const save = document.getElementById('chat-photo-save');
    const previous = document.getElementById('chat-photo-prev');
    const next = document.getElementById('chat-photo-next');
    const close = document.getElementById('chat-photo-close');
    const zoomIn = document.getElementById('chat-photo-zoom-in');
    const zoomOut = document.getElementById('chat-photo-zoom-out');
    const zoomLabel = document.getElementById('chat-photo-zoom-label');

    const photo = {
        items: [], index: 0, scale: 1, x: 0, y: 0,
        inHistory: false, ignoreNextPop: false, returnFocus: null,
        pointers: new Map(), swipeStart: null, pinch: null,
        lastTap: null
    };

    // Only same-origin attachment endpoints already rendered in this thread.
    // Never derive a disk path or accept arbitrary URLs from message content.
    function galleryFromThread() {
        return Array.from(thread.querySelectorAll('a.chat-image-card'))
            .map(function (link) {
                const raw = link.getAttribute('href');
                if (!raw) { return null; }
                let url;
                try { url = new URL(raw, window.location.href); }
                catch (error) { return null; }
                if (url.origin !== window.location.origin ||
                    !/\/admin\/chat\/file$/.test(url.pathname) ||
                    !/^[1-9]\d*$/.test(url.searchParams.get('id') || '')) {
                    return null;
                }
                const preview = link.querySelector('img');
                return {
                    link: link, url: url.href,
                    name: preview && preview.alt ? preview.alt : 'Фотографія'
                };
            })
            .filter(Boolean);
    }

    function resetPointers() {
        photo.pointers.clear();
        photo.swipeStart = null;
        photo.pinch = null;
        photo.lastTap = null;
    }

    function clampPan() {
        if (photo.scale <= 1) {
            photo.x = 0;
            photo.y = 0;
            return;
        }
        const maxX = Math.max(0, (image.offsetWidth * photo.scale - stage.clientWidth) / 2);
        const maxY = Math.max(0, (image.offsetHeight * photo.scale - stage.clientHeight) / 2);
        photo.x = Math.max(-maxX, Math.min(maxX, photo.x));
        photo.y = Math.max(-maxY, Math.min(maxY, photo.y));
    }

    function applyTransform() {
        clampPan();
        image.style.transform = 'translate3d(' + photo.x + 'px,' + photo.y +
            'px,0) scale(' + photo.scale + ')';
        stage.classList.toggle('is-zoomed', photo.scale > 1);
        zoomLabel.textContent = Math.round(photo.scale * 100) + '%';
        zoomOut.disabled = photo.scale <= 1;
        zoomIn.disabled = photo.scale >= 4;
    }

    function setZoom(scale) {
        photo.scale = Math.max(1, Math.min(4, scale));
        applyTransform();
    }

    function showPhoto(index) {
        if (index < 0 || index >= photo.items.length) { return; }
        photo.index = index;
        const item = photo.items[index];
        resetPointers();
        photo.scale = 1;
        photo.x = 0;
        photo.y = 0;
        applyTransform();
        title.textContent = item.name;
        counter.textContent = (index + 1) + ' / ' + photo.items.length;
        previous.disabled = index === 0;
        next.disabled = index === photo.items.length - 1;
        save.href = item.url;
        save.download = item.name;
        image.hidden = true;
        status.hidden = false;
        status.textContent = 'Завантаження фото…';
        image.alt = item.name;
        image.onload = function () {
            if (viewer.hidden || image.src !== item.url) { return; }
            image.hidden = false;
            status.hidden = true;
            applyTransform();
        };
        image.onerror = function () {
            if (viewer.hidden || image.src !== item.url) { return; }
            image.hidden = true;
            status.hidden = false;
            status.textContent = 'Фото недоступне. Перевірте доступ до розмови.';
        };
        image.src = item.url;
    }

    function movePhoto(direction) {
        if (photo.pointers.size > 1) { return; }
        showPhoto(photo.index + direction);
    }

    function openViewer(link) {
        const items = galleryFromThread();
        const index = items.findIndex(function (item) { return item.link === link; });
        if (index < 0) { return false; }
        photo.items = items;
        photo.returnFocus = document.activeElement;
        viewer.hidden = false;
        document.body.classList.add('chat-photo-no-scroll');
        showPhoto(index);
        try {
            const original = history.state && typeof history.state === 'object'
                ? history.state : {};
            history.pushState(Object.assign({}, original, {anabelkaChatPhoto: true}),
                '', window.location.href);
            photo.inHistory = true;
        } catch (error) {
            photo.inHistory = false;
        }
        close.focus();
        return true;
    }

    function closeViewer(fromHistory) {
        if (viewer.hidden) { return; }
        viewer.hidden = true;
        image.onload = null;
        image.onerror = null;
        image.removeAttribute('src');
        photo.items = [];
        resetPointers();
        document.body.classList.remove('chat-photo-no-scroll');
        const focusTarget = photo.returnFocus;
        photo.returnFocus = null;
        if (focusTarget && focusTarget.isConnected && typeof focusTarget.focus === 'function') {
            focusTarget.focus({preventScroll: true});
        }
        const hadHistory = photo.inHistory;
        photo.inHistory = false;
        if (!fromHistory && hadHistory) {
            photo.ignoreNextPop = true;
            history.back();
        }
    }

    // Called by the existing chat popstate handler, before chat navigation.
    function consumePopState() {
        if (!viewer.hidden) {
            closeViewer(true);
            return true;
        }
        if (photo.ignoreNextPop) {
            photo.ignoreNextPop = false;
            return true;
        }
        return false;
    }
    window.AnabelkaChatPhotoViewer = {consumePopState: consumePopState};

    thread.addEventListener('click', function (event) {
        const target = event.target && event.target.closest
            ? event.target.closest('a.chat-image-card') : null;
        if (!target || !thread.contains(target)) { return; }
        const valid = galleryFromThread().some(function (item) { return item.link === target; });
        if (!valid) { return; }
        event.preventDefault();
        openViewer(target);
    });

    close.addEventListener('click', function () { closeViewer(false); });
    previous.addEventListener('click', function () { movePhoto(-1); });
    next.addEventListener('click', function () { movePhoto(1); });
    zoomIn.addEventListener('click', function () { setZoom(photo.scale + 0.5); });
    zoomOut.addEventListener('click', function () { setZoom(photo.scale - 0.5); });
    window.addEventListener('resize', function () {
        if (!viewer.hidden) { applyTransform(); }
    });

    // Pointer Events support one-finger swipes/panning and two-finger pinch.
    function pointerDistance(first, second) {
        return Math.hypot(first.x - second.x, first.y - second.y);
    }

    stage.addEventListener('pointerdown', function (event) {
        if (viewer.hidden || (event.pointerType === 'mouse' && event.button !== 0)) {
            return;
        }
        event.preventDefault();
        if (typeof stage.setPointerCapture === 'function') {
            stage.setPointerCapture(event.pointerId);
        }
        photo.pointers.set(event.pointerId, {x: event.clientX, y: event.clientY});
        if (photo.pointers.size === 1) {
            photo.swipeStart = {
                x: event.clientX, y: event.clientY,
                time: Date.now(), isTouch: event.pointerType === 'touch'
            };
            photo.pinch = null;
        } else if (photo.pointers.size === 2) {
            const pair = Array.from(photo.pointers.values());
            photo.pinch = {distance: pointerDistance(pair[0], pair[1]), scale: photo.scale};
            photo.swipeStart = null;
            photo.lastTap = null;
        }
    });

    stage.addEventListener('pointermove', function (event) {
        const old = photo.pointers.get(event.pointerId);
        if (!old || viewer.hidden) { return; }
        event.preventDefault();
        photo.pointers.set(event.pointerId, {x: event.clientX, y: event.clientY});
        if (photo.pointers.size >= 2 && photo.pinch) {
            const pair = Array.from(photo.pointers.values());
            const distance = pointerDistance(pair[0], pair[1]);
            if (photo.pinch.distance > 0) {
                setZoom(photo.pinch.scale * distance / photo.pinch.distance);
            }
        } else if (photo.pointers.size === 1 && photo.scale > 1 && !photo.pinch) {
            photo.x += event.clientX - old.x;
            photo.y += event.clientY - old.y;
            applyTransform();
        }
    });

    function finishPointer(event, cancelled) {
        if (!photo.pointers.has(event.pointerId)) { return; }
        const countBefore = photo.pointers.size;
        const start = photo.swipeStart;
        photo.pointers.delete(event.pointerId);
        if (countBefore > 1) {
            photo.pinch = null;
            photo.swipeStart = null;
            photo.lastTap = null;
            return;
        }
        photo.swipeStart = null;
        photo.pinch = null;
        if (cancelled || !start || viewer.hidden) { return; }
        const dx = event.clientX - start.x;
        const dy = event.clientY - start.y;
        if (photo.scale <= 1 && Math.abs(dx) > 65 &&
            Math.abs(dx) > Math.abs(dy) * 1.4) {
            movePhoto(dx < 0 ? 1 : -1);
            return;
        }
        if (start.isTouch && Math.abs(dx) < 18 && Math.abs(dy) < 18) {
            const now = Date.now();
            const last = photo.lastTap;
            if (last && now - last.time < 360 &&
                Math.hypot(event.clientX - last.x, event.clientY - last.y) < 45) {
                setZoom(photo.scale > 1 ? 1 : 2.5);
                photo.lastTap = null;
            } else {
                photo.lastTap = {time: now, x: event.clientX, y: event.clientY};
            }
        }
    }
    stage.addEventListener('pointerup', function (event) { finishPointer(event, false); });
    stage.addEventListener('pointercancel', function (event) { finishPointer(event, true); });
    stage.addEventListener('dblclick', function (event) {
        event.preventDefault();
        setZoom(photo.scale > 1 ? 1 : 2.5);
    });

    document.addEventListener('keydown', function (event) {
        if (viewer.hidden) { return; }
        if (event.key === 'Escape') {
            event.preventDefault();
            closeViewer(false);
        } else if (event.key === 'ArrowLeft') {
            event.preventDefault();
            movePhoto(-1);
        } else if (event.key === 'ArrowRight') {
            event.preventDefault();
            movePhoto(1);
        } else if (event.key === '+' || event.key === '=') {
            event.preventDefault();
            setZoom(photo.scale + 0.5);
        } else if (event.key === '-') {
            event.preventDefault();
            setZoom(photo.scale - 0.5);
        } else if (event.key === 'Tab') {
            const controls = Array.from(viewer.querySelectorAll('button:not(:disabled),a[href]'));
            if (!controls.length) { return; }
            const first = controls[0];
            const last = controls[controls.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }
    });
}());
