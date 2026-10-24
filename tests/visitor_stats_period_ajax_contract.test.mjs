import {test} from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const js = fs.readFileSync(new URL('../js/admin-visitor-stats.js', import.meta.url), 'utf8');
const view = fs.readFileSync(new URL('../views/admin/visitor-stats/index.php', import.meta.url), 'utf8');

test('period change keeps a normal GET fallback and adds accessible progressive enhancement', () => {
    assert.match(view, /action="\/Anabelka\/admin\/visitor-stats#visitor-popularity"/);
    assert.match(view, /admin-visitor-stats\.js\?v=1/);
    assert.match(js, /form\.addEventListener\('submit'/);
    assert.match(js, /window\.fetch\(requestUrl\.href/);
    assert.match(js, /credentials: 'same-origin'/);
    assert.match(js, /window\.history\.replaceState/);
    assert.match(js, /existingGrid\.replaceWith/);
    assert.match(js, /aria-busy/);
    assert.doesNotMatch(js, /\binnerHTML\s*=|\beval\(/);
});
