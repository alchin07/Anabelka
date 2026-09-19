import assert from 'node:assert/strict';
import fs from 'node:fs';

const required = [
  'views/partials/mobile-bottom-navigation.php',
  'views/partials/mobile-menu-sheet.php',
  'views/partials/mobile-header-shortcuts.php',
  'css/mobile-navigation.css',
  'js/mobile-navigation.js'
];

for (const file of required) {
  assert.ok(fs.existsSync(file), `${file} must exist`);
}

const header = fs.readFileSync('views/partials/header.php', 'utf8');
const bottom = fs.readFileSync(
  'views/partials/mobile-bottom-navigation.php',
  'utf8'
);
const sheet = fs.readFileSync(
  'views/partials/mobile-menu-sheet.php',
  'utf8'
);
const shortcuts = fs.readFileSync(
  'views/partials/mobile-header-shortcuts.php',
  'utf8'
);
const css = fs.readFileSync('css/mobile-navigation.css', 'utf8');
const js = fs.readFileSync('js/mobile-navigation.js', 'utf8');

assert.match(header, /\$mobileNavigationEligible/);
assert.match(header, /!\$isCheckoutPage[\s\S]*!\$isQuickOrderPage/);
assert.match(header, /MobileNavigation::publicItems/);
assert.match(header, /mobile-header-shortcuts\.php/);
assert.match(header, /mobile-bottom-navigation\.php/);
assert.match(header, /mobile-menu-sheet\.php/);
assert.match(header, /mobile-navigation\.css\?v=1/);
assert.match(header, /mobile-navigation\.js\?v=2/);

for (const href of [
  '/Anabelka/news',
  '/Anabelka/reviews',
  '/Anabelka/gift-certificates'
]) {
  assert.match(shortcuts, new RegExp(href.replaceAll('/', '\\/')));
}

assert.match(bottom, /data-mobile-menu-toggle/);
assert.match(bottom, /data-mobile-profile-action/);
assert.match(bottom, /data-mobile-cart-slot/);
assert.match(bottom, /data-mobile-admin-slot/);
assert.match(bottom, /--mobile-bottom-navigation-columns/);
assert.doesNotMatch(
  bottom,
  /id=["'](?:cart-count|profile-notification-count|admin-system-error-count)["']/,
  'mobile bar must move canonical counters instead of cloning them'
);

assert.match(sheet, /data-mobile-menu-layer/);
assert.match(sheet, /role="dialog"/);
assert.match(sheet, /aria-modal="true"/);
assert.match(sheet, /data-mobile-menu-close/);
assert.match(sheet, /data-mobile-menu-backdrop/);
assert.match(sheet, /\$mobileMenuItems/);
assert.match(sheet, /target="_blank" rel="noopener noreferrer"/);

assert.match(js, /document\.querySelector\(['"]\.header-cart['"]\)/);
assert.match(js, /document\.getElementById\([\s\S]*profile-notification-count/);
assert.match(js, /document\.querySelector\([\s\S]*public-header-admin-action/);
assert.match(js, /adminSlot\.appendChild\(adminAction\)/);
assert.match(js, /adminLabel\.textContent\s*=\s*['\"]Адмін['\"]/);
assert.match(js, /restoreHome\(homes\.admin\)/);
assert.match(js, /document\.createComment/);
assert.match(js, /restoreHome/);
assert.match(js, /matchMedia\(['"]\(max-width: 430px\)['"]\)/);
assert.match(js, /window\.AnabelkaMobileNavigation/);
assert.match(js, /openMenu:\s*openMenu/);
assert.match(js, /closeMenu:\s*closeMenu/);
assert.match(js, /isOpen:/);

assert.match(css, /@media\s*\(max-width:\s*430px\)/);
assert.match(css, /position:\s*fixed/);
assert.match(css, /safe-area-inset-bottom/);
assert.match(css, /repeat\([\s\S]*--mobile-bottom-navigation-columns/);
assert.match(
  css,
  /public-header-mobile-navigation-excluded[\s\S]*public-header-profile/
);

process.stdout.write('mobile navigation contract passed\n');
