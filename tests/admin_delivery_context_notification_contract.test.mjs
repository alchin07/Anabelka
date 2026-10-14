import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import {fileURLToPath} from 'node:url';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = p => fs.readFileSync(path.join(root, p), 'utf8');

test('toggle messages show the actual delivery item name, including after editing', () => {
    const actions = read('views/admin/delivery/partials/action-buttons.php');
    const toggle = read('js/admin-delivery/toggle.js');
    const edit = read('js/admin-delivery/edit.js');
    assert.match(actions, /data-name="<\?= htmlspecialchars\(\s*\$itemName/);
    assert.match(toggle, /form\.dataset\.name/);
    assert.match(toggle, /'увімкнено' : 'вимкнено'/);
    assert.match(edit, /toggleForm\.dataset\.name = editName\.value/);
});

test('Groq visibility depends on opened delivery method/service forms only', () => {
    const src = read('js/admin-ai-translation.js');
    assert.match(src, /path === '\/Anabelka\/admin\/delivery'/);
    assert.match(src, /\['method', 'service'\]\.includes\(editType\.value\)/);
    for (const id of ['delivery-edit-modal','delivery-add-modal','delivery-add-service-modal']) {
        assert.ok(src.includes("'" + id + "'"));
    }
    assert.match(src, /new MutationObserver\(syncSwitcherVisibility\)\.observe\(modal/);
    const start=src.indexOf('function pageAllowsSwitcher()');
    const end=src.indexOf('function syncSwitcherVisibility()', start);
    assert.ok(start>=0 && end>start);
    const elements={'delivery-edit-modal':{hidden:true},'edit-type':{value:'method'},
        'delivery-add-modal':{hidden:true},'delivery-add-service-modal':{hidden:true}};
    const window={location:{pathname:'/Anabelka/admin/delivery'}};
    const document={getElementById:id=>elements[id]||null};
    const fn=new Function('window','document',
        "function pagePath(){return window.location.pathname.replace(/\\/$/, '');}\n"
        + src.slice(start,end) + '\nreturn pageAllowsSwitcher();');
    assert.equal(fn(window,document),false);
    elements['delivery-edit-modal'].hidden=false;
    assert.equal(fn(window,document),true);
    elements['edit-type'].value='option';
    assert.equal(fn(window,document),false);
    elements['delivery-edit-modal'].hidden=true;
    elements['delivery-add-modal'].hidden=false;
    assert.equal(fn(window,document),true);
    elements['delivery-add-modal'].hidden=true;
    elements['delivery-add-service-modal'].hidden=false;
    assert.equal(fn(window,document),true);
    elements['delivery-add-service-modal'].hidden=true;
    assert.equal(fn(window,document),false);
});

test('Android caches load new versions of delivery and AI scripts', () => {
    assert.match(read('js/admin-nav.js'),/admin-ai-translation\.js\?v=13/);
    assert.match(read('views/admin/partials/header.php'),/admin-nav\.js\?v=29/);
    assert.match(read('views/admin/delivery/index.php'),/admin-delivery\/toggle\.js\?v=3/);
    assert.match(read('views/admin/delivery/index.php'),/admin-delivery\/edit\.js\?v=9/);
});
