import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const testDirectory = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(testDirectory, '..');

function filePath(relativePath) {
    return path.join(projectRoot, relativePath);
}

function read(relativePath) {
    return fs.readFileSync(filePath(relativePath), 'utf8');
}

function requireFile(relativePath) {
    assert.equal(
        fs.existsSync(filePath(relativePath)),
        true,
        `${relativePath} must exist`
    );

    return read(relativePath);
}

const header = read('views/partials/header.php');
const home = read('views/home.php');
const homePage = read('app/Models/HomePage.php');
const homeController = read('app/Controllers/HomeController.php');
const sidebar = requireFile('views/partials/public-catalog-sidebar.php');
const sidebarCss = requireFile('css/public-catalog-sidebar.css');
const sidebarJs = requireFile('js/public-catalog-sidebar.js');

assert.match(
    homePage,
    /public\s+static\s+function\s+localizedNavigationTree\s*\(/,
    'HomePage must expose reusable localized navigation tree data'
);
assert.match(
    homePage,
    /CategoryTranslator::localizeTree\s*\(/,
    'global sidebar tree translation must use the batched tree localizer'
);
assert.doesNotMatch(
    homePage,
    /CategoryTranslator::localize\s*\(/,
    'global sidebar tree must not translate categories one-by-one'
);
assert.match(
    homeController,
    /HomePage::localizedNavigationTree\s*\(/,
    'HomeController must reuse the shared localization helper'
);
assert.doesNotMatch(
    homeController,
    /private\s+function\s+localizeCategoryTree\s*\(/,
    'HomeController must not keep a duplicate recursive localization helper'
);

const adminReturnIndex = header.indexOf('if ($isAdminPage)');
const sidebarIncludeIndex = header.indexOf("public-catalog-sidebar.php");
assert.ok(adminReturnIndex >= 0, 'admin early-return block must exist');
assert.ok(sidebarIncludeIndex > adminReturnIndex, 'public sidebar must be integrated after the admin early-return path');

assert.match(header, /public-catalog-sidebar\.css\?v=2/);
assert.match(header, /public-catalog-sidebar\.js\?v=1/);
assert.match(header, /require\s+__DIR__\s*\.\s*['"]\/public-catalog-sidebar\.php['"]/);

assert.doesNotMatch(home, /home-desktop-sidebar/);
assert.doesNotMatch(home, /data-home-sidebar-/);
assert.doesNotMatch(home, /renderSidebarNodes/);
assert.doesNotMatch(home, /home-desktop-sidebar\.css/);
assert.doesNotMatch(home, /home-desktop-sidebar\.js/);

assert.match(sidebar, /class="public-catalog-sidebar"/);
assert.match(sidebar, /HomePage::localizedNavigationTree\s*\(/);
assert.match(sidebar, /Category::catalogUrl\s*\(/);
assert.match(sidebar, /AdultAccess::gateUrl\s*\(/);
assert.match(sidebar, /data-public-catalog-sidebar-toggle/);
assert.match(sidebar, /data-public-catalog-sidebar-children/);
assert.match(sidebar, /anabelka-strawberry-white\.svg|public-catalog-sidebar-adult-badge/);

assert.match(sidebarJs, /anabelka-public-sidebar-collapsed/);
assert.match(sidebarJs, /\.public-catalog-sidebar/);
assert.match(sidebarJs, /data-public-catalog-sidebar-toggle/);
assert.match(sidebarJs, /data-public-catalog-sidebar-children/);
assert.doesNotMatch(sidebarJs, /systemErrorEndpoint|admin-system-error/);

assert.match(sidebarCss, /\.public-catalog-sidebar\s*\{[^{}]*display:\s*none/si);
assert.match(sidebarCss, /public-sidebar-scroll-thumb/);
assert.match(sidebarCss, /::-webkit-scrollbar\s*\{[^{}]*width:\s*10px/si);
assert.match(sidebarCss, /::-webkit-scrollbar-thumb\s*\{[\s\S]*?radial-gradient[\s\S]*?min-height:\s*72px|::-webkit-scrollbar-thumb\s*\{[\s\S]*?min-height:\s*72px[\s\S]*?radial-gradient/si);
assert.match(sidebarCss, /::-webkit-scrollbar-thumb:hover\s*\{[^{}]*background-color:\s*var\(--public-sidebar-primary\)/si);
assert.match(sidebarCss, /::-webkit-scrollbar-thumb:active\s*\{[^{}]*background-color:\s*var\(--public-sidebar-primary-dark\)/si);
assert.match(sidebarCss, /scroll-behavior:\s*smooth/);
assert.match(sidebarCss, /@media\s*\(min-width:\s*1050px\)/i);
assert.match(sidebarCss, /grid-template-columns:\s*280px\s+minmax\(0,\s*1fr\)/i);
assert.match(sidebarCss, /\.public-header\s*\{[^{}]*grid-column:\s*1\s*\/\s*-1/si);
assert.match(sidebarCss, /\.public-catalog-sidebar\s*\{[^{}]*grid-column:\s*1[^{}]*position:\s*sticky/si);
assert.match(sidebarCss, /body\s*>\s*main\s*\{[^{}]*grid-column:\s*2[^{}]*min-width:\s*0/si);
assert.match(sidebarCss, /\.home-department-nav\s*\{[^{}]*display:\s*none/si);
assert.match(sidebarCss, /@media\s*\(min-width:\s*1250px\)/i);
assert.match(sidebarCss, /grid-template-columns:\s*300px\s+minmax\(0,\s*1fr\)/i);

process.stdout.write('public catalog sidebar contract passed\n');
