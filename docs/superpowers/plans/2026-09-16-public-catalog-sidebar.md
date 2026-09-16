# Global Public Catalog Sidebar Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reuse the home-page desktop catalog tree as one persistent sidebar on every public Anabelka page without changing mobile or admin behavior.

**Architecture:** The shared public header becomes the integration point. A reusable sidebar partial renders a localized tree, generic CSS places the public header/sidebar/direct `<main>` in a desktop two-column body grid, and generic JS preserves existing collapse state across page navigation. The home page removes its private copy.

**Tech Stack:** PHP 7+/MVC views and models, CSS Grid, vanilla JavaScript, Node contract tests.

**Spec:** `docs/superpowers/specs/2026-09-16-public-catalog-sidebar-design.md`

## Global Constraints

- Public pages only; admin pages unchanged.
- Sidebar visible only at `min-width: 1050px`.
- Current mobile/tablet layout below `1050px` unchanged.
- Keep `anabelka-public-sidebar-collapsed` sessionStorage key.
- Keep `Category::catalogUrl()` and `AdultAccess::gateUrl()` routing helpers.
- No database, migration, route, product, cart, checkout, auth, or adult-access logic changes.
- Do not merge `feature/category-manager` into `main`.

---

### Task 1: Add the failing global-sidebar contract

**Files:**
- Create: `tests/public_catalog_sidebar_contract.test.mjs`

**Interfaces:**
- Consumes: existing PHP/CSS/JS source files as text fixtures.
- Produces: structural regression checks for the shared public sidebar.

- [ ] **Step 1: Write the failing test**

Create a Node test that reads the relevant source files and asserts at minimum:

```js
assert.match(header, /public-catalog-sidebar\.css/);
assert.match(header, /partials\/public-catalog-sidebar\.php/);
assert.match(header, /public-catalog-sidebar\.js/);
assert.doesNotMatch(home, /home-desktop-sidebar/);
assert.match(sidebar, /Category::catalogUrl\(/);
assert.match(sidebar, /AdultAccess::gateUrl\(/);
assert.match(sidebarJs, /anabelka-public-sidebar-collapsed/);
assert.match(sidebarCss, /@media\s*\(min-width:\s*1050px\)/);
assert.match(sidebarCss, /grid-template-columns/);
assert.match(sidebarCss, /position:\s*sticky/);
```

Also assert that the admin early-return appears before the public sidebar include in `views/partials/header.php`.

- [ ] **Step 2: Run the test to verify RED**

Run:

```bash
node tests/public_catalog_sidebar_contract.test.mjs
```

Expected: FAIL because generic sidebar assets/partial do not exist and `home.php` still owns the sidebar.

- [ ] **Step 3: Commit the RED contract**

```bash
git add tests/public_catalog_sidebar_contract.test.mjs
git commit -m "test: define global public catalog sidebar contract"
```

---

### Task 2: Make category-tree localization reusable

**Files:**
- Modify: `app/Models/HomePage.php`
- Modify: `app/Controllers/HomeController.php`

**Interfaces:**
- Produces: `HomePage::localizedNavigationTree($languageCode): array`.
- Consumes: `HomePage::navigationTree()` and `CategoryTranslator::localize()`.

- [ ] **Step 1: Add the model method**

Implement recursive localization in `HomePage`:

```php
public static function localizedNavigationTree($languageCode)
{
    $localize = function (array $nodes) use (&$localize, $languageCode) {
        foreach ($nodes as &$node) {
            $children = is_array($node['children'] ?? null)
                ? $node['children']
                : [];

            unset($node['children']);
            $node = CategoryTranslator::localize($node, $languageCode);
            $node['children'] = $localize($children);
        }
        unset($node);

        return $nodes;
    };

    return $localize(self::navigationTree());
}
```

- [ ] **Step 2: Replace controller-private recursion**

In `HomeController::index()` use:

```php
$navigationTree = HomePage::localizedNavigationTree($languageCode);
```

Delete the private `localizeCategoryTree()` method.

- [ ] **Step 3: Verify PHP delimiter/static contracts**

Run the sidebar contract and existing category-manager contract. Expected: sidebar contract still RED only for missing generic assets/integration; category-manager contract remains PASS.

- [ ] **Step 4: Commit**

```bash
git add app/Models/HomePage.php app/Controllers/HomeController.php
git commit -m "refactor: reuse localized public category tree"
```

---

### Task 3: Create reusable sidebar partial, CSS, and JS

**Files:**
- Create: `views/partials/public-catalog-sidebar.php`
- Create: `css/public-catalog-sidebar.css`
- Create: `js/public-catalog-sidebar.js`
- Read from: existing `views/home.php`, `css/home-desktop-sidebar.css`, `js/home-desktop-sidebar.js`

**Interfaces:**
- Consumes: `$currentLanguage`, `HomePage::localizedNavigationTree()`, `Translator`, `Category`, `AdultAccess`.
- Produces: one `.public-catalog-sidebar` aside and collapse behavior.

- [ ] **Step 1: Extract the recursive renderer**

The partial should obtain the active language code and tree:

```php
$sidebarLanguageCode = $currentLanguage['code'] ?? Language::SOURCE_CODE;
$publicCatalogTree = HomePage::localizedNavigationTree($sidebarLanguageCode);
```

Render recursive nodes using generic `public-catalog-sidebar-*` classes. For links:

```php
$href = $isAdultRoot
    ? AdultAccess::gateUrl($node)
    : Category::catalogUrl($node);
```

Preserve the current adult strawberry badge and current collapse button semantics/ARIA.

- [ ] **Step 2: Create generic desktop CSS**

Below `1050px`:

```css
.public-catalog-sidebar { display: none; }
```

At `1050px+`:

```css
body {
    display: grid;
    grid-template-columns: 280px minmax(0, 1fr);
    column-gap: 24px;
    align-items: start;
}

.public-header { grid-column: 1 / -1; }
.public-catalog-sidebar { grid-column: 1; position: sticky; top: 16px; }
body > main { grid-column: 2; min-width: 0; width: 100%; }
```

At `1250px+`, grow the sidebar column to 300px. Preserve the existing tree card colors, same-axis child levels, adult styling, and hide `.home-department-nav` on desktop.

- [ ] **Step 3: Create generic JS**

Port only sidebar collapse logic from `home-desktop-sidebar.js`; do not duplicate header/admin badge logic because that now belongs to existing header-specific JS. Preserve:

```js
const storageKey = 'anabelka-public-sidebar-collapsed';
```

Initialize against `.public-catalog-sidebar` and generic data attributes.

- [ ] **Step 4: Run the new contract**

Expected: still RED until header/home integration is completed, but partial routing, storage key, breakpoint/grid/sticky assertions should now pass.

- [ ] **Step 5: Commit**

```bash
git add views/partials/public-catalog-sidebar.php css/public-catalog-sidebar.css js/public-catalog-sidebar.js
git commit -m "feat: add reusable public catalog sidebar"
```

---

### Task 4: Integrate globally and remove the home-only copy

**Files:**
- Modify: `views/partials/header.php`
- Modify: `views/home.php`
- Delete: `css/home-desktop-sidebar.css`
- Delete: `js/home-desktop-sidebar.js`

**Interfaces:**
- Consumes: generic sidebar partial/assets from Task 3.
- Produces: exactly one global sidebar on every public page that includes the public header.

- [ ] **Step 1: Load generic assets in the public-header path**

After the admin early return, load:

```php
<link rel="stylesheet" href="/Anabelka/css/public-catalog-sidebar.css?v=1">
```

At the end of the public header output, before returning control to the page view:

```php
<?php require __DIR__ . '/public-catalog-sidebar.php'; ?>
<script src="/Anabelka/js/public-catalog-sidebar.js?v=1" defer></script>
```

Do not place either before the admin early-return branch.

- [ ] **Step 2: Remove the private home sidebar**

Delete from `views/home.php`:

- `$navigationTree` dependency used only by the old aside;
- `$renderSidebarNodes` closure;
- `<aside class="home-desktop-sidebar">…</aside>`;
- `home-desktop-sidebar.css` link;
- `home-desktop-sidebar.js` script.

Leave the home department nav because it remains the non-desktop fallback.

- [ ] **Step 3: Remove obsolete assets**

Delete:

```text
css/home-desktop-sidebar.css
js/home-desktop-sidebar.js
```

- [ ] **Step 4: Run the new contract to GREEN**

```bash
node tests/public_catalog_sidebar_contract.test.mjs
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add views/partials/header.php views/home.php css/public-catalog-sidebar.css js/public-catalog-sidebar.js tests/public_catalog_sidebar_contract.test.mjs
git rm css/home-desktop-sidebar.css js/home-desktop-sidebar.js
git commit -m "feat: show catalog sidebar across public desktop pages"
```

---

### Task 5: Regression verification

**Files:**
- Verify only; modify tests only if a renamed asset requires an assertion update with unchanged behavioral intent.

**Interfaces:**
- Consumes: completed feature tree.
- Produces: evidence that existing public/admin/mobile behavior is intact.

- [ ] **Step 1: Run syntax checks**

```bash
node --check js/public-catalog-sidebar.js
node --check tests/public_catalog_sidebar_contract.test.mjs
```

- [ ] **Step 2: Run relevant contracts**

```bash
node tests/public_catalog_sidebar_contract.test.mjs
node tests/category_manager_contract.test.mjs
node tests/public_header_admin_contract.test.mjs
node tests/catalog_adult_brand_card_contract.test.mjs
node tests/home_desktop_adult_brand_contract.test.mjs
node tests/product_variant_color_contract.test.mjs
node tests/product_variant_stock_mobile_contract.test.mjs
node tests/anabelka_notify_contract.test.mjs
node tests/public_error_notify_cleanup_contract.test.mjs
```

Expected: all PASS.

- [ ] **Step 3: Check repository whitespace**

```bash
git diff --check
```

Expected: no output.

- [ ] **Step 4: Review layout invariants statically**

Confirm:

- public header spans both desktop grid columns;
- sidebar is hidden below 1050px;
- direct public `<main>` occupies column 2 only on desktop;
- admin early-return prevents sidebar assets/markup in admin;
- home has only the global sidebar;
- adult root route and styling remain unchanged;
- mobile header files are not modified.

- [ ] **Step 5: Final commit only if verification required test-only corrections**

```bash
git add tests/
git commit -m "test: cover global public sidebar regressions"
```

## Manual laptop check later

When laptop access returns, verify at 1050px, 1280px, 1440px, and a wider desktop viewport:

- home, catalog root, nested category, product, search, favorites, cart, checkout, account, login, order history, legal page, adult gate, and 404 page;
- sidebar stays visible while scrolling;
- tree collapse state survives navigation;
- no content overlaps or horizontal overflow;
- below 1050px current mobile/tablet layout remains unchanged.