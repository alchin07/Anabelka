import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import {fileURLToPath} from 'node:url';

const testDirectory = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(testDirectory, '..');
const filePath = (relativePath) => path.join(projectRoot, relativePath);
const read = (relativePath) => fs.readFileSync(filePath(relativePath), 'utf8');

test('admin floating tools use one reusable drag-and-persist module', () => {
    assert.ok(
        fs.existsSync(filePath('js/anabelka-floating-tool.js')),
        'shared floating-tool JS module must exist'
    );
    assert.ok(
        fs.existsSync(filePath('css/anabelka-floating-tool.css')),
        'shared floating-tool CSS module must exist'
    );

    const js = read('js/anabelka-floating-tool.js');
    const css = read('css/anabelka-floating-tool.css');

    assert.match(js, /window\.AnabelkaFloatingTool/);
    assert.match(js, /register\s*:/);
    assert.match(js, /reset\s*:/);
    assert.match(js, /\[data-anabelka-floating-tool\]/);
    assert.match(js, /data-anabelka-drag-handle/);
    assert.match(js, /pointerdown/);
    assert.match(js, /pointermove/);
    assert.match(js, /pointerup/);
    assert.match(js, /setPointerCapture/);
    assert.match(js, /localStorage/);
    assert.match(js, /Math\.max/);
    assert.match(js, /Math\.min/);
    assert.match(js, /resize/);
    assert.match(js, /initialRect\.width[\s\S]*initialRect\.height/);

    assert.match(css, /\.anabelka-floating-drag-handle/);
    assert.match(css, /touch-action:\s*none/);
    assert.match(css, /cursor:\s*grab/);
});

test('AI provider panel registers as a reusable floating tool', () => {
    const ai = read('js/admin-ai-translation.js');

    assert.match(ai, /anabelka-floating-tool\.css\?v=/);
    assert.match(ai, /anabelka-floating-tool\.js\?v=/);
    assert.match(ai, /data-anabelka-floating-tool/);
    assert.match(ai, /data-anabelka-floating-key/);
    assert.match(ai, /anabelka-floating-drag-handle/);
    assert.match(ai, /data-anabelka-drag-handle/);
    assert.match(ai, /(?:AnabelkaFloatingTool|floatingTool)\.register/);
    assert.match(ai, /storageKey:\s*['"]ai-provider['"]/);
});

test('admin header cache-busts the nav loader that enables floating AI tools', () => {
    const header = read('views/admin/partials/header.php');
    const nav = read('js/admin-nav.js');

    assert.match(header, /admin-nav\.js\?v=20/);
    assert.match(nav, /admin-ai-translation\.js\?v=9/);
});


test('AI switcher visibility follows category edit and product translations', () => {
    const ai = read('js/admin-ai-translation.js');

    assert.match(ai, /function\s+pageAllowsSwitcher\s*\(/);
    assert.match(
        ai,
        /path\s*===\s*['"]\/Anabelka\/admin\/categories['"][\s\S]*?category-edit-modal[\s\S]*?!modal\.hidden/
    );
    assert.match(
        ai,
        /path\s*===\s*['"]\/Anabelka\/admin\/products['"][\s\S]*?product-editor[\s\S]*?data-translation-details[\s\S]*?translations\.open/
    );
    assert.match(ai, /function\s+syncSwitcherVisibility\s*\(/);
    assert.match(ai, /switcher\.hidden\s*=\s*!visible/);
    assert.match(ai, /MutationObserver\(syncSwitcherVisibility\)/);
    assert.match(ai, /attributeFilter:\s*\['hidden'\]/);
    assert.match(ai, /attributeFilter:\s*\['open'\]/);
    assert.match(ai, /translations\.addEventListener\([\s\S]*?['"]toggle['"]/);
    assert.doesNotMatch(
        ai,
        /renderProviders[\s\S]*?switcher\.hidden\s*=\s*false[\s\S]*?activateFloatingSwitcher/
    );
});
