import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const testDirectory = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(testDirectory, '..');

function read(relativePath) {
    return fs.readFileSync(path.join(projectRoot, relativePath), 'utf8');
}

const home = read('views/home.php');
const css = read('css/home-desktop-sidebar.css');

assert.match(home, /class="home-sidebar-brand-name"/);
assert.match(home, /class="home-sidebar-brand-strawberry"/);
assert.match(home, /anabelka-strawberry-icon\.php/);
assert.doesNotMatch(home, /home-sidebar-adult-badge/);
assert.match(home, /home-desktop-sidebar\.css\?v=3/);

assert.match(
    css,
    /\.home-sidebar-node\.is-adult-root\s*>\s*\.home-sidebar-row\s+\.home-sidebar-link\s*\{[^{}]*justify-content:\s*space-between/si
);
assert.match(
    css,
    /\.home-sidebar-brand-name\s*\{[^{}]*font-family:\s*Georgia[^{}]*font-style:\s*italic/si
);
assert.match(
    css,
    /\.home-sidebar-brand-strawberry\s*\{[^{}]*width:\s*28px[^{}]*height:\s*28px/si
);

process.stdout.write('home desktop adult brand contract passed\n');
