import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

function source(path) {
    return readFileSync(new URL('../' + path, import.meta.url), 'utf8');
}

const deliveryFiles = [
    'js/admin-delivery/add.js',
    'js/admin-delivery/add-service.js',
    'js/admin-delivery/add-option.js',
    'js/admin-delivery/edit.js',
    'js/admin-delivery/delete.js',
    'js/admin-delivery/toggle.js',
    'js/admin-delivery-ai-translation.js'
];

test('delivery page no longer renders the legacy site-message container', () => {
    const page = source('views/admin/delivery/index.php');
    assert.equal(page.includes('id="site-message"'), false);
});

test('delivery common helper no longer defines the legacy showMessage API', () => {
    const common = source('js/admin-delivery/common.js');
    assert.equal(common.includes('window.showMessage'), false);
    assert.equal(common.includes('site-message'), false);
});

test('delivery scripts no longer consume showMessage or browser alert', () => {
    for (const path of deliveryFiles) {
        const file = source(path);
        assert.equal(file.includes('showMessage('), false, path);
        assert.equal(file.includes('window.showMessage'), false, path);
        assert.equal(file.includes('window.alert('), false, path);
    }
});

test('create and delete flows persist success notifications across reload', () => {
    for (const path of [
        'js/admin-delivery/add.js',
        'js/admin-delivery/add-service.js',
        'js/admin-delivery/add-option.js',
        'js/admin-delivery/delete.js'
    ]) {
        const file = source(path);
        assert.match(file, /AnabelkaNotify\.flash\(\s*'success'/, path);
        assert.match(file, /AnabelkaNotify\.error\(/, path);
    }
});

test('add option reports partial-save state as a warning flash', () => {
    const file = source('js/admin-delivery/add-option.js');
    assert.match(file, /AnabelkaNotify\.flash\(\s*'warning'/);
});

test('edit and toggle use typed immediate notifications without reload flash', () => {
    const edit = source('js/admin-delivery/edit.js');
    const toggle = source('js/admin-delivery/toggle.js');

    assert.match(edit, /AnabelkaNotify\.success\(/);
    assert.match(edit, /AnabelkaNotify\.error\(/);
    assert.equal(edit.includes('AnabelkaNotify.flash('), false);

    assert.match(toggle, /AnabelkaNotify\.success\(/);
    assert.match(toggle, /AnabelkaNotify\.error\(/);
    assert.equal(toggle.includes('AnabelkaNotify.flash('), false);
});

test('delivery AI draft states use info and failures use error', () => {
    const ai = source('js/admin-delivery-ai-translation.js');
    assert.match(ai, /AnabelkaNotify\.info\(/);
    assert.match(ai, /AnabelkaNotify\.error\(/);
});
