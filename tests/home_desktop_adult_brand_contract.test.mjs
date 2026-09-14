import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const testDirectory = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(testDirectory, '..');

function read(relativePath) {
    return fs.readFileSync(path.join(projectRoot, relativePath), 'utf8');
}

function ruleBody(css, selector) {
    const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const match = css.match(new RegExp(escaped + '\\s*\\{([^{}]*)\\}', 's'));

    assert.ok(match, `CSS rule ${selector} was not found`);
    return match[1];
}

const css = read('css/home-desktop-sidebar.css');
const icon = read('assets/icons/anabelka-strawberry-white.svg');

const adultLink = ruleBody(
    css,
    '.home-sidebar-node.is-adult-root > .home-sidebar-row .home-sidebar-link'
);
const adultName = ruleBody(
    css,
    '.home-sidebar-node.is-adult-root > .home-sidebar-row .home-sidebar-name'
);
const adultIcon = ruleBody(css, '.home-sidebar-adult-badge');

assert.match(adultLink, /justify-content:\s*space-between/i);
assert.match(adultName, /font-family:\s*Georgia/i);
assert.match(adultName, /font-style:\s*italic/i);
assert.match(adultName, /order:\s*1/i);
assert.match(adultIcon, /order:\s*2/i);
assert.match(adultIcon, /width:\s*28px/i);
assert.match(adultIcon, /height:\s*28px/i);
assert.match(adultIcon, /font-size:\s*0/i);
assert.match(
    adultIcon,
    /background-image:\s*url\(['"]?\/Anabelka\/assets\/icons\/anabelka-strawberry-white\.svg['"]?\)/i
);

assert.match(icon, /viewBox="0 0 24 24"/);
assert.equal((icon.match(/<circle\b/g) ?? []).length, 6);
assert.match(icon, /stroke="#fff"/i);
assert.match(icon, /fill="#fff"/i);

process.stdout.write('home desktop adult brand contract passed\n');
