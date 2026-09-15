import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import {fileURLToPath} from 'node:url';

const testDirectory = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(testDirectory, '..');
const read = (relativePath) => fs.readFileSync(
    path.join(projectRoot, relativePath),
    'utf8'
);

test('admin pages load the shared branded confirm dialog', () => {
    const header = read('views/admin/partials/header.php');
    const dialogJs = read('js/anabelka-dialog.js');
    const dialogCss = read('css/anabelka-dialog.css');

    assert.match(header, /anabelka-dialog\.css\?v=/);
    assert.match(header, /anabelka-dialog\.js\?v=/);
    assert.match(dialogJs, /window\.AnabelkaDialog/);
    assert.match(dialogJs, /role[^\n]*dialog|setAttribute\(['"]role['"],\s*['"]dialog['"]\)/i);
    assert.match(dialogJs, /aria-modal/i);
    assert.match(dialogJs, /aria-labelledby/i);
    assert.match(dialogJs, /aria-describedby/i);
    assert.match(dialogJs, /Escape/);
    assert.match(dialogJs, /data-anabelka-confirm|dataset\.anabelkaConfirm/);
    assert.match(dialogCss, /\.anabelka-dialog/);
});

test('user destructive actions use branded confirmation instead of browser confirm', () => {
    const users = read('views/admin/users/index.php');

    assert.doesNotMatch(users, /\bconfirm\s*\(/);
    assert.doesNotMatch(users, /onsubmit\s*=\s*["'][^"']*confirm/i);
    assert.match(users, /data-anabelka-confirm=/);
    assert.match(users, /data-anabelka-confirm-title=/);
    assert.match(users, /data-anabelka-confirm-confirm-text=/);
    assert.match(users, /data-anabelka-confirm-danger=/);
});

test('AI provider chooser uses branded listbox and AnabelkaNotify for errors', () => {
    const nav = read('js/admin-nav.js');
    const ai = read('js/admin-ai-translation.js');
    const css = read('css/admin-ai-translation.css');

    assert.match(nav, /ai-provider-trigger/);
    assert.match(nav, /ai-provider-options/);
    assert.match(nav, /aria-haspopup/);
    assert.match(nav, /listbox/);
    assert.match(ai, /role[^\n]*option|setAttribute\(['"]role['"],\s*['"]option['"]\)/i);
    assert.match(ai, /aria-selected/);
    assert.match(ai, /aria-expanded/);
    assert.match(ai, /AnabelkaNotify\.error/);
    assert.doesNotMatch(ai, /window\.alert\s*\(/);
    assert.match(css, /\.ai-provider-trigger/);
    assert.match(css, /\.ai-provider-options/);
});
