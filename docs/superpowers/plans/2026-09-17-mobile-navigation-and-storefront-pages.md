# Mobile Navigation and Storefront Pages Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development when agent dispatch is available, or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Do not claim an independent review unless another reviewer actually performed it.

**Goal:** Deliver the approved mobile header, fixed bottom navigation, multilingual admin-managed hamburger menu, and four working public storefront pages without changing the existing desktop composition or pricing rules.

**Architecture:** Deliver three independently testable increments: public pages, menu storage/editor, and mobile presentation. `StorefrontProductCollection` owns selection and pagination; `MobileNavigation` and its translator own menu data; reusable partials and a viewport adapter own navigation presentation. Preserve the existing cart/admin DOM nodes and counter IDs during relocation instead of cloning their state.

**Tech Stack:** Existing PHP/PDO, MySQL/MariaDB 10.4 conventions, vanilla JavaScript/CSS, Node built-in assertions/test runner. PHP CLI tests and real-browser checks supplement source contracts; no new production framework or build pipeline.

**Spec:** `docs/superpowers/specs/2026-09-17-mobile-navigation-and-storefront-pages-design.md`

**Approval:** The owner approved the written specification in chat on 2026-09-17. This records that approval; the older “pending written-spec review” marker in the original document is historical, not a request to repeat approval.

**Source baseline:** `alchin07/Anabelka`, `feature/category-manager`, commit `1b2db959078846bdf069f0d10cce3bac7c3c5d78`, draft PR #4. This document is a plan, not an implementation or test-success report.

## Global Constraints

- `At widths above 430 px, the current desktop/tablet header composition remains unchanged.`
- `The full Анабелька logo remains visible and unclipped.`
- `Favorites stays directly to the right of the logo.`
- `Buttons remain at least 44 px high.`
- `24 products per page` for Discounts and New Arrivals.
- `No Catalog item in the hamburger menu.`
- `No 18+ item is automatically inserted into the hamburger menu.`
- `No nested tree inside the hamburger menu.`
- `No dedicated CMS for Contacts copy.`
- `No dedicated CMS for Delivery/Payment/Returns copy.`
- `No automatic database migration execution.`
- Draft PR #4 remains unmerged; never merge or write to `main` without separate explicit owner approval.
- The mobile bottom bar and mobile News/Reviews/Gift shortcuts are absent on the exact checkout and quick-order form paths, including their query strings/trailing-slash variants. Login and registration retain navigation. Admin pages retain their existing admin navigation.
- Both product collections exclude effectively adult categories even after age confirmation; inactive products, departments, categories, and ancestors remain excluded.
- UA is the required source name. Target languages are active language rows, not hardcoded RU/EN columns. Missing translations fall back to UA; changes to UA mark existing target translations outdated.
- No real shop contacts, payment policies, delivery promises, or return terms are invented. No product/category/order schema changes belong to this work.

---

## Delivery sequence and file ownership

Keep the increments sequential in the existing branch. Do not simultaneously edit the shared header, application loader, permission map, or translation dashboard from another task.

| Increment | Tasks | Independently reviewable result |
| --- | --- | --- |
| A. Public pages | 1–3 | Four routes work directly, before any change to mobile navigation. |
| B. Editable menu | 4–7 | Menu data, translations, permissions and admin editor work; existing storefront navigation is still intact. |
| C. Mobile integration | 8–11 | Approved header/bottom bar/sheet use the working pages and menu; final integration checks are explicit. |

### New production files

- `app/Models/StorefrontProductCollection.php`: qualification, current-price projection and 24-item pagination.
- `app/Controllers/StorefrontPageController.php`: four public GET actions.
- `routes/StorefrontPages.php`: four public route registrations.
- `views/storefront/discounts.php`, `new.php`, `delivery-payment.php`, `contacts.php`: page shells.
- `views/storefront/partials/product-card.php`, `pagination.php`: shared presentation for the two collections only.
- `css/storefront-pages.css`: scoped collection/information page styling.
- `app/Models/MobileNavigation.php`: validation, persistence, ordering and public fallback.
- `app/Models/MobileNavigationTranslator.php`: target names, workflow state and public localization.
- `app/Controllers/AdminMobileNavigationController.php`, `routes/MobileNavigation.php`: editor endpoints.
- `views/admin/mobile-navigation/index.php`, `css/admin-mobile-navigation.css`, `js/admin-mobile-navigation.js`: flat mobile-first editor.
- `views/partials/mobile-bottom-navigation.php`, `mobile-menu-sheet.php`, `mobile-header-shortcuts.php`: presentation without independent session/price queries.
- `css/mobile-navigation.css`, `js/mobile-navigation.js`: responsive adapter, sheet interaction and safe-area layout.
- `database/preflight/2026-09-17_mobile_navigation_preflight.sql`, `database/migrations/2026-09-17_mobile_navigation.sql`, `database/postflight/2026-09-17_mobile_navigation_postflight.sql`: manual deployment artifacts.

### Existing production integration points

`app/Core/App.php`; `app/Models/AdminAccess.php`; `app/Models/ContentInterfaceTranslator.php`; `app/Services/TranslationDashboardService.php`; `app/Controllers/AdminTranslationController.php`; `views/admin/translations/index.php`; `views/admin/translations/missing.php`; `views/admin/partials/header.php`; `views/partials/header.php`; `views/home.php`; `css/public-header.css` only for narrowly required responsive compatibility. Read each complete file before editing it. Preserve unrelated page behavior.

### Test files

The five principal suites remain those in the spec:

- `tests/mobile_navigation_contract.test.mjs`
- `tests/mobile_navigation_runtime.test.mjs`
- `tests/storefront_pages_contract.test.mjs`
- `tests/storefront_product_collection_contract.test.mjs`
- `tests/admin_mobile_navigation_contract.test.mjs`

Add focused supporting files: `tests/helpers/php-probe.mjs`, `tests/mobile_navigation_model_contract.test.mjs`, `tests/mobile_navigation_translation_contract.test.mjs`, `tests/integration/storefront_collections.php`, `tests/integration/mobile_navigation.php`, and `tests/browser/mobile_navigation.spec.mjs`. Browser/integration tests have separate commands so a missing optional runner is not mistaken for an application regression or silently reported as a pass.

## Verified integration facts at the source baseline

- `js/public-header-admin-badges.js` captures one `.public-header-admin-action` and `#admin-system-error-count`; cloning an admin action would not automatically give the second copy live updates.
- `TranslationDashboardService` explicitly lists sections and builds both aggregate and per-language coverage. Adding a translator class alone does not expose missing menu translations there.
- `TranslationWorkflow` already defines `draft`, `review`, `approved`, `outdated`, `manual`, and `ai`; reuse these values and helpers.
- `AdminAccess` seeds `owner`, `store_owner`, `administrator`, `order_manager`, and `content_manager`; content-manager defaults already include news/review permissions. Do not grant menu management to order managers as a side effect.
- `ContentInterfaceTranslator` uses `INSERT IGNORE` for UI dictionaries. Preserve administrator-edited interface translations.

These are code observations, not evidence of a fresh complete-suite or live database run. Historical PR descriptions are not current verification evidence.

## Start-of-execution gate

Use an isolated checkout/worktree during execution. Read repository instruction files first. Do not reset, clean, stash, or overwrite an owner's uncommitted changes automatically.

```bash
git status --short
git branch --show-current
git rev-parse HEAD
git log -5 --oneline
node --version
php --version
```

Compare current branch/head with this plan's baseline. If newer commits exist, inspect their diff before applying the plan. Do not force-push or recreate the feature branch.

Run the entire current Node inventory as a baseline, recording every failing filename rather than stopping at the first file:

```bash
(
  failed=0
  count=0
  for f in tests/*.test.mjs; do
    [ -f "$f" ] || continue
    count=$((count + 1))
    printf '\n== %s ==\n' "$f"
    node "$f" || { printf 'FAILED: %s\n' "$f"; failed=$((failed + 1)); }
  done
  printf '\nFILES=%s FAILED=%s\n' "$count" "$failed"
  [ "$count" -gt 0 ] && [ "$failed" -eq 0 ]
)
```

The old `category_manager_contract` has reported stale expectations. Record observed failures; do not assume it is the only failure and do not remove it from the final run. Keep the initial baseline record outside tracked production files.

Run RED/GREEN locally when the available environment permits. Do not require the owner to execute each deliberately failing static test on the phone. Request device checks only for behavior that needs the real Android/KSWEB environment, or one clearly explained verification batch when a required runner is missing.

---

## Task 1: Testable collection rules and real paginated selection

**Files:** Create `app/Models/StorefrontProductCollection.php`, `tests/helpers/php-probe.mjs`, `tests/storefront_product_collection_contract.test.mjs`, `tests/integration/storefront_collections.php`. Read `app/Models/Product.php`, `Category.php`, `HomePage.php`, `ProductTranslator.php`, `ProductImage.php`, and `app/Core/Database.php` without changing their business rules.

**Interfaces:**

- Consume `Category::visibleCategoryIds()`, `Category::adultCategoryIds()`, `Product::getCurrentRankSlug()`, `ProductTranslator::localizeList(array, string)`, and `ProductImage::colorVariantsForProducts(array)`.
- Produce `StorefrontProductCollection::page(string $kind, $pageInput, string $languageCode): array`, with `$kind` restricted to `discounts` or `new`.
- Result keys: `items`, `page`, `per_page` (=24), `total`, `total_pages`, `has_previous`, `has_next`.
- Each item includes existing product-card fields plus `current_price`, `active_discount_percent`, `display_discount_percent`, and `color_variants`.
- Produce pure `pageMeta($pageInput, int $total): array` and `discountPercent($oldPrice, $currentPrice, $activePercent): ?float` for focused executable tests.

- [ ] **1.1 Write executable RED checks.** Add the PHP probe helper below. A missing PHP executable is a reported prerequisite failure, not a passing PHP test.

```js
import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
const root = fileURLToPath(new URL('../../', import.meta.url));
export function phpJson(program) {
  const r = spawnSync(process.env.PHP_BIN || 'php', ['-r', program], {
    cwd: root, encoding: 'utf8', timeout: 15000
  });
  if (r.error) throw r.error;
  if (r.status !== 0) throw new Error(r.stderr || r.stdout || `PHP exit ${r.status}`);
  return JSON.parse(r.stdout);
}
```

```js
import assert from 'node:assert/strict';
import {phpJson} from './helpers/php-probe.mjs';
const results = phpJson(`
require 'app/Models/StorefrontProductCollection.php';
echo json_encode([
 StorefrontProductCollection::pageMeta('2', 25),
 StorefrontProductCollection::pageMeta(['2'], 25),
 StorefrontProductCollection::discountPercent(100, 80, 0),
 StorefrontProductCollection::discountPercent(0, 0, 0)
]);`);
assert.equal(results[0].page, 2);
assert.equal(results[0].per_page, 24);
assert.equal(results[0].has_next, false);
assert.equal(results[1].page, 1);
assert.equal(results[2], 20);
assert.equal(results[3], null);
```

- [ ] **1.2 Run RED:** `node tests/storefront_product_collection_contract.test.mjs`. Verify failure identifies the missing new model/method, not a malformed test.
- [ ] **1.3 Implement pure rules.** Accept only scalar positive integer page values within `PHP_INT_MAX`; invalid input maps to 1. Clamp a positive page above the last page to the last page. Empty collections use page 1, total 0 and total_pages 1. Percentage display uses the active percentage when it is finite and in `(0,100]`; otherwise compute a reduction only from finite `old > current >= 0` with `old > 0`. Return null rather than invent a negative/invalid saving.

```php
$totalPages = max(1, (int) ceil($total / 24));
$validated = is_scalar($pageInput)
    ? filter_var($pageInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
$page = min($totalPages, $validated === false ? 1 : (int) $validated);
```

- [ ] **1.4 Implement bounded SQL selection.** Resolve visible IDs minus adult IDs before selecting products or calculating total. If none remain, return the empty metadata without constructing `IN ()`. Bind category IDs, rank slug, limit and offset with correct PDO types. Reject arbitrary collection names; never interpolate request-supplied SQL fragments.

Use a derived priced-product relation: active products joined to at most one current-rank price row and a grouped `MAX(discount_percent)` per product, restricted to active positive discounts. Preserve the existing rank-price-over-base rule, including zero rank prices. Apply the percentage once and round to two decimals before testing `old_price > current_price`. Use the same relation/predicate in COUNT and page queries. Check the existing rank-price uniqueness invariant rather than hiding duplicate data with an arbitrary new aggregation.

```sql
-- Shape of the qualification; :rank_slug and category placeholders are bound.
-- q contains exactly one row per eligible product and the current-price projection.
WHERE q.active_discount_percent > 0 OR q.old_price > q.current_price
ORDER BY q.id DESC
LIMIT :limit OFFSET :offset
```

Do not load the full catalog into PHP and filter after LIMIT. Do not add a membership discount a second time. Do not globally refactor `Product::getCurrentPrice()`; compare results against it using controlled fixtures. Localize and batch-load color variants for the selected page only. Use `$collectionItem` loop names, not leaked `$category`/`$product` variables before the shared header.

- [ ] **1.5 Verify behavior against actual SQL in an isolated database.** The integration script must require `ANABELKA_TEST_DSN`, `ANABELKA_TEST_USER`, `ANABELKA_TEST_PASSWORD`, and `ANABELKA_ALLOW_TEST_DB=1`; assert `SELECT DATABASE()` equals `anabelka_mobile_navigation_test`. It must never include the live application database config or default to KSWEB. Use fixtures and rollback transactional test data; no automatic production schema creation/deletion.

Fixture expectations: 25 eligible products give 24+1 rows with no duplicate IDs; `id DESC` is stable; two active badges produce one product with the maximum percentage; disabled badge alone does not qualify; `old_price=100,current=80` qualifies; equal prices do not qualify; rank price changes qualification consistently with product detail; an adult parent hides a child product even when the child has `is_adult=0`; inactive ancestors/departments are excluded from both rows and totals. Repeat with age confirmation enabled. A mock of price logic alone does not validate the SQL.

```bash
node tests/storefront_product_collection_contract.test.mjs
php -l app/Models/StorefrontProductCollection.php
php tests/integration/storefront_collections.php
git diff --check
```

Without the isolated database, record that integration run as pending, not passed.
- [ ] **1.6 Commit only the model and its tests/helper:** `feat: add non-adult storefront collections`.

## Task 2: Discounts and New Arrivals routes/cards

**Files:** Create `routes/StorefrontPages.php`, `app/Controllers/StorefrontPageController.php`, `views/storefront/discounts.php`, `views/storefront/new.php`, `views/storefront/partials/product-card.php`, `views/storefront/partials/pagination.php`, `css/storefront-pages.css`, and `tests/storefront_pages_contract.test.mjs`. Modify only required load registrations in `app/Core/App.php` and UI dictionaries in `app/Models/ContentInterfaceTranslator.php`.

**Interfaces:** Controller actions `discounts()` and `newArrivals()` consume `StorefrontProductCollection::page(...)` and pass `collection`, `currentLanguage`, `pageTitle`, `collectionPath` to the view. Partials receive one `$collectionItem` and the collection metadata; they perform no price/stock writes or per-card database queries.

- [ ] **2.1 Write RED route/card tests.** Verify the exact routes, a valid empty-state page, 24-item pagination links, escaped names/URLs, hex color validation, and absence of page-local adult context.

```js
import assert from 'node:assert/strict';
import fs from 'node:fs';
const routes = fs.readFileSync('routes/StorefrontPages.php', 'utf8');
assert.match(routes, /get\('\/discounts',\s*'StorefrontPageController@discounts'\)/);
assert.match(routes, /get\('\/new',\s*'StorefrontPageController@newArrivals'\)/);
const card = fs.readFileSync('views/storefront/partials/product-card.php', 'utf8');
assert.match(card, /current_price/);
assert.match(card, /color_variants/);
assert.doesNotMatch(card, /Database::connect|Product::getCurrentPrice/);
```

Add a PHP rendering fixture that supplies one item with an HTML-like name and missing/invalid color; assert escaped output, no executable attribute injection, and the established neutral color fallback. Test rendering with no `old_price`, then with a valid reduction.

- [ ] **2.2 Run RED:** `node tests/storefront_pages_contract.test.mjs`.
- [ ] **2.3 Register and implement the two actions and shared presentation.** Seed interface dictionaries before rendering; initialize `$isAdultCatalogContext = false` on these non-adult collection pages. Do not modify the existing home latest-products query merely to expose `/new`.

```php
$router->get('/discounts', 'StorefrontPageController@discounts');
$router->get('/new', 'StorefrontPageController@newArrivals');
```

Escape all names and attributes with `ENT_QUOTES, 'UTF-8'`; validate swatches against `/^#[0-9a-f]{6}$/i`. Display stored `old_price` struck through only when greater than `current_price`; show a percentage only when `display_discount_percent` is not null. Scope CSS under `.storefront-page`; keep two product columns at 320–430 px using `minmax(0,1fr)` and do not change global catalog geometry.

Pagination links use the fixed `collectionPath` and an integer page; mark the current page with `aria-current="page"`. A genuine empty result gives translated empty copy and no useless previous/next links. Unexpected database exceptions give HTTP 503 and safe error copy via existing public error presentation, not a misleading “no discounted products” success page; log details server-side.

- [ ] **2.4 Verify:** `node tests/storefront_pages_contract.test.mjs`, the collection suite, PHP lint on the controller and all new views, and `git diff --check`. Browser/direct-route checks must include both an empty and populated collection.
- [ ] **2.5 Commit:** `feat: add discounts and new-arrivals pages`.

## Task 3: Information pages without invented business details

**Files:** Extend `StorefrontPageController.php`, `routes/StorefrontPages.php`, `ContentInterfaceTranslator.php`, `storefront-pages.css`, and `tests/storefront_pages_contract.test.mjs`. Create `views/storefront/delivery-payment.php` and `contacts.php`.

**Interfaces:** `deliveryPayment()` renders three sections; `contacts()` renders approved configured contact values or neutral empty-state copy. This task creates neither a CMS nor new customer-data tables.

- [ ] **3.1 Add RED assertions for both destinations and safe content.**

```js
const routes = fs.readFileSync('routes/StorefrontPages.php', 'utf8');
assert.match(routes, /get\('\/delivery-payment',\s*'StorefrontPageController@deliveryPayment'\)/);
assert.match(routes, /get\('\/contacts',\s*'StorefrontPageController@contacts'\)/);
const info = fs.readFileSync('views/storefront/delivery-payment.php', 'utf8');
const ids = ['id="delivery"', 'id="payment"', 'id="returns"'].map(s => info.indexOf(s));
assert.ok(ids.every(x => x >= 0));
assert.deepEqual(ids, [...ids].sort((a,b) => a-b));
```

- [ ] **3.2 Run RED:** `node tests/storefront_pages_contract.test.mjs`.
- [ ] **3.3 Add the actions, routes and translated page copy.** Use Ukrainian headings `Доставка`, `Оплата`, `Повернення`, `Контакти`. Before including a factual business value, trace its existing configured source and record that source in the task notes. Never treat an administrator login email, the owner's personal phone, sample data, or another merchant's policy as shop contact/policy data. With no confirmed configured source use `Інформація уточнюється.` and `Контактні дані ще не опубліковані.` with RU/EN UI translations. No fake phone/email links.

```php
$router->get('/delivery-payment', 'StorefrontPageController@deliveryPayment');
$router->get('/contacts', 'StorefrontPageController@contacts');
```

- [ ] **3.4 Run GREEN and lint.** Open all four routes with current language and a fallback language; verify no 404, no fabricated terms and no horizontal overflow.
- [ ] **3.5 Commit:** `feat: add delivery-payment-returns and contacts pages`. Increment A is now independently usable without the bottom menu.

---

## Task 4: Manual menu schema and deployment checks

**Files:** Create the three dated SQL files in the file map and `tests/mobile_navigation_model_contract.test.mjs`. No SQL is executed against the owner's database by this task.

**Interfaces:** The two tables and columns are exactly those in spec §8; UA lives in `mobile_navigation_items.name_uk`. Initial source rows have orders 10,20,30,40 and the four approved internal URLs. Target translations start absent.

- [ ] **4.1 Write RED schema contracts.** Require both tables, source-name field, translation composite key, cascade FK, four seeds, and no ALTER/DROP of product/category/customer/order tables. Reject runtime migration registration in `App.php`.

```js
import assert from 'node:assert/strict';
import fs from 'node:fs';
const sql = fs.readFileSync('database/migrations/2026-09-17_mobile_navigation.sql', 'utf8');
assert.match(sql, /CREATE TABLE mobile_navigation_items\s*\(/i);
assert.match(sql, /name_uk VARCHAR\(160\) NOT NULL/i);
assert.match(sql, /PRIMARY KEY\s*\(item_id,\s*language_code\)/i);
assert.match(sql, /ON DELETE CASCADE\s+ON UPDATE RESTRICT/i);
assert.doesNotMatch(sql, /\b(?:DROP|TRUNCATE)\s+TABLE|\bALTER\s+TABLE/i);
for (const url of ['/Anabelka/discounts','/Anabelka/new','/Anabelka/delivery-payment','/Anabelka/contacts']) {
  assert.ok(sql.includes(url));
}
```

- [ ] **4.2 Run RED:** `node tests/mobile_navigation_model_contract.test.mjs`.
- [ ] **4.3 Write explicit manual SQL.** Use `INT UNSIGNED` consistently for item IDs, UTF8MB4/InnoDB, a unique FK symbol `fk_mobile_navigation_translation_item`, PK `(item_id, language_code)` and public-order index `(is_active, sort_order, id)`. Create the schema first, then put the four seed INSERTs in a transaction. DDL and seed DML are not one atomic transaction; document partial-state recovery explicitly.

```sql
INSERT INTO mobile_navigation_items (name_uk,url,is_external,is_active,sort_order)
VALUES
('Знижки','/Anabelka/discounts',0,1,10),
('Новинки','/Anabelka/new',0,1,20),
('Доставка, оплата і повернення','/Anabelka/delivery-payment',0,1,30),
('Контакти','/Anabelka/contacts',0,1,40);
```

Preflight uses INFORMATION_SCHEMA, reports database identity and `not_started`/`partial`/`tables_present`, and does not SELECT from absent tables. “Tables present” is not a claim the schema is correct. Postflight verifies columns, unsigned types, indexes, FK actions, initial source rows and no orphan translations. Four seed rows are a first-rollout check, not an invariant after admin edits/deletions.

- [ ] **4.4 Verify source contracts and a disposable-schema run when available.** Assert a rerun fails safely instead of overwriting an existing menu. Document recovery from tables-created-but-seeds-absent without dropping tables.
- [ ] **4.5 Commit:** `feat: add manual mobile-menu migration and checks`.

## Task 5: Safe menu model, transactions and public fallback

**Files:** Create `app/Models/MobileNavigation.php`, `MobileNavigationTranslator.php`, `tests/integration/mobile_navigation.php`. Extend model contracts and load model classes in `app/Core/App.php` only after the files exist.

**Interfaces:**

- `MobileNavigation::validateUrl($input): array` returns `['url'=>string,'is_external'=>bool]` or throws `InvalidArgumentException`.
- `publicItems(string $languageCode): array` returns ordered `id,name,url,is_external` rows; `adminItems(): array` returns source rows with translations and an ordering revision.
- `create(array $input): int`, `update(int $id,array $input): void`, `setActive(int $id,bool $active): void`, `reorder(array $ids,string $revision): void`, `delete(int $id): void`.
- `MobileNavigationTranslator::localizeList(array $items,string $languageCode): array`, `getForItem(int $id): array`, `save(int $id,string $code,array $input): void`, `markOutdated(int $id): void`.
- `save` consumes validated active language codes; source-language rows are never created in the target table.

- [ ] **5.1 Add executable RED URL tests.**

```js
import {phpJson} from './helpers/php-probe.mjs';
const result = phpJson(`
require 'app/Models/MobileNavigation.php';
$out=[];
foreach (['/Anabelka/new','https://example.com/offers','javascript:alert(1)','//example.com','/Anabelka/../outside'] as $url) {
 try { $out[]=MobileNavigation::validateUrl($url); }
 catch (InvalidArgumentException $e) { $out[]=false; }
}
echo json_encode($out);`);
assert.equal(result[0].is_external, false);
assert.equal(result[1].is_external, true);
assert.deepEqual(result.slice(2), [false,false,false]);
```

Also test empty/array/over-1000-character inputs, CR/LF/NUL (literal and encoded), backslashes, protocol-relative URLs, `data:`, `file:`, encoded path traversal, valid query/fragment, valid HTTPS with a port, and false client-supplied `is_external`.

- [ ] **5.2 Run RED:** `node tests/mobile_navigation_model_contract.test.mjs`.
- [ ] **5.3 Implement one URL validator.** Reject controls before trimming surrounding spaces. Internal path must remain `/Anabelka` or a child after validation of percent-decoding and dot segments; reject escaping that prefix. Reject backslashes and ambiguous nested encoding rather than relying on browser normalization. External links require an absolute HTTP(S) scheme, a valid host and no embedded username/password; classify on the server. Do not fetch URLs to “check” them. Invalid saved rows are not emitted as executable links.

Validate source/target names as non-empty plain text of at most 160 Unicode characters, active language membership, positive IDs and boolean statuses. Use prepared statements. Pre-resolve language/schema helpers before beginning a write transaction; new menu model/translator methods must not perform runtime DDL or invoke schema-seeding code inside it.

Create/update plus submitted translations use one transaction. On source change, mark all existing targets outdated; a submitted target that was actually rewritten for the new source can be saved with its explicit workflow state. Do not blindly reapprove unchanged translations from the old form. Distinguish omitted target fields (preserve) from an intentionally submitted blank target (remove that translation).

Reorder locks current item rows, verifies the supplied IDs are a complete unique permutation, verifies a revision hash of the existing order, writes sequential sort orders, then commits. Include disabled rows so reenabling preserves ordering. On stale revision reject with a conflict; no partial order save. Delete affects only the menu item and its translations, never the linked product/page.

- [ ] **5.4 Implement public reads and failure behavior.** One batch translation read; only active items ordered by `sort_order,id`. Approved/outdated nonblank translations may display under existing public semantics; draft/review/missing falls back to UA. Use the four safe in-memory source links only on database/read failure and log once per request. An intentionally empty menu or all-disabled menu stays empty and gets translated empty-state copy; never resurrect seed links. No seed/CREATE statements in public request paths.

- [ ] **5.5 Verify actual CRUD on the opt-in isolated test DB.** Use the same DSN guard as Task 1. Assert atomic rollback on a failed target save, stale reorder rejection, no partial ID mutation, cascade delete, intentional empty-menu behavior, translation fallback, and unavailable-table fallback. Run source contracts and PHP lint as well; missing DB means integration pending.
- [ ] **5.6 Commit:** `feat: add validated multilingual mobile-menu storage`.

## Task 6: Missing/outdated translation workflow integration

**Files:** Modify `TranslationDashboardService.php`, `AdminTranslationController.php`, `views/admin/translations/index.php`, `views/admin/translations/missing.php`; extend `MobileNavigationTranslator.php`. Create `tests/mobile_navigation_translation_contract.test.mjs`.

**Interfaces:** New section key `mobile_navigation`, entity type `mobile_navigation_item`, editor URL `/Anabelka/admin/mobile-navigation?highlight=<id>&language=<code>`. Missing rows use the existing `entity_id,entity_name,language_code,translation_source,translation_status,translation_has_content` shape consumed by `groupMissingRows()`.

- [ ] **6.1 Add RED fixtures** for two items × two active target languages, one approved, one outdated, one draft and one missing translation. Required coverage is 4, approved coverage is 1, outstanding is 3; inactive languages do not increase totals. Change the source and verify the formerly approved target becomes outdated.

```js
import assert from 'node:assert/strict';
import fs from 'node:fs';
const service = fs.readFileSync('app/Services/TranslationDashboardService.php','utf8');
assert.match(service, /mobile_navigation/);
assert.match(service, /missingMobileNavigation/);
assert.match(service, /mobile_navigation_item_translations/);
```

The static assertions are wiring checks, not substitutes for the fixture results.

- [ ] **6.2 Run RED:** `node tests/mobile_navigation_translation_contract.test.mjs`.
- [ ] **6.3 Implement coverage and navigation.** Add the section to allowed sections, missing dispatch, aggregate coverage, per-language coverage and the zero-target-languages branch. Use the existing grouping/filtering helpers. Missing SQL joins active non-source languages to `mobile_navigation_items.name_uk` and left-joins targets; blank/missing or non-approved targets remain outstanding. Include disabled menu items in admin translation coverage so prepared seasonal links can be translated.

Expose source-language/name search and language filtering, link to the correct highlighted editor row, and focus the selected missing-language name input. If menu tables do not exist, keep existing translation sections functional and show the new section as unavailable, not “100% translated”. Never auto-migrate from `prepareStorage()`.

- [ ] **6.4 Verify GREEN:** translation suite, model suite and existing translation-related suites. Test missing table, zero target languages, long source name, removed item and disabled language.
- [ ] **6.5 Commit:** `feat: integrate mobile-menu translation workflow`.

## Task 7: Admin menu editor and authorization

**Files:** Create `AdminMobileNavigationController.php`, `routes/MobileNavigation.php`, `views/admin/mobile-navigation/index.php`, `css/admin-mobile-navigation.css`, `js/admin-mobile-navigation.js`, and `tests/admin_mobile_navigation_contract.test.mjs`. Modify `App.php`, `AdminAccess.php`, `views/admin/partials/header.php`, and interface dictionaries.

**Permissions:** `mobile_navigation.view` for reading; `mobile_navigation.manage` for every mutation.

**Interfaces:** GET `/admin/mobile-navigation` → `index`; POST suffixes `/create`, `/update`, `/toggle`, `/move`, `/delete` → matching methods. Use JSON `{ok:true,message:string}` on success; validation 422, conflict 409, expired CSRF 419 and safe unexpected-error 500/503 responses. All error responses include `{ok:false,message:string}`; do not expose SQL/stack traces.

- [ ] **7.1 Write RED guard/action tests.** Test through the router as well as direct controller fixtures: unauthenticated denied, view-only cannot mutate, manager can mutate, missing/incorrect CSRF cannot write. Confirm every mutation audits the acting administrator ID, not merely a null actor.

```js
import assert from 'node:assert/strict';
import fs from 'node:fs';
const access = fs.readFileSync('app/Models/AdminAccess.php','utf8');
assert.match(access, /'\/admin\/mobile-navigation'\s*=>\s*\['mobile_navigation\.view',\s*'mobile_navigation\.manage'\]/);
const controller = fs.readFileSync('app/Controllers/AdminMobileNavigationController.php','utf8');
assert.match(controller, /AdminAccess::verifyCsrf/);
assert.match(controller, /AdminAccess::currentId/);
assert.match(controller, /AdminAccess::audit/);
```

- [ ] **7.2 Run RED:** `node tests/admin_mobile_navigation_contract.test.mjs`.
- [ ] **7.3 Implement endpoints and permission wiring.** Add view/manage permission seeds and content-manager defaults. Retain owner/store-owner/administrator behavior through existing role seeding and saved overrides. Do not grant order-manager access. Show “Мобільне меню” under Content only to `.view`; all POST requests require `.manage` plus CSRF. Explicitly pass `AdminAccess::currentId()` to audit after successful mutations. GET performs no menu creation/updates.

```php
$router->get('/admin/mobile-navigation', 'AdminMobileNavigationController@index');
foreach (['create', 'update', 'toggle', 'move', 'delete'] as $action) {
    $router->post('/admin/mobile-navigation/' . $action,
        'AdminMobileNavigationController@' . $action);
}
```

- [ ] **7.4 Implement the editor.** One top Add button; flat rows with source name, URL, enabled status, edit/delete and a drag handle. Drag initiates only from its handle using Pointer Events; also provide accessible up/down controls. Keep form nodes stable while typing; no full-list rerender on input/focus. Disable duplicate saves while pending, show errors without discarding input, and preserve the form on network failure. After success persist feedback through the existing notification/flash API, then reload. On delete use existing `AnabelkaDialog` confirmation, not an unrelated new modal framework.

Translation fields are generated from active languages and use current workflow labels. Highlight/deep-link behavior consumes Task 6's query parameters. Any empty “all items removed” state offers Add, not automatic seeds. If the migration is missing, show an explicit migration-required notice and disable writes.

- [ ] **7.5 Verify GREEN:** guards/CSRF, create/update/toggle/reorder/delete, cancel-delete, external URL rejection, 320px editor layout, preserving focus/caret while editing, source-change/outdated behavior and notification survival across reload.
- [ ] **7.6 Commit:** `feat: add mobile-menu admin editor`. Increment B is usable before activating the public bottom panel.

---

## Task 8: Navigation markup, shared state and counter relocation

**Files:** Create the three mobile partials, `css/mobile-navigation.css`, `js/mobile-navigation.js`, `tests/mobile_navigation_contract.test.mjs`, `tests/mobile_navigation_runtime.test.mjs`. Modify `views/partials/header.php` narrowly. Existing badge/updater scripts are read first; modify them only if an evidenced integration requirement cannot be met by preserving their nodes.

**Interfaces:** Header prepares one `$mobileNavigationContext` array with `eligible`, `profile_url`, `profile_label`, `items`, `has_admin`, and translated labels, derived from current existing session/bootstrap data. Public admin validity comes from `$currentAdmin`, never a client flag. New `window.AnabelkaMobileNavigation` exposes `openMenu()`, `closeMenu()`, `isOpen()` after initialization; DOM initialization must be idempotent.

- [ ] **8.1 Write RED markup and identity tests.** In rendered guest/customer/admin fixtures assert exactly one of each canonical counter ID: `favorite-count`, `cart-count`, `profile-notification-count`, and `admin-system-error-count` when applicable. At 430px the cart/admin elements must be the same node objects as before relocation; at 431px they must return to their original order. A guest has no admin link or system-badge fetch. Verify a real 18+ category still changes only the Favorites icon, not its destination/count.

```js
// Inside the DOM runtime fixture, after loading the real mobile-navigation.js:
const cart = document.querySelector('.header-cart');
const badge = document.getElementById('cart-count');
window.dispatchEvent(new Event('resize'));
assert.equal(document.querySelectorAll('#cart-count').length, 1);
assert.equal(document.getElementById('cart-count'), badge);
assert.equal(document.querySelector('.header-cart'), cart);
```

The runtime harness controls matchMedia transitions; resize alone must not be mistaken for a viewport-change simulation. Fixtures must execute the actual script, not reimplement its relocation algorithm.

- [ ] **8.2 Run RED:** both mobile suites, with explicit missing partial/API failures.
- [ ] **8.3 Extract/present without cloning counters.** Preserve desktop structure by marking original cart/admin positions with comment anchors. Render a separate mobile Profile/Login link from prepared data and move its existing unread-count span from desktop summary into that link when mobile is active. Keep the desktop profile popover intact and restore the badge to it above 430px. Move the existing cart/admin action nodes, not copies, into bottom-nav slots; node-capturing scripts keep their references.

```js
function makeHome(node) {
  const anchor = document.createComment('anabelka-navigation-home');
  node.parentNode.insertBefore(anchor, node);
  return {node, anchor};
}
function restoreHome(entry) {
  entry.anchor.parentNode.insertBefore(entry.node, entry.anchor.nextSibling);
}
```

Collect and validate nodes before changing anything. On initialization failure restore moved nodes and keep the original header functional. Apply the ready class only after a successful move. No-JS/no-init fallback retains existing navigation instead of hiding both copies. Mobile-only content shortcuts use inline SVG, `currentColor`, labels and titles; hide them until ready. Favorites and Language never move.

On exact checkout/quick-order form routes, omit bottom-nav/sheet/shortcuts partials; the mobile media rule hides original Profile/Cart/Admin, leaving logo/Favorites/language/search. Above 430px preserve those desktop actions even on checkout. On admin routes retain the early return to the admin header.

- [ ] **8.4 Verify existing writers.** Search the complete checkout with `git grep -n -E 'cart-count|profile-notification-count|admin-system-error-count|header-cart|public-header-admin-action' -- js views css`. Exercise the identified scripts while mobile and after restoring desktop; do not add polling or MutationObservers just to mirror duplicate counters. Preserve established error-badge priority and formatCount behavior.
- [ ] **8.5 Commit after both new suites and relevant existing badge tests pass:** `feat: add responsive mobile navigation adapter`.

## Task 9: Sheet focus, scroll and browser-history state machine

**Files:** Extend `mobile-menu-sheet.php`, `mobile-navigation.js`, `mobile-navigation.css`, and `mobile_navigation_runtime.test.mjs`. No database changes.

**Interfaces:** `openMenu()`, `closeMenu()`, `isOpen()` from Task 8; one owned history sentinel keyed `anabelkaMobileMenu`. Opening retains the visible URL and existing history state. Only this component's current sentinel may be consumed by its close operation.

- [ ] **9.1 Write RED transition tests.** Closed→open pushes once; double-open does not push twice; X/backdrop/Escape close once; Back closes without a second back call; Forward into an owned entry is handled coherently; unrelated `popstate` is not consumed. Test open→resize above 430→close, repeated taps during pending close, internal link activation, external/new-tab link activation, focus return and restoration of previous inline scroll styles.

```js
assert.equal(window.AnabelkaMobileNavigation.isOpen(), false);
window.AnabelkaMobileNavigation.openMenu();
assert.equal(window.AnabelkaMobileNavigation.isOpen(), true);
assert.equal(document.querySelector('[data-mobile-menu-toggle]').getAttribute('aria-expanded'), 'true');
window.AnabelkaMobileNavigation.openMenu();
assert.equal(historyPushes.length, 1);
```

`historyPushes` is the runtime fixture's recorded call array around the actual `history.pushState`; it is not an alternate menu implementation.

- [ ] **9.2 Run RED:** `node tests/mobile_navigation_runtime.test.mjs`.
- [ ] **9.3 Implement explicit states** `closed`, `open`, `closing-history`; retain an instance-specific token and original scroll position/styles. Merge the sentinel into current history state rather than erasing another component's state. `popstate` closes/restores locally without calling `history.back()` again. For X/Escape/backdrop, call back only if the current sentinel belongs to this instance; complete cleanup in `popstate`. Suppress duplicate close operations until settled.

For a normal same-tab menu link, prevent default, remove the owned sentinel, then navigate to the captured validated href; do not race a queued history traversal against the new navigation. Do not intercept modifier/new-tab activation. External HTTP(S) anchors remain real user-activated `target="_blank" rel="noopener noreferrer"` links; their menu cleanup must not depend on a popup call after an asynchronous wait. Resize above 430px cleans up the sheet and scroll lock without removing unrelated history entries. Reload/back-forward restoration of a marked entry must not leave the page locked or reopen an invisible desktop sheet.

Use dialog semantics (`role="dialog"`, `aria-modal="true"`, labelled heading), a real close button, `aria-expanded` on the trigger, focus containment while open and focus return on close. Hide/inert the closed sheet so its links are not in the tab order. Preserve and restore preexisting page overflow/position styles and scroll coordinates. Keep the sheet internally scrollable and buttons reachable at enlarged text/short viewport heights. Respect reduced-motion preference.

- [ ] **9.4 Run GREEN:** runtime transitions plus a real mobile-browser Back/Forward test. A fake history unit test alone does not establish Android behavior.
- [ ] **9.5 Commit:** `feat: add accessible mobile-menu sheet behavior`.

## Task 10: Responsive geometry, safe-area, shortcuts and asset versions

**Files:** Extend `mobile-navigation.css`; adjust only required rules in `public-header.css`, mobile partials, `views/home.php`, and the shared header. Create `tests/browser/mobile_navigation.spec.mjs`; extend mobile/page contracts.

**Interfaces:** Ready-state class on `<html>` enables mobile presentation only under `max-width:430px`; the same height variable drives bottom-nav height, content compensation and sheet bottom offset. No UA sniffing, no server-side guesses about viewport width.

- [ ] **10.1 Add RED layout assertions:** 320,360,375,390,412,430px with guest/admin and counts 0/1/99/100; 431,768,1049,1050,1280px for unchanged non-mobile layout. Check no horizontal overflow, fully visible logo, lower content scrollable above the bar and closed sheet absent from focus order.

```css
@media (max-width: 430px) {
  html.has-mobile-navigation {
    --mobile-nav-height: 64px;
    --mobile-nav-bottom: env(safe-area-inset-bottom, 0px);
  }
  html.has-mobile-navigation body {
    padding-bottom: calc(var(--mobile-nav-height) + var(--mobile-nav-bottom) + 12px);
  }
  .mobile-bottom-navigation {
    position: fixed;
    inset-inline: 0;
    bottom: 0;
    min-height: var(--mobile-nav-height);
    padding-bottom: var(--mobile-nav-bottom);
  }
}
```

This is the intended geometry fragment, not permission to overwrite a page's existing larger bottom padding. Compose with the existing layout. Constrain new CSS to mobile-ready public pages; audit fixed-position containing blocks and stacking contexts before choosing final z-index values.

- [ ] **10.2 Run RED** against a real browser fixture when available and the semantic source contracts. Record browser prerequisites separately; do not silently skip and call the geometry verified.
- [ ] **10.3 Implement the layout.** Use 3 or 4 equal flex/grid cells from actual rendered children, not a blank admin slot. Use Anabelka's existing white/soft-purple panel, border and purple icons. Retain the approved rounded-frames admin SVG and existing badge styling. Fit the top row using the already approved compact-width approach; touch height stays at least 44px, full logo has no clipping/ellipsis. Search remains immediately below. Preserve the existing desktop/profile behavior.

Hide the redundant `home-useful-menu` only at ≤430px after navigation initialization; keep it for non-mobile widths and no-JS fallback. Preserve the existing route to the catalog outside ☰; do not add a fifth default sheet item. New public pages reuse the existing desktop sidebar, not a new layout. When the on-screen keyboard opens, the panel/sheet must not obscure a focused input or submit control; verify with login and registration, preserving pinch zoom. Close/open layers must not cover notification dialogs incorrectly.

Bump every modified existing asset URL in all relevant consumers; start genuinely new assets at `v=1`. Include scoped styles for moved elements that previously depended on `.public-header` ancestry. Test stylesheet references and loading, but do not freeze unrelated historical version numbers in broad contracts.

- [ ] **10.4 Verify screenshots/computed layout and real Android.** Record JS-disabled fallback, keyboard, orientation/431px transition, long translated menu labels, safe-area, admin/no-admin, checkout/quick-order exceptions, cart increment and system-error badge behavior. Do not claim desktop sidebar/scrollbar accepted until its separate postponed manual check is completed.
- [ ] **10.5 Commit:** `style: finish mobile navigation layout and cache busting`.

## Task 11: Integration, targeted contract maintenance and deployment handoff

**Files:** Update only affected expectations in `tests/final_review_mobile_header_variant_a_contract.test.mjs`, `tests/public_header_admin_contract.test.mjs`, relevant home/menu contracts, and finally `tests/category_manager_contract.test.mjs`. Add `docs/testing/2026-09-17-mobile-navigation-verification.md` and `docs/deployment/2026-09-17-mobile-navigation.md`. Update PR #4 description via the connector without changing draft/base/merge state.

**Interfaces:** Verification report separates source/unit, PHP runtime, isolated SQL, real browser, and owner-reported Android checks. Deployment guide references the existing category and home-content migrations as well as the new menu migration; it never instructs blind reapplication.

- [ ] **11.1 Establish coverage before removing an obsolete assertion.** Map each old header/notification expectation to a named replacement test. Keep category transaction, FK, inheritance, CSRF, translation and existing non-mobile assertions. Replace only mobile behavior intentionally superseded by the approved spec. Do not delete a suite or weaken a failing check merely to get green output. Mobile header's old Catalog/Admin top-row rule is superseded; desktop admin behavior is not.
- [ ] **11.2 Run all suites without exclusions** using the start-of-execution loop. Record the total file count, all failures, exit codes and current SHA. PHP-lint changed files using `git diff --name-only --diff-filter=ACMR` from the execution baseline; do not lint only a single hand-picked file. Run browser/integration commands separately and record missing runners as pending.

```bash
node tests/storefront_product_collection_contract.test.mjs
node tests/storefront_pages_contract.test.mjs
node tests/mobile_navigation_model_contract.test.mjs
node tests/mobile_navigation_translation_contract.test.mjs
node tests/admin_mobile_navigation_contract.test.mjs
node tests/mobile_navigation_contract.test.mjs
node tests/mobile_navigation_runtime.test.mjs
php tests/integration/storefront_collections.php
php tests/integration/mobile_navigation.php
# With an installed browser runner and the isolated fixture server:
node tests/browser/mobile_navigation.spec.mjs
git diff --check
```

- [ ] **11.3 Complete manual acceptance** without requiring the owner to invent scenarios: sign out of admin in a separate session→3 bottom items; sign in→4; checkout/quick-order→no bar/shortcuts; login/register→bar present; open/back/forward sheet→correct history; add/disable/rename/reorder a test menu link→public state follows after refresh; missing target→UA; source changed→outdated; external link→new tab; both collections→no adult products even after confirmation; 25-item collection→24+1; final content not hidden under the bar.
- [ ] **11.4 Review code before deployment.** Use a fresh reviewer when available, otherwise label the review as self-review. Resolve evidenced Critical/Important defects. Preserve test logs/diffs; never label historical PR statements as a fresh review.
- [ ] **11.5 Prepare the manual rollout.** Order: verify backup and current migration state→inspect missing schema only→owner applies approved menu SQL→postflight→deploy/refresh compatible PHP and assets→owner device checks. Public menu fallback is not proof that migration succeeded. On rollback revert code/feature presentation safely while retaining menu data; no DROP TABLE rollback. Do not automatically change the working KSWEB database.
- [ ] **11.6 Update deployment description and commit** `test: reconcile navigation contracts and document rollout`. Report unverified desktop sidebar/scrollbar separately. Leave draft PR #4 open and unmerged; ZIP backup/merge remain separate owner-directed actions.

---

## Spec-to-task coverage and handoff

| Spec requirement | Implementation/check owner |
| --- | --- |
| §1–5 mobile-only scope, top row, 3/4 bottom actions, checkout exception | Tasks 8,10,11 |
| §6 sheet, focus, scroll, Android/browser Back | Task 9 and real-browser acceptance |
| §7–10 editable multilingual menu, URL safety, rights, CSRF, audit | Tasks 4–7 |
| §11–14 routes, shared collections, prices, pagination, non-adult filtering | Tasks 1–3 |
| §15–16 information pages and no invented business facts | Task 3 |
| §17 module boundaries and shared counter state | File map and Task 8 |
| §18 manual migration, seeds, fallback | Tasks 4,5,11 |
| §19–22 testing, non-goals, acceptance and no merge | Global constraints and Task 11 |

All implementation checkboxes intentionally remain unchecked when this plan is published. The first executable increment is Task 1; it does not change the visible header or require an owner database migration. Finish and verify one increment before activating its dependent presentation.

Execution may proceed sequentially in the current conversation using the actual available tools. Where source/unit tests can run locally, include their RED→GREEN evidence in the task result rather than adding another phone-only confirmation round. Report the precise scope of every verification result; never equate a regex contract with a live browser or database test.
