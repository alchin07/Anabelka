import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import {fileURLToPath} from 'node:url';

const testDirectory = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(testDirectory, '..');
const storageKey = 'anabelka-notify-flash';

function read(relativePath) {
    return fs.readFileSync(path.join(projectRoot, relativePath), 'utf8');
}

function readIfPresent(relativePath) {
    const absolutePath = path.join(projectRoot, relativePath);

    return fs.existsSync(absolutePath)
        ? fs.readFileSync(absolutePath, 'utf8')
        : '';
}

function createClassList() {
    const names = new Set();

    return {
        add(...values) {
            values.forEach((value) => names.add(value));
        },
        remove(...values) {
            values.forEach((value) => names.delete(value));
        },
        toggle(value, enabled) {
            if (enabled) {
                names.add(value);
            } else {
                names.delete(value);
            }
        },
        contains(value) {
            return names.has(value);
        }
    };
}

function createMessageElement() {
    return {
        textContent: '',
        classList: createClassList()
    };
}

function createStorage(values = new Map(), unavailable = false) {
    if (unavailable) {
        return {
            getItem() {
                throw new Error('sessionStorage disabled');
            },
            setItem() {
                throw new Error('sessionStorage disabled');
            },
            removeItem() {
                throw new Error('sessionStorage disabled');
            }
        };
    }

    return {
        getItem(key) {
            return values.has(key) ? values.get(key) : null;
        },
        setItem(key, value) {
            values.set(key, String(value));
        },
        removeItem(key) {
            values.delete(key);
        }
    };
}

function loadNotify({
    values = new Map(),
    element = createMessageElement(),
    unavailableStorage = false,
    loadWrapper = false
} = {}) {
    const timers = [];
    const window = {
        sessionStorage: createStorage(values, unavailableStorage),
        clearTimeout() {},
        setTimeout(callback, delay) {
            timers.push({callback, delay});
            return timers.length;
        }
    };
    const document = {
        getElementById(id) {
            return id === 'site-message' ? element : null;
        }
    };

    window.window = window;
    vm.runInNewContext(
        readIfPresent('js/anabelka-notify.js'),
        {document, window},
        {filename: 'js/anabelka-notify.js'}
    );

    assert.ok(
        window.AnabelkaNotify,
        'js/anabelka-notify.js must expose window.AnabelkaNotify'
    );

    if (loadWrapper) {
        vm.runInNewContext(
            read('js/admin-flash-message.js'),
            {document, window},
            {filename: 'js/admin-flash-message.js'}
        );
    }

    return {element, timers, values, window};
}

function assertShown(page, type, message, duration) {
    assert.equal(page.element.textContent, message);
    assert.ok(page.element.classList.contains('show'));
    assert.ok(page.element.classList.contains('is-' + type));
    assert.equal(page.timers.at(-1)?.delay, duration);
}

test('success shows the centered toast for 2800 ms by default', function () {
    const page = loadNotify();

    assert.equal(page.window.AnabelkaNotify.success('Збережено.'), true);
    assertShown(page, 'success', 'Збережено.', 2800);
});

test('error shows the error toast for 4800 ms by default', function () {
    const page = loadNotify();

    assert.equal(page.window.AnabelkaNotify.error('Помилка.'), true);
    assertShown(page, 'error', 'Помилка.', 4800);
});

test('warning shows the calm warning toast for 4200 ms by default', function () {
    const page = loadNotify();

    assert.equal(page.window.AnabelkaNotify.warning('Перевірте дані.'), true);
    assertShown(page, 'warning', 'Перевірте дані.', 4200);
});

test('info shows the branded light toast for 3500 ms by default', function () {
    const page = loadNotify();

    assert.equal(page.window.AnabelkaNotify.info('До відома.'), true);
    assertShown(page, 'info', 'До відома.', 3500);
});

test('show honors a custom duration', function () {
    const page = loadNotify();

    page.window.AnabelkaNotify.show(
        'warning',
        'Зачекайте.',
        {duration: 1234}
    );

    assertShown(page, 'warning', 'Зачекайте.', 1234);
});

test('store survives a simulated reload and auto-consume displays it', function () {
    const values = new Map();
    const firstPage = loadNotify({values, element: null});

    assert.equal(
        firstPage.window.AnabelkaNotify.store(
            'success',
            'Категорію створено.',
            {duration: 1600}
        ),
        true
    );
    assert.deepEqual(JSON.parse(values.get(storageKey)), {
        type: 'success',
        message: 'Категорію створено.',
        options: {duration: 1600}
    });

    const reloadedPage = loadNotify({values});

    assert.equal(values.has(storageKey), false);
    assertShown(reloadedPage, 'success', 'Категорію створено.', 1600);
});

test('stored flash is consumed only once', function () {
    const values = new Map();
    const firstPage = loadNotify({values, element: null});

    firstPage.window.AnabelkaNotify.store('info', 'Одноразове.');

    const secondPage = loadNotify({values});
    const thirdPage = loadNotify({values});

    assertShown(secondPage, 'info', 'Одноразове.', 3500);
    assert.equal(thirdPage.element.textContent, '');
    assert.equal(thirdPage.timers.length, 0);
    assert.equal(thirdPage.window.AnabelkaNotify.consume(), false);
});

test('consume removes corrupted JSON without showing or throwing', function () {
    const values = new Map([[storageKey, '{broken-json']]);
    const page = loadNotify({values});

    assert.equal(values.has(storageKey), false);
    assert.equal(page.element.textContent, '');
    assert.equal(page.timers.length, 0);
    assert.equal(page.window.AnabelkaNotify.consume(), false);
});

test('disabled sessionStorage never breaks show, store, or consume', function () {
    const page = loadNotify({unavailableStorage: true});

    assert.doesNotThrow(function () {
        assert.equal(
            page.window.AnabelkaNotify.store('success', 'Збережено.'),
            false
        );
        assert.equal(page.window.AnabelkaNotify.consume(), false);
        page.window.AnabelkaNotify.success('Видиме повідомлення.');
    });
    assertShown(page, 'success', 'Видиме повідомлення.', 2800);
});

test('unknown notification type safely falls back to info', function () {
    const values = new Map();
    const page = loadNotify({values});

    page.window.AnabelkaNotify.show('danger', 'Невідомий тип.');
    assertShown(page, 'info', 'Невідомий тип.', 3500);

    page.window.AnabelkaNotify.store('danger', 'Збережений тип.');
    assert.equal(JSON.parse(values.get(storageKey)).type, 'info');
});

test('empty messages are ignored and never enter storage', function () {
    const page = loadNotify();

    assert.equal(page.window.AnabelkaNotify.show('success', '   '), false);
    assert.equal(page.window.AnabelkaNotify.store('error', ''), false);
    assert.equal(page.values.size, 0);
    assert.equal(page.element.textContent, '');
    assert.equal(page.timers.length, 0);
});

test('formatCount caps notification badges at 99+', function () {
    const page = loadNotify({element: null});
    const formatCount = page.window.AnabelkaNotify.formatCount;

    assert.equal(formatCount(-4), '0');
    assert.equal(formatCount('invalid'), '0');
    assert.equal(formatCount(0), '0');
    assert.equal(formatCount(7), '7');
    assert.equal(formatCount(99), '99');
    assert.equal(formatCount(100), '99+');
    assert.equal(formatCount(1280), '99+');
});

test('AdminFlashMessage remains a compatibility wrapper over AnabelkaNotify', function () {
    const page = loadNotify({loadWrapper: true});
    const calls = [];
    const originalShow = page.window.AnabelkaNotify.show;
    const originalStore = page.window.AnabelkaNotify.store;

    page.window.AnabelkaNotify.show = function (...args) {
        calls.push(['show', ...args]);
        return originalShow.apply(this, args);
    };
    page.window.AnabelkaNotify.store = function (...args) {
        calls.push(['store', ...args]);
        return originalStore.apply(this, args);
    };

    page.window.AdminFlashMessage.show('Операцію не виконано.', true);
    page.window.AdminFlashMessage.storeSuccess('Категорію оновлено.');

    assert.deepEqual(calls[0], [
        'show',
        'error',
        'Операцію не виконано.'
    ]);
    assert.deepEqual(calls[1], [
        'store',
        'success',
        'Категорію оновлено.'
    ]);
    assertShown(page, 'error', 'Операцію не виконано.', 4800);
    assert.equal(JSON.parse(page.values.get(storageKey)).type, 'success');
});

test('category reload and translation-return pages load the shared module once before the wrapper', function () {
    const views = [
        read('views/admin/categories/index.php'),
        read('views/admin/translations/missing.php')
    ];

    views.forEach(function (view) {
        const moduleMatches = view.match(/js\/anabelka-notify\.js\?v=1/g) || [];
        const wrapperMatches = view.match(/js\/admin-flash-message\.js\?v=2/g) || [];

        assert.equal(moduleMatches.length, 1);
        assert.equal(wrapperMatches.length, 1);
        assert.ok(
            view.indexOf('js/anabelka-notify.js?v=1')
                < view.indexOf('js/admin-flash-message.js?v=2')
        );
        assert.match(view, /css\/anabelka-notify\.css\?v=1/);
        assert.doesNotMatch(view, /css\/admin-flash-message\.css/);
    });

    assert.doesNotMatch(
        read('js/admin-categories.js'),
        /anabelka-category-success-flash/
    );
});

test('notification CSS preserves centered type colors without horizontal overflow', function () {
    const css = readIfPresent('css/anabelka-notify.css');

    assert.match(css, /position:\s*fixed/i);
    assert.match(css, /top:\s*50%/i);
    assert.match(css, /left:\s*50%/i);
    assert.match(css, /width:\s*max-content/i);
    assert.match(
        css,
        /max-width:\s*min\(360px,\s*calc\(100vw\s*-\s*24px\)\)/i
    );
    assert.match(css, /box-sizing:\s*border-box/i);
    assert.match(css, /overflow-wrap:\s*anywhere/i);
    assert.match(
        css,
        /\.site-message\.is-success\s*\{[^{}]*background:\s*#8a2be2[^{}]*color:\s*#fff/is
    );
    assert.match(
        css,
        /\.site-message\.is-error\s*\{[^{}]*background:\s*#b63e48[^{}]*color:\s*#fff/is
    );
    assert.match(
        css,
        /\.site-message\.is-info\s*\{[^{}]*background:\s*#f4eaff[^{}]*color:\s*#6519b9[^{}]*border[^;]*#8a2be2/is
    );
    assert.match(
        css,
        /\.site-message\.is-warning\s*\{[^{}]*background:\s*#fff7e6[^{}]*color:\s*#6d4c1f[^{}]*border[^;]*#d4a047/is
    );
    assert.doesNotMatch(css, /#302437/i);

    const expectedMaxWidths = new Map([
        [320, 296],
        [360, 336],
        [375, 351],
        [390, 360],
        [412, 360],
        [430, 360]
    ]);

    expectedMaxWidths.forEach(function (expected, viewport) {
        assert.equal(Math.min(360, viewport - 24), expected);
        assert.ok(expected <= viewport, `${viewport}px viewport overflowed`);
    });
});

async function runHeaderBadges(sharedFormatter) {
    const attributes = new Map([
        ['aria-label', 'Адмін-панель'],
        ['title', 'Адмін-панель']
    ]);
    const messageBadge = {hidden: false, textContent: '12'};
    const systemBadge = {hidden: true, textContent: '0'};
    const adminLink = {
        querySelector(selector) {
            return selector === '.public-header-admin-message-badge'
                ? messageBadge
                : null;
        },
        getAttribute(name) {
            return attributes.get(name) || null;
        },
        setAttribute(name, value) {
            attributes.set(name, String(value));
        }
    };
    const document = {
        querySelector(selector) {
            return selector === '.public-header-admin-action'
                ? adminLink
                : null;
        },
        getElementById(id) {
            return id === 'admin-system-error-count'
                ? systemBadge
                : null;
        }
    };
    const window = sharedFormatter
        ? {AnabelkaNotify: {formatCount: sharedFormatter}}
        : {};
    const fetch = function () {
        return Promise.resolve({
            ok: true,
            json() {
                return Promise.resolve({ok: true, count: 105});
            }
        });
    };

    vm.runInNewContext(
        read('js/public-header-admin-badges.js'),
        {document, fetch, window},
        {filename: 'js/public-header-admin-badges.js'}
    );
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();

    return {adminLink, attributes, messageBadge, systemBadge};
}

test('header system badge uses AnabelkaNotify.formatCount when available', async function () {
    const page = await runHeaderBadges(function (count) {
        return 'shared-' + count;
    });

    assert.equal(page.messageBadge.hidden, true);
    assert.equal(page.systemBadge.hidden, false);
    assert.equal(page.systemBadge.textContent, 'shared-105');
    assert.equal(
        page.attributes.get('aria-label'),
        'Адмін-панель. Нових системних помилок: 105'
    );
    assert.equal(
        page.attributes.get('title'),
        'Адмін-панель. Нових системних помилок: 105'
    );
});

test('header system badge keeps a safe 99+ fallback without the shared module', async function () {
    const page = await runHeaderBadges(null);

    assert.equal(page.messageBadge.hidden, true);
    assert.equal(page.systemBadge.hidden, false);
    assert.equal(page.systemBadge.textContent, '99+');
});
