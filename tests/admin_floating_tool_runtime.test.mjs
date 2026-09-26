import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import {fileURLToPath} from 'node:url';

const testDirectory = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(testDirectory, '..');
const source = fs.readFileSync(
    path.join(projectRoot, 'js/anabelka-floating-tool.js'),
    'utf8'
);

function createHarness({
    width = 300,
    height = 200,
    rootWidth = 100,
    rootHeight = 40
} = {}) {
    const storage = new Map();
    const windowListeners = {};
    const handleListeners = {};
    const classes = new Set();
    const styleValues = {};
    let capturedPointer = null;
    let defaultLeft = 200;
    let defaultTop = 50;

    const style = {
        set left(value) { styleValues.left = value; },
        get left() { return styleValues.left || ''; },
        set top(value) { styleValues.top = value; },
        get top() { return styleValues.top || ''; },
        set right(value) { styleValues.right = value; },
        get right() { return styleValues.right || ''; },
        set bottom(value) { styleValues.bottom = value; },
        get bottom() { return styleValues.bottom || ''; },
        removeProperty(name) { delete styleValues[name]; }
    };

    const handle = {
        addEventListener(type, callback) {
            handleListeners[type] = callback;
        },
        setPointerCapture(pointerId) {
            capturedPointer = pointerId;
        },
        hasPointerCapture(pointerId) {
            return capturedPointer === pointerId;
        },
        releasePointerCapture(pointerId) {
            if (capturedPointer === pointerId) {
                capturedPointer = null;
            }
        }
    };

    const root = {
        id: 'test-floating-tool',
        dataset: {anabelkaFloatingKey: 'test-tool'},
        style,
        classList: {
            add(name) { classes.add(name); },
            remove(name) { classes.delete(name); }
        },
        querySelector(selector) {
            return selector === '[data-anabelka-drag-handle]'
                ? handle
                : null;
        },
        getBoundingClientRect() {
            const left = styleValues.left
                ? Number.parseFloat(styleValues.left)
                : defaultLeft;
            const top = styleValues.top
                ? Number.parseFloat(styleValues.top)
                : defaultTop;

            return {
                left,
                top,
                width: rootWidth,
                height: rootHeight,
                right: left + rootWidth,
                bottom: top + rootHeight
            };
        }
    };

    const documentElement = {
        clientWidth: width,
        clientHeight: height
    };
    const document = {
        readyState: 'complete',
        documentElement,
        querySelector() { return null; },
        querySelectorAll() { return []; }
    };
    const window = {
        innerWidth: width,
        innerHeight: height,
        localStorage: {
            getItem(key) {
                return storage.has(key) ? storage.get(key) : null;
            },
            setItem(key, value) { storage.set(key, value); },
            removeItem(key) { storage.delete(key); }
        },
        requestAnimationFrame(callback) { callback(); },
        addEventListener(type, callback) {
            windowListeners[type] = callback;
        }
    };

    const context = vm.createContext({
        window,
        document,
        console,
        Map,
        JSON,
        Math,
        Number,
        Promise
    });
    vm.runInContext(source, context);

    return {
        api: window.AnabelkaFloatingTool,
        root,
        handleListeners,
        styleValues,
        storage,
        window,
        documentElement,
        windowListeners
    };
}

function pointerEvent(overrides = {}) {
    return {
        pointerId: 1,
        pointerType: 'mouse',
        button: 0,
        clientX: 0,
        clientY: 0,
        preventDefault() {},
        ...overrides
    };
}

test('drag clamps to viewport, persists, reclamps on resize, and resets', () => {
    const harness = createHarness();
    const controller = harness.api.register(
        harness.root,
        {storageKey: 'runtime'}
    );

    assert.ok(controller);

    harness.handleListeners.pointerdown(
        pointerEvent({clientX: 210, clientY: 60})
    );
    harness.handleListeners.pointermove(
        pointerEvent({clientX: 1000, clientY: 1000})
    );
    harness.handleListeners.pointerup(
        pointerEvent({clientX: 1000, clientY: 1000})
    );

    assert.equal(harness.styleValues.left, '194px');
    assert.equal(harness.styleValues.top, '154px');
    assert.equal(
        harness.storage.get('anabelka-floating-tool:runtime'),
        JSON.stringify({left: 194, top: 154})
    );

    harness.window.innerWidth = 250;
    harness.documentElement.clientWidth = 250;
    harness.windowListeners.resize();

    assert.equal(harness.styleValues.left, '144px');
    assert.equal(harness.styleValues.top, '154px');

    harness.api.reset(harness.root);
    assert.equal(harness.styleValues.left, undefined);
    assert.equal(harness.styleValues.top, undefined);
    assert.equal(
        harness.storage.has('anabelka-floating-tool:runtime'),
        false
    );
});

test('zero-sized hidden tool is not registered prematurely', () => {
    const harness = createHarness({rootWidth: 0, rootHeight: 0});

    assert.equal(harness.api.register(harness.root), null);
    assert.equal(Object.keys(harness.handleListeners).length, 0);
});
