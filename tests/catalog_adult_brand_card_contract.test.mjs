import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const testDirectory = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(testDirectory, '..');

function read(relativePath) {
    return fs.readFileSync(path.join(projectRoot, relativePath), 'utf8');
}

function cssRuleBody(css, selector) {
    const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const match = css.match(new RegExp(
        '(?:^|})\\s*' + escaped + '\\s*\\{([^{}]*)\\}',
        'm'
    ));

    assert.ok(match, `CSS rule ${selector} was not found`);
    return match[1];
}

const view = read('views/catalog/index.php');
const css = read('css/catalog.css');

assert.match(
    view,
    /<a\s+href="<\?=\s*\$escape\(AdultAccess::gateUrl\(\$category\)\)\s*\?>"\s+class="catalog-adult-entry"\s+aria-labelledby="<\?=\s*\$escape\(\$rootLabelId\)\s*\?>"/
);
assert.match(
    view,
    /class="catalog-adult-brand-name"[\s\S]*?\$category\['name'\]/
);
assert.match(view, /class="catalog-adult-strawberry"/);
assert.doesNotMatch(view, /catalog-adult-badge/);
assert.doesNotMatch(view, /catalog-adult-action/);
assert.doesNotMatch(view, /catalog-adult-tree/);
assert.doesNotMatch(view, /catalog-adult-node/);
assert.doesNotMatch(view, /\$renderAdultTree/);
assert.doesNotMatch(view, /public\.catalog\.adult_enter/);
assert.match(view, /css\/catalog\.css\?v=11/);

const entryRule = cssRuleBody(css, '.catalog-adult-entry');
assert.match(entryRule, /display:\s*flex/i);
assert.match(entryRule, /align-items:\s*center/i);
assert.match(entryRule, /text-decoration:\s*none/i);
assert.match(entryRule, /background:\s*linear-gradient\([^;]*#8a2be2[^;]*#6519b9/i);
assert.match(entryRule, /color:\s*#fff/i);

assert.doesNotMatch(css, /\.catalog-adult-root\b/);
assert.doesNotMatch(css, /\.catalog-adult-root-meta\b/);
assert.doesNotMatch(css, /\.catalog-adult-badge\b/);
assert.doesNotMatch(css, /\.catalog-adult-action\b/);
assert.doesNotMatch(css, /\.catalog-adult-tree\b/);
assert.doesNotMatch(css, /\.catalog-adult-node(?:\b|-)/);

process.stdout.write('catalog adult brand card contract passed\n');
