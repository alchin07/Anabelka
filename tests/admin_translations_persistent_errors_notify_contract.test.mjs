import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import {fileURLToPath} from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = relative => fs.readFileSync(path.join(root, relative), 'utf8');

for (const [page, errorVariable] of [
    ['views/admin/translations/index.php', 'dashboardError'],
    ['views/admin/translations/missing.php', 'missingError'],
    ['views/admin/translations/interface-edit.php', 'editorError']
]) {
    test(page + ' keeps blocking errors persistent, accessible and branded', () => {
        const html = read(page);
        assert.equal((html.match(/href="\/Anabelka\/css\/anabelka-notify\.css\?v=3"/g) || []).length, 1);
        assert.equal((html.match(/class="translations-error anabelka-notify-inline" role="alert"/g) || []).length, 1);
        assert.match(html, new RegExp('<\\?php if \\(!empty\\(\\$' + errorVariable + '\\)\\): \\?>'));
        assert.match(html, new RegExp('htmlspecialchars\\(' +
            '(?:\\(string\\) )?\\$' + errorVariable + '\\)'));
        assert.doesNotMatch(html, /class="translations-error"/);
    });
}

test('translation server failure stays inline and purple, not an auto-expiring toast', () => {
    const legacy = read('css/admin-translations.css');
    const shared = read('css/anabelka-notify.css');
    assert.match(legacy, /\.translations-error\s*\{\s*\/\*[\s\S]*?\*\/\s*margin-bottom:\s*16px;/);
    for (const oldColor of ['#e0a5a5', '#fff5f5', '#8a2c2c']) {
        assert.ok(!legacy.includes(oldColor), oldColor + ' legacy style must be removed');
    }
    assert.match(shared, /\.anabelka-notify-inline\s*\{/);
    assert.match(shared, /background:\s*var\(--notify-info-bg\)/);
    assert.match(shared, /color:\s*var\(--notify-info-text\)/);
    assert.match(shared, /--notify-info-bg:\s*var\(--purple-light, #f4eaff\)/i);
    assert.match(shared, /--notify-info-text:\s*var\(--purple-dark, #6519b9\)/i);
});

test('translation editor and missing page keep the shared toast for asynchronous responses', () => {
    const editor = read('views/admin/translations/interface-edit.php');
    const missing = read('views/admin/translations/missing.php');
    const browser = read('js/admin-interface-translations.js');
    for (const page of [editor, missing]) {
        assert.equal((page.match(/id="site-message"/g) || []).length, 1);
        assert.match(page, /js\/anabelka-notify\.js\?v=3/);
    }
    assert.match(browser, /showMessage\('success', data\.message \|\| 'Збережено\.', true\)/);
    assert.match(missing, /js\/admin-flash-message\.js\?v=2/);
});
