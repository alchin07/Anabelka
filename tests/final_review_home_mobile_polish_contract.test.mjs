import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const testDirectory = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(testDirectory, '..');
const read = relativePath => fs.readFileSync(
    path.join(projectRoot, relativePath),
    'utf8'
);

function cssRuleBody(css, selector) {
    const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const match = css.match(new RegExp(
        '(?:^|})\\s*' + escaped + '\\s*\\{([^{}]*)\\}',
        'm'
    ));

    assert.ok(match, `CSS rule ${selector} must exist`);
    return match[1];
}

const homeView = read('views/home.php');
const homeCss = read('css/home.css');
const catalogView = read('views/catalog/index.php');

assert.match(
    homeView,
    /\$variant\['hex'\]/,
    'home product swatches must consume the resolved variant hex returned by ProductImage'
);
assert.doesNotMatch(
    homeView,
    /\$variant\['color_hex'\]/,
    'home product swatches must not fall back to the obsolete color_hex key'
);

for (const href of [
    '/Anabelka/news',
    '/Anabelka/reviews',
    '/Anabelka/gift-certificates'
]) {
    assert.doesNotMatch(
        catalogView,
        new RegExp(`href=["']${href.replaceAll('/', '\\/')}["']`),
        `${href} must not be rendered as catalog navigation`
    );
    assert.match(
        homeView,
        new RegExp(`href=["']${href.replaceAll('/', '\\/')}["']`),
        `${href} must be available from the home useful menu`
    );
}
assert.doesNotMatch(catalogView, /catalog-utility-links/);
assert.match(homeView, /<details\s+class="home-useful-menu"/);
assert.match(homeView, /<summary[^>]*>[\s\S]*?home\.useful_title/);
assert.match(homeCss, /\.home-useful-menu\s*\{/);
assert.match(
    homeCss,
    /@media\s*\(min-width:\s*1250px\)[\s\S]*?\.home-useful-menu\s*\{[^{}]*display:\s*none/is,
    'compact useful menu must disappear when the desktop right rail is visible'
);

const adultRule = cssRuleBody(homeCss, '.home-adult-section');
assert.match(adultRule, /background:\s*(?:#faf7ff|#f4eaff)/i);
assert.match(adultRule, /border:\s*1px\s+solid\s+(?:#8a2be2|rgba\(138,\s*43,\s*226[^)]*\))/i);
assert.doesNotMatch(
    adultRule,
    /linear-gradient\([^)]*#8a2be2[^)]*#6519b9/i,
    'home 18+ card must not be a dark purple slab'
);
assert.match(adultRule, /padding:\s*(?:12|14|16)px/i);

process.stdout.write('final home mobile polish contract passed\n');
