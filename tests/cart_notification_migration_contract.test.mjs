import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import {fileURLToPath} from 'node:url';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = p => fs.readFileSync(path.join(projectRoot, p), 'utf8');

test('cart has branded host and one shared notification queue', () => {
    const view = read('views/cart/index.php');
    assert.match(view, /class="site-message anabelka-notify"/);
    assert.match(view, /role="status" aria-live="polite"/);
    assert.equal((view.match(/JSON_HEX_TAG/g) || []).length, 4);
    assert.match(view, /function showMessage\(text, type = 'error'\)/);
    assert.match(view, /notify\.show\(type, text\)/);
    assert.match(view, /notify\.store\('success', cartMessages\.removed\)/);
    assert.doesNotMatch(view, /siteMessageTimer/);
    assert.doesNotMatch(view, /classList\.add\('show'\)/);
});

test('cart removal confirms, last item reloads and quantity changes stay quiet', () => {
    const html = read('views/cart/index.php');
    const script = html.match(/<script>([\s\S]*?)<\/script>/);
    assert.ok(script, 'Cart action script missing');
    const source = script[1].replace(/<\?=\s*json_encode\([\s\S]*?\)\s*\?>/g,
        '"Товар видалено з кошика."');
    const notices = [];
    let isEmpty = false;
    let reloads = 0;
    const window = {AnabelkaNotify: {
        show(type, message) {notices.push({method:'show',type,message});return true;},
        store(type, message) {notices.push({method:'store',type,message});return true;}
    }, location: {reload(){reloads++;}}, setTimeout() {throw new Error('Unexpected fallback');}};
    const document = {
        querySelectorAll(){return [];},
        querySelector(selector){return selector === '.cart-item' && !isEmpty ? {} : null;},
        getElementById(){return null;}
    };
    const sandbox={window,document,FormData:class {}};
    vm.runInNewContext(source, sandbox);
    sandbox.showMessage('Помилка', 'error');
    assert.equal(notices.at(-1).type,'error');
    sandbox.announceRemoved();
    assert.deepEqual(JSON.stringify(notices.at(-1)),
        JSON.stringify({method:'show',type:'success',message:'Товар видалено з кошика.'}));
    assert.equal(reloads,0);
    isEmpty=true;
    sandbox.announceRemoved();
    assert.equal(notices.at(-1).method,'store');
    assert.equal(notices.at(-1).type,'success');
    assert.equal(reloads,1);
    assert.match(source, /if \(currentQuantity <= 1\)\s*\{\s*announceRemoved\(\)/);
    assert.match(source, /item\.remove\(\);[\s\S]*?announceRemoved\(\)/);
});

test('server error localization preserves severity', () => {
    const i18n=read('views/partials/cart-server-i18n.php');
    assert.match(i18n, /window\.showMessage = function \(text, type = 'error'\)/);
    assert.match(i18n, /baseShowMessage\(translated, type\)/);
    assert.match(i18n, /t\.color_sold_out/);
    assert.match(i18n, /t\.change_error/);
    assert.match(i18n, /JSON_HEX_TAG/);
});

test('legacy cart color repair no longer uses browser alerts', () => {
    const color=read('js/cart-legacy-color.js');
    assert.doesNotMatch(color, /window\.alert\(/);
    assert.match(color, /notifyColor\('error', result\.message/);
    assert.match(color, /notifyColor\('success', 'Колір товару збережено\.', true\)/);
    assert.match(color, /anabelka-notify-inline is-warning/);
    assert.match(read('views/partials/header.php'),/cart-legacy-color\.js\?v=3/);
});
