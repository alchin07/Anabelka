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
    assert.match(view, /id="cart-filled-content"/);
    assert.match(view, /id="empty-cart-message"/);
    assert.doesNotMatch(view, /notify\.store\('success', cartMessages\.removed\)/);
    assert.doesNotMatch(view, /window\.location\.reload\(\)/);
    assert.doesNotMatch(view, /siteMessageTimer/);
    assert.doesNotMatch(view, /classList\.add\('show'\)/);
});

test('cart last-item removal shows an immediate toast and a live empty state', () => {
    const view=read('views/cart/index.php');
    const script=view.match(/<script>([\s\S]*?)<\/script>/);
    assert.ok(script);
    const source=script[1].replace(/<\?=\s*json_encode\([\s\S]*?\)\s*\?>/g,
        '"Товар видалено з кошика."');
    const notifications=[];
    const filled={hidden:false}, empty={hidden:true};
    let hasItems=true;
    const window={AnabelkaNotify:{
        show(type,message){notifications.push({type,message});return true;},
        store(){throw new Error('Do not store cart deletion flash');}
    }, location:{reload(){throw new Error('Do not navigate on deletion');}}};
    const document={
        querySelectorAll(){return []},
        querySelector(selector){
            return selector==='.cart-item' && hasItems ? {} : null;
        },
        getElementById(id){
            return id==='cart-filled-content' ? filled
                : id==='empty-cart-message' ? empty : null;
        }
    };
    const sandbox={window,document,FormData:class {}};
    vm.runInNewContext(source,sandbox);

    sandbox.announceRemoved();
    assert.equal(notifications.length,1);
    assert.equal(notifications[0].type,'success');
    assert.equal(filled.hidden,false);
    assert.equal(empty.hidden,true);

    hasItems=false;
    sandbox.announceRemoved();
    assert.equal(notifications.length,2);
    assert.equal(notifications[1].type,'success');
    assert.equal(filled.hidden,true);
    assert.equal(empty.hidden,false);

    assert.match(source,/if \(currentQuantity <= 1\)\s*\{\s*announceRemoved\(\)/);
    assert.match(source,/item\.remove\(\);[\s\S]*?announceRemoved\(\)/);
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
