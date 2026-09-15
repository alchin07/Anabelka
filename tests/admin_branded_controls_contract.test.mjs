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

test('admin pages load the reusable branded select component', () => {
    const header = read('views/admin/partials/header.php');
    const selectJs = read('js/anabelka-select.js');
    const selectCss = read('css/anabelka-select.css');

    assert.match(header, /anabelka-select\.css\?v=/);
    assert.match(header, /anabelka-select\.js\?v=/);
    assert.match(selectJs, /window\.AnabelkaSelect/);
    assert.match(selectJs, /data-anabelka-select/);
    assert.match(selectJs, /aria-haspopup/);
    assert.match(selectJs, /listbox/);
    assert.match(selectJs, /aria-selected/);
    assert.match(selectJs, /Escape/);
    assert.match(selectJs, /dispatchEvent\(new Event\(['"]change['"]/);
    assert.match(selectJs, /select\.addEventListener\(['"]focus['"]/);
    assert.match(selectCss, /\.anabelka-select/);
});

test('all user-page select groups are marked for the reusable branded select without changing field names', () => {
    const users = read('views/admin/users/index.php');
    const selectJs = read('js/anabelka-select.js');

    assert.match(users, /name=["']invite_channel["']/);
    assert.match(users, /name=["']invite_rank_id["']/);
    assert.match(users, /name=["']rank_id["']/);
    assert.match(users, /name=["']status["']/);

    assert.match(selectJs, /\/Anabelka\/admin\/users/);
    assert.match(selectJs, /select\[name=["']invite_channel["']\]/);
    assert.match(selectJs, /select\[name=["']invite_rank_id["']\]/);
    assert.match(selectJs, /\.admin-users-filters\s+select\[name=["']rank_id["']\]/);
    assert.match(selectJs, /\.admin-users-filters\s+select\[name=["']status["']\]/);
    assert.match(selectJs, /\.admin-user-rank-form\s+select\[name=["']rank_id["']\]/);
});

test('category modal select groups are branded including dynamic move options', () => {
    const selectJs = read('js/anabelka-select.js');
    const selectCss = read('css/anabelka-select.css');

    assert.match(selectJs, /\/Anabelka\/admin\/categories/);
    assert.match(selectJs, /#category-create-department/);
    assert.match(selectJs, /#category-move-parent/);
    assert.match(selectJs, /#category-move-department/);
    assert.match(selectJs, /\.category-translation-status\s+select/);

    assert.match(selectJs, /function\s+renderOptions\s*\(/);
    assert.match(selectJs, /MutationObserver/);
    assert.match(selectJs, /childList\s*:\s*true/);
    assert.match(selectJs, /syncAll/);

    assert.match(selectCss, /max-height:\s*min\(/);
    assert.match(selectCss, /overflow-y:\s*auto/);
});
