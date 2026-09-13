import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const testDirectory = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(testDirectory, '..');

function filePath(relativePath) {
    return path.join(projectRoot, relativePath);
}

function read(relativePath) {
    return fs.readFileSync(filePath(relativePath), 'utf8');
}

function cssRuleBody(css, selector) {
    const escapedSelector = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const match = css.match(
        new RegExp('(?:^|})\\s*' + escapedSelector + '\\s*\\{([^{}]*)\\}', 'm')
    );

    assert.ok(match, `CSS rule ${selector} must exist`);
    return match[1];
}

function relativeLuminance(hexColor) {
    const channels = hexColor.match(/[0-9a-f]{2}/gi).map(function (value) {
        const channel = parseInt(value, 16) / 255;

        return channel <= 0.04045
            ? channel / 12.92
            : Math.pow((channel + 0.055) / 1.055, 2.4);
    });

    return (
        0.2126 * channels[0]
        + 0.7152 * channels[1]
        + 0.0722 * channels[2]
    );
}

function contrastRatio(first, second) {
    const firstLuminance = relativeLuminance(first);
    const secondLuminance = relativeLuminance(second);
    const lighter = Math.max(firstLuminance, secondLuminance);
    const darker = Math.min(firstLuminance, secondLuminance);

    return (lighter + 0.05) / (darker + 0.05);
}

function test(name, callback) {
    try {
        callback();
        process.stdout.write(`ok - ${name}\n`);
    } catch (error) {
        process.stderr.write(`not ok - ${name}\n`);
        throw error;
    }
}

class ClassList {
    constructor(element) {
        this.element = element;
        this.names = new Set();
    }

    add(...names) {
        names.forEach((name) => this.names.add(name));
        this.sync();
    }

    remove(...names) {
        names.forEach((name) => this.names.delete(name));
        this.sync();
    }

    contains(name) {
        return this.names.has(name);
    }

    toggle(name, force) {
        const enabled = force === undefined ? !this.names.has(name) : force;

        if (enabled) {
            this.names.add(name);
        } else {
            this.names.delete(name);
        }

        this.sync();
        return enabled;
    }

    replaceFromString(value) {
        this.names = new Set(String(value).split(/\s+/).filter(Boolean));
        this.sync();
    }

    sync() {
        this.element._className = Array.from(this.names).join(' ');
    }
}

class FakeElement {
    constructor(tagName, ownerDocument) {
        this.tagName = String(tagName).toUpperCase();
        this.ownerDocument = ownerDocument;
        this.attributes = new Map();
        this.children = [];
        this.parentNode = null;
        this.dataset = {};
        this.eventListeners = new Map();
        this.textContent = '';
        this._className = '';
        this.classList = new ClassList(this);
    }

    set id(value) {
        const nextId = String(value);
        this.attributes.set('id', nextId);
        this.ownerDocument.elementsById.set(nextId, this);
    }

    get id() {
        return this.attributes.get('id') || '';
    }

    set className(value) {
        this.classList.replaceFromString(value);
    }

    get className() {
        return this._className;
    }

    setAttribute(name, value) {
        const stringValue = String(value);
        this.attributes.set(name, stringValue);

        if (name === 'id') {
            this.ownerDocument.elementsById.set(stringValue, this);
        }
    }

    getAttribute(name) {
        return this.attributes.has(name) ? this.attributes.get(name) : null;
    }

    appendChild(child) {
        child.parentNode = this;
        this.children.push(child);
        return child;
    }

    removeChild(child) {
        const index = this.children.indexOf(child);

        if (index >= 0) {
            this.children.splice(index, 1);
            child.parentNode = null;
        }

        return child;
    }

    remove() {
        if (this.parentNode) {
            this.parentNode.removeChild(this);
        }
    }

    addEventListener(type, callback) {
        const listeners = this.eventListeners.get(type) || [];
        listeners.push(callback);
        this.eventListeners.set(type, listeners);
    }

    click() {
        (this.eventListeners.get('click') || []).forEach((callback) => {
            callback({currentTarget: this, preventDefault() {}});
        });
    }

    querySelector(selector) {
        return this.querySelectorAll(selector)[0] || null;
    }

    querySelectorAll(selector) {
        const matches = [];
        const isClass = selector.startsWith('.');
        const expected = isClass ? selector.slice(1) : selector.toUpperCase();

        function visit(element) {
            element.children.forEach((child) => {
                const match = isClass
                    ? child.classList.contains(expected)
                    : child.tagName === expected;

                if (match) {
                    matches.push(child);
                }

                visit(child);
            });
        }

        visit(this);
        return matches;
    }
}

class FakeDocument {
    constructor() {
        this.elementsById = new Map();
    }

    createElement(tagName) {
        return new FakeElement(tagName, this);
    }

    getElementById(id) {
        return this.elementsById.get(id) || null;
    }
}

function storageFor(values = new Map()) {
    return {
        values,
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

function createPage(options = {}) {
    const document = new FakeDocument();
    const container = document.createElement('div');
    container.id = 'anabelka-notifications';
    container.dataset.closeLabel = 'Закрити сповіщення';

    const bootstrap = document.createElement('script');
    bootstrap.id = 'anabelka-notifications-bootstrap';
    bootstrap.textContent = JSON.stringify(options.bootstrap || []);

    const timers = [];
    const window = {
        document,
        sessionStorage: storageFor(options.storage),
        setTimeout(callback, delay) {
            const timer = {
                callback,
                delay,
                id: timers.length + 1,
                cleared: false
            };
            timers.push(timer);
            return timer.id;
        },
        clearTimeout(id) {
            const timer = timers.find((item) => item.id === id);

            if (timer) {
                timer.cleared = true;
            }
        }
    };
    window.window = window;

    vm.runInNewContext(
        read('js/anabelka-notifications.js'),
        {document, window}
    );

    return {
        bootstrap,
        container,
        document,
        storage: window.sessionStorage.values,
        timers,
        window
    };
}

test('success renders through the shared accessible component', function () {
    assert.ok(
        fs.existsSync(filePath('js/anabelka-notifications.js')),
        'shared browser notification module must exist'
    );

    const page = createPage();

    page.window.AnabelkaNotify.success('Категорію збережено.');

    assert.equal(page.container.children.length, 1);
    const notification = page.container.children[0];
    assert.ok(notification.classList.contains('anabelka-notification'));
    assert.ok(notification.classList.contains('anabelka-notification--success'));
    assert.equal(notification.getAttribute('role'), 'status');
    assert.equal(notification.getAttribute('aria-live'), 'polite');
    assert.equal(notification.getAttribute('aria-atomic'), 'true');
    assert.equal(
        notification.querySelector('.anabelka-notification__message')?.textContent,
        'Категорію збережено.'
    );
    assert.equal(page.timers.at(-1)?.delay, 2800);
});

test('types share one structure and use centralized accents and durations', function () {
    const cases = [
        ['success', 'status', 'polite', 2800, false],
        ['info', 'status', 'polite', 3000, false],
        ['warning', 'status', 'polite', 4000, true],
        ['error', 'alert', 'assertive', 5000, true]
    ];

    cases.forEach(function ([type, role, live, duration, hasClose]) {
        const page = createPage();

        page.window.AnabelkaNotify[type](`Message ${type}`);

        assert.equal(page.container.children.length, 1);
        const notification = page.container.children[0];
        assert.ok(notification.classList.contains('anabelka-notification'));
        assert.ok(notification.classList.contains(`anabelka-notification--${type}`));
        assert.equal(notification.getAttribute('role'), role);
        assert.equal(notification.getAttribute('aria-live'), live);
        assert.equal(
            notification.querySelector('.anabelka-notification__accent')?.getAttribute('aria-hidden'),
            'true'
        );
        assert.equal(
            Boolean(notification.querySelector('.anabelka-notification__close')),
            hasClose
        );
        assert.equal(page.timers.at(-1)?.delay, duration);

        if (hasClose) {
            assert.equal(
                notification.querySelector('.anabelka-notification__close')?.getAttribute('aria-label'),
                'Закрити сповіщення'
            );
        }
    });
});

test('notifications use one FIFO queue and suppress active or queued duplicates', function () {
    const page = createPage();
    const notify = page.window.AnabelkaNotify;

    assert.equal(notify.info('First message'), true);
    assert.equal(notify.error('Second message'), true);
    assert.equal(notify.error('Second message'), false);

    assert.equal(page.container.children.length, 1);
    assert.equal(
        page.container.children[0]
            .querySelector('.anabelka-notification__message')?.textContent,
        'First message'
    );

    page.timers[0].callback();

    assert.equal(page.container.children.length, 1);
    const secondNotification = page.container.children[0];
    assert.equal(
        secondNotification.querySelector('.anabelka-notification__message')?.textContent,
        'Second message'
    );
    assert.equal(page.timers.at(-1)?.delay, 5000);

    secondNotification.querySelector('.anabelka-notification__close')?.click();

    assert.equal(page.container.children.length, 0);
    assert.equal(page.timers.at(-1)?.cleared, true);
    assert.equal(notify.error('Second message'), true);
    assert.equal(page.container.children.length, 1);
    assert.equal(notify.dismiss(), true);
    assert.equal(page.container.children.length, 0);
    assert.equal(notify.dismiss(), false);
});

test('show supports persistent notifications and safe duration overrides', function () {
    const persistentPage = createPage();
    const notify = persistentPage.window.AnabelkaNotify;

    assert.equal(typeof notify.show, 'function');
    assert.equal(
        notify.show('success', 'Requires confirmation', {persistent: true}),
        true
    );
    assert.equal(persistentPage.timers.length, 0);
    assert.ok(
        persistentPage.container.children[0]
            .querySelector('.anabelka-notification__close')
    );
    assert.equal(notify.dismiss(), true);
    assert.equal(notify.show('unknown', 'Ignored'), false);
    assert.equal(notify.show('info', '   '), false);

    const timedPage = createPage();
    assert.equal(
        timedPage.window.AnabelkaNotify.info('Custom duration', {duration: 1250}),
        true
    );
    assert.equal(timedPage.timers.at(-1)?.delay, 1250);

    const fallbackPage = createPage();
    fallbackPage.window.AnabelkaNotify.error('Default duration', {duration: -1});
    assert.equal(fallbackPage.timers.at(-1)?.delay, 5000);
});

test('types use a normalized own-value allowlist and messages match PHP parity', function () {
    const page = createPage();
    const notify = page.window.AnabelkaNotify;

    ['constructor', '__proto__', 'toString'].forEach(function (type) {
        assert.equal(notify.show(type, 'Must be rejected'), false);
        assert.equal(notify.flash(type, 'Must be rejected'), false);
    });
    assert.equal(page.container.children.length, 0);
    assert.equal(page.timers.length, 0);

    assert.equal(notify.show(' SUCCESS ', 0), true);
    const notification = page.container.children[0];
    assert.ok(notification.classList.contains('anabelka-notification--success'));
    assert.equal(
        notification.querySelector('.anabelka-notification__message')?.textContent,
        '0'
    );
    assert.equal(page.timers.at(-1)?.delay, 2800);
});

test('client flash survives navigation and is consumed exactly once', function () {
    const sharedStorage = new Map();
    const firstPage = createPage({storage: sharedStorage});
    const flash = firstPage.window.AnabelkaNotify.flash;

    assert.equal(typeof flash, 'function');
    assert.equal(flash('success', 'Saved after redirect'), true);
    assert.equal(flash('success', 'Saved after redirect'), false);
    assert.equal(firstPage.container.children.length, 0);
    assert.equal(sharedStorage.size, 1);

    const redirectedPage = createPage({storage: sharedStorage});

    assert.equal(sharedStorage.size, 0);
    assert.equal(redirectedPage.container.children.length, 1);
    assert.equal(
        redirectedPage.container.children[0]
            .querySelector('.anabelka-notification__message')?.textContent,
        'Saved after redirect'
    );
    assert.equal(redirectedPage.timers.at(-1)?.delay, 2800);

    const refreshedPage = createPage({storage: sharedStorage});
    assert.equal(refreshedPage.container.children.length, 0);
});

test('deduplication spans stored, active, and queued notification states', function () {
    const storedFirst = createPage({storage: new Map()});
    const storedNotify = storedFirst.window.AnabelkaNotify;

    assert.equal(storedNotify.flash('success', 'Same event'), true);
    assert.equal(storedNotify.success('Same event'), false);
    assert.equal(storedFirst.container.children.length, 0);

    const shownFirst = createPage({storage: new Map()});
    const shownNotify = shownFirst.window.AnabelkaNotify;

    assert.equal(shownNotify.success('Same event'), true);
    assert.equal(shownNotify.flash('success', 'Same event'), false);
    assert.equal(shownFirst.storage.size, 0);
});

test('malformed client flash is removed without displaying a message', function () {
    const sharedStorage = new Map([
        ['anabelka.notifications.flash.v1', '{invalid json']
    ]);
    const page = createPage({storage: sharedStorage});

    assert.equal(sharedStorage.size, 0);
    assert.equal(page.container.children.length, 0);
});

test('PHP bootstrap and client flash enter the same deduplicated queue', function () {
    const sharedStorage = new Map();
    const sourcePage = createPage({storage: sharedStorage});
    sourcePage.window.AnabelkaNotify.flash('info', 'Shared message');
    sourcePage.window.AnabelkaNotify.flash('success', 'Client message');

    const page = createPage({
        bootstrap: [
            {type: 'info', message: 'Shared message'},
            {type: 'error', message: 'Server message'},
            {type: 'unknown', message: 'Ignored message'},
            {type: 'warning', message: '   '}
        ],
        storage: sharedStorage
    });

    assert.equal(page.bootstrap.textContent, '');
    assert.equal(sharedStorage.size, 0);
    assert.equal(
        page.container.children[0]
            .querySelector('.anabelka-notification__message')?.textContent,
        'Shared message'
    );

    page.timers[0].callback();
    assert.equal(
        page.container.children[0]
            .querySelector('.anabelka-notification__message')?.textContent,
        'Server message'
    );

    page.timers[1].callback();
    assert.equal(
        page.container.children[0]
            .querySelector('.anabelka-notification__message')?.textContent,
        'Client message'
    );

    page.timers[2].callback();
    assert.equal(page.container.children.length, 0);
});

test('CSS keeps every type in one mobile-safe purple top-center overlay', function () {
    assert.ok(
        fs.existsSync(filePath('css/anabelka-notifications.css')),
        'shared notification stylesheet must exist'
    );

    const css = read('css/anabelka-notifications.css');
    const container = cssRuleBody(css, '.anabelka-notifications');
    const notification = cssRuleBody(css, '.anabelka-notification');
    const close = cssRuleBody(css, '.anabelka-notification__close');

    assert.match(container, /position:\s*fixed/i);
    assert.match(container, /left:\s*50%/i);
    assert.match(container, /top:\s*calc\([^;]*safe-area-inset-top/i);
    assert.match(container, /transform:\s*translateX\(-50%\)/i);
    assert.doesNotMatch(container, /(?:^|;)\s*(?:right|bottom)\s*:/i);
    assert.match(container, /z-index:\s*(?:[1-9]\d{3,}|[1-9]\d{4,})/i);

    assert.match(notification, /box-sizing:\s*border-box/i);
    assert.match(notification, /width:\s*max-content/i);
    assert.match(
        notification,
        /max-width:\s*min\(420px,\s*calc\(100vw\s*-\s*24px\)\)/i
    );
    assert.match(notification, /background:\s*#[0-9a-f]{6}/i);
    assert.match(notification, /color:\s*#fff(?:fff)?/i);
    assert.match(notification, /overflow-wrap:\s*anywhere/i);
    assert.match(notification, /word-break:\s*break-word/i);

    ['success', 'info', 'warning', 'error'].forEach(function (type) {
        const modifier = cssRuleBody(
            css,
            `.anabelka-notification--${type}`
        );
        assert.match(modifier, /--anabelka-notification-accent:/i);
        assert.doesNotMatch(modifier, /(?:^|;)\s*background(?:-color)?\s*:/i);
    });

    assert.match(close, /min-width:\s*44px/i);
    assert.match(close, /min-height:\s*44px/i);
    assert.match(css, /@media\s*\(prefers-reduced-motion:\s*reduce\)/i);

    const background = notification.match(/background:\s*(#[0-9a-f]{6})/i)?.[1];
    const errorAccent = cssRuleBody(
        css,
        '.anabelka-notification--error'
    ).match(/--anabelka-notification-accent:\s*(#[0-9a-f]{6})/i)?.[1];
    assert.ok(background && errorAccent);
    assert.ok(
        contrastRatio(background, errorAccent) >= 3,
        'error accent must have at least 3:1 contrast against the purple base'
    );
});

test('PHP bridge owns a separate one-shot session namespace and generic API', function () {
    assert.ok(
        fs.existsSync(filePath('app/Core/AnabelkaFlash.php')),
        'PHP session flash bridge must exist'
    );

    const bridge = read('app/Core/AnabelkaFlash.php');
    const app = read('app/Core/App.php');

    assert.match(bridge, /final\s+class\s+AnabelkaFlash/);
    assert.match(
        bridge,
        /private\s+const\s+SESSION_KEY\s*=\s*'anabelka_flash'/
    );
    ['push', 'success', 'info', 'warning', 'error', 'consume'].forEach(
        function (method) {
            assert.match(
                bridge,
                new RegExp('public\\s+static\\s+function\\s+' + method + '\\s*\\(')
            );
        }
    );
    assert.match(bridge, /\$_SESSION\[self::SESSION_KEY\]/);
    assert.match(bridge, /unset\(\$_SESSION\[self::SESSION_KEY\]\)/);
    assert.match(bridge, /\['success',\s*'info',\s*'warning',\s*'error'\]/);
    assert.match(
        bridge,
        /if\s*\(\s*!is_string\(\$item\['type'\]\s*\?\?\s*null\)\s*\)\s*\{\s*return null;/
    );
    assert.doesNotMatch(bridge, /Категор|Достав|Товар|Замовлен|Заказ/u);
    assert.match(app, /require_once\s+__DIR__\s*\.\s*'\/AnabelkaFlash\.php'/);
});

test('PHP bridge has an executable behavior suite', function () {
    const runtimeTest = filePath('tests/anabelka_flash_runtime.php');
    assert.ok(fs.existsSync(runtimeTest));

    const phpVersion = spawnSync('php', ['-v'], {encoding: 'utf8'});

    if (phpVersion.error && phpVersion.error.code === 'ENOENT') {
        process.stdout.write('skip - PHP CLI is unavailable in this environment\n');
        return;
    }

    assert.equal(phpVersion.status, 0, phpVersion.stderr);
    const result = spawnSync('php', [runtimeTest], {encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    assert.match(result.stdout, /anabelka flash runtime checks passed/);
});

test('one guarded partial connects both header paths to one DOM container', function () {
    assert.ok(
        fs.existsSync(filePath('views/partials/anabelka-notifications.php')),
        'shared notification partial must exist'
    );

    const partial = read('views/partials/anabelka-notifications.php');
    const publicHeader = read('views/partials/header.php');
    const adminHeader = read('views/admin/partials/header.php');

    assert.match(
        partial,
        /defined\('ANABELKA_NOTIFICATIONS_PARTIAL_RENDERED'\)/
    );
    assert.match(
        partial,
        /define\('ANABELKA_NOTIFICATIONS_PARTIAL_RENDERED',\s*true\)/
    );
    assert.match(partial, /AnabelkaFlash::consume\(\)/);
    assert.match(partial, /JSON_HEX_TAG/);
    assert.equal(
        (partial.match(/id="anabelka-notifications"/g) || []).length,
        1
    );
    assert.equal(
        (partial.match(/id="anabelka-notifications-bootstrap"/g) || []).length,
        1
    );
    assert.equal(
        (partial.match(/css\/anabelka-notifications\.css\?v=1/g) || []).length,
        1
    );
    assert.equal(
        (partial.match(/js\/anabelka-notifications\.js\?v=1/g) || []).length,
        1
    );
    assert.ok(
        partial.indexOf('id="anabelka-notifications"')
            < partial.indexOf('js/anabelka-notifications.js?v=1')
    );
    assert.match(
        publicHeader,
        /require\s+__DIR__\s*\.\s*'\/anabelka-notifications\.php'/
    );
    assert.match(
        adminHeader,
        /require\s+__DIR__\s*\.\s*'\/\.\.\/\.\.\/partials\/anabelka-notifications\.php'/
    );
});
