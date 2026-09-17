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

function ruleBody(css, selector) {
    const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const match = css.match(new RegExp(
        '(?:^|})\\s*' + escaped + '\\s*\\{([^{}]*)\\}',
        'mi'
    ));
    assert.ok(match, `CSS rule ${selector} must exist`);
    return match[1];
}

function backgroundHex(rule, label) {
    const match = rule.match(/background:\s*(#[0-9a-f]{6})/i);
    assert.ok(match, `${label} must use a direct six-digit hex background`);
    return match[1].toLowerCase();
}

function luminance(hex) {
    const channels = [1, 3, 5].map(index => parseInt(hex.slice(index, index + 2), 16) / 255);
    const linear = channels.map(value => value <= 0.04045
        ? value / 12.92
        : Math.pow((value + 0.055) / 1.055, 2.4));
    return (0.2126 * linear[0]) + (0.7152 * linear[1]) + (0.0722 * linear[2]);
}

function assertStrictlyDarker(colors, label) {
    assert.equal(new Set(colors).size, colors.length, `${label} levels must all be visually distinct`);
    for (let index = 1; index < colors.length; index += 1) {
        assert.ok(
            luminance(colors[index]) < luminance(colors[index - 1]),
            `${label} level ${index} must be darker than its parent`
        );
    }
}

const adminCss = read('css/admin-categories.css');
const sidebarCss = read('css/public-catalog-sidebar.css');
const homeCss = read('css/home.css');
const adminView = read('views/admin/categories/index.php');
const publicHeader = read('views/partials/header.php');
const homeView = read('views/home.php');

const adminColors = [
    backgroundHex(ruleBody(adminCss, '.category-admin-card'), 'admin level 0'),
    ...Array.from({length: 8}, (_, index) => {
        const level = index + 1;
        return backgroundHex(
            ruleBody(
                adminCss,
                `.category-admin-row[data-category-level="${level}"] .category-admin-card`
            ),
            `admin level ${level}`
        );
    })
];
assertStrictlyDarker(adminColors, 'admin category depth');

const publicColors = Array.from({length: 9}, (_, index) => {
    const level = index + 1;
    if (level === 1) {
        return backgroundHex(
            ruleBody(sidebarCss, '.public-catalog-sidebar-link'),
            'public level 1'
        );
    }
    return backgroundHex(
        ruleBody(
            sidebarCss,
            `.public-catalog-sidebar-row[data-level="${level}"]\n        .public-catalog-sidebar-link`
        ),
        `public level ${level}`
    );
});
assertStrictlyDarker(publicColors, 'public category depth');

assert.equal(
    adminColors[0],
    publicColors[0],
    'the same root category must use the same depth color in admin and public trees'
);
for (let index = 1; index < adminColors.length; index += 1) {
    assert.equal(
        adminColors[index],
        publicColors[index],
        `admin/public depth color ${index} must stay synchronized`
    );
}

assert.match(
    ruleBody(homeCss, '.home-department-nav'),
    /scrollbar-color:\s*#c9a7e8\s+#f4eaff/i,
    'mobile category menu must use the Anabelka soft scrollbar palette'
);
assert.match(homeCss, /\.home-department-nav::-webkit-scrollbar\s*\{[^{}]*height:\s*[456]px/is);
assert.match(homeCss, /\.home-department-nav::-webkit-scrollbar-track\s*\{[^{}]*background:\s*#f4eaff/is);
assert.match(homeCss, /\.home-department-nav::-webkit-scrollbar-thumb\s*\{[^{}]*background:\s*#c9a7e8[^{}]*border-radius:\s*999px/is);

assert.match(
    ruleBody(sidebarCss, '.public-catalog-sidebar'),
    /scrollbar-color:\s*#c9a7e8\s+#f4eaff/i,
    'desktop catalog sidebar must use the same Anabelka soft scrollbar palette'
);
assert.match(sidebarCss, /\.public-catalog-sidebar::-webkit-scrollbar\s*\{[^{}]*width:\s*[456]px/is);
assert.match(sidebarCss, /\.public-catalog-sidebar::-webkit-scrollbar-track\s*\{[^{}]*background:\s*#f4eaff/is);
assert.match(sidebarCss, /\.public-catalog-sidebar::-webkit-scrollbar-thumb\s*\{[^{}]*background:\s*#c9a7e8[^{}]*border-radius:\s*999px/is);

assert.match(
    adminView,
    /css\/admin-categories\.css\?v=4/,
    'admin category palette change must cache-bust the stylesheet'
);
assert.match(
    publicHeader,
    /css\/public-catalog-sidebar\.css\?v=2/,
    'public sidebar palette/scrollbar change must cache-bust the stylesheet'
);
assert.match(
    homeView,
    /css\/home\.css\?v=5/,
    'home category scrollbar change must cache-bust the stylesheet'
);

process.stdout.write('category depth palette and scrollbar contract passed\n');
