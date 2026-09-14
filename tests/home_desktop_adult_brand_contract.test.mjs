import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const testDirectory = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(testDirectory, '..');

function read(relativePath) {
    return fs.readFileSync(path.join(projectRoot, relativePath), 'utf8');
}

const css = read('css/home-desktop-sidebar.css');
const icon = read('assets/icons/anabelka-strawberry-white.svg');

assert.match(
    css,
    /\.home-sidebar-node\.is-adult-root\s*>\s*\.home-sidebar-row\s+\.home-sidebar-link\s*\{[^{}]*justify-content:\s*space-between/si
);
assert.match(
    css,
    /\.home-sidebar-node\.is-adult-root[^{}]*\.home-sidebar-name\s*\{[^{}]*font-family:\s*Georgia[^{}]*font-style:\s*italic[^{}]*order:\s*1/si
);
assert.match(
    css,
    /\.home-sidebar-adult-badge\s*\{[^{}]*order:\s*2[^{}]*width:\s*28px[^{}]*height:\s*28px[^{}]*font-size:\s*0/si
);
assert.match(
    css,
    /background-image:\s*url\(['"]?\/Anabelka\/assets\/icons\/anabelka-strawberry-white\.svg['"]?\)/i
);

assert.match(icon, /viewBox="0 0 24 24"/);
assert.equal((icon.match(/<circle\b/g) ?? []).length, 6);
assert.match(icon, /stroke="#fff"/i);
assert.match(icon, /fill="#fff"/i);

process.stdout.write('home desktop adult brand contract passed\n');
