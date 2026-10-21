# Mobile Navigation and Storefront Pages Design

Date: 2026-09-17
Status: Approved in chat, pending written-spec review
Branch: `feature/category-manager`
PR: draft #4, must remain unmerged until separate owner approval

## 1. Goal

Add a mobile-only navigation system for public Anabelka pages at widths up to 430 px, move profile/cart/admin actions from the mobile header into a fixed bottom bar, add mobile header shortcuts for News, Reviews and Gift Certificates, and make the hamburger menu editable from the admin panel.

The same change also adds four public storefront pages:

- `/Anabelka/discounts`
- `/Anabelka/new`
- `/Anabelka/delivery-payment`
- `/Anabelka/contacts`

The new work must preserve the existing desktop header/layout, existing Favorites-to-strawberry behavior in adult context, existing cart/profile/admin counters and badges, current language handling, and current 18+ access rules.

## 2. Scope classification

This is an architectural change, not a bounded header tweak. It introduces a reusable public mobile-navigation subsystem, an admin-managed menu model, a migration, new permissions, four public routes/pages, product collection logic, and new test coverage.

Implementation must not begin until this written design is reviewed and approved.

## 3. Approved mobile header composition

At `max-width: 430px`, normal public pages use this top row:

`[Анабелька] [♡/🍓] [News] [Reviews] [Gift] [UA]`

The search row remains immediately below it.

Rules:

- The full `Анабелька` logo remains visible and unclipped.
- Favorites stays directly to the right of the logo.
- Favorites keeps the current badge/count behavior.
- In an adult catalog/product context, Favorites keeps the existing strawberry-icon substitution; its link and badge behavior remain unchanged.
- News is an icon-only link to `/Anabelka/news`.
- Reviews is an icon-only link to `/Anabelka/reviews`.
- Gift Certificates is an icon-only link to `/Anabelka/gift-certificates`.
- Language remains the current compact language control at the right edge.
- News/Reviews/Gift icons use inline SVG with `currentColor`; no generated image assets are required.
- Every icon-only action has an accessible `aria-label` and `title`.

At widths above 430 px, the current desktop/tablet header composition remains unchanged.

## 4. Checkout and quick-order exception

On `/Anabelka/checkout` and `/Anabelka/quick-order`:

- the fixed mobile bottom navigation is not rendered;
- the mobile News/Reviews/Gift shortcuts are not rendered;
- the mobile header keeps only the logo, Favorites, language and search;
- no new navigation element may cover or distract from checkout/quick-order actions.

This exception applies only to the two approved flows above. Login and registration keep the normal mobile bottom navigation.

## 5. Fixed mobile bottom navigation

At `max-width: 430px`, normal public pages get a fixed bottom navigation bar.

For guests or customers without an active admin session:

`[☰ Menu] [Profile/Login] [Cart]`

For a visitor with an active admin session:

`[☰ Menu] [Profile/Login] [Cart] [Admin]`

There is no reserved blank fourth cell when Admin is absent; three items stretch evenly across the width. When Admin exists, four items stretch evenly.

### 5.1 Menu item

The first item is always the hamburger `☰` button. It opens the mobile menu bottom sheet and does not navigate directly.

### 5.2 Profile/Login item

- Guest: label `Вхід`, link `/Anabelka/login`.
- Authenticated customer: label `Профіль`, link `/Anabelka/account`.
- Existing customer unread-notification count moves with this action into the bottom bar.

### 5.3 Cart item

- Link `/Anabelka/cart`.
- Existing cart count moves with the cart action into the bottom bar.

### 5.4 Admin item

- Render only when the existing public-header admin-session logic resolves a current admin.
- Link `/Anabelka/admin`.
- Existing admin notification and system-error badges move with this action into the bottom bar.

### 5.5 Layout and safe area

- Use `position: fixed` at the viewport bottom.
- Buttons remain at least 44 px high.
- Use `env(safe-area-inset-bottom)` so the panel works on Android/iPhone browser chrome and devices with a bottom safe area.
- Pages that render the bar get enough bottom padding that the final product, button or footer content is never hidden underneath it.
- The bar must not produce horizontal overflow from 320 through 430 px.

## 6. Mobile hamburger bottom sheet

Pressing `☰` opens a bottom sheet above the fixed bar.

Behavior:

- panel slides from the bottom;
- backdrop dims the page;
- background-page scrolling is locked while open;
- close button `×` closes it;
- tapping the backdrop closes it;
- Escape closes it where a keyboard is present;
- Android/browser Back closes the menu first rather than immediately leaving the page;
- focus is moved into the sheet on open and returned to the hamburger trigger on close;
- the sheet has correct `aria-hidden` / `aria-expanded` state.

For Android Back behavior, opening the sheet adds a temporary `history.pushState` sentinel without changing the visible URL. `popstate` closes the sheet. Programmatic close paths must remove only the sentinel created by this component and must not consume an unrelated browser-history entry.

## 7. Admin-managed hamburger menu

The hamburger list is data-driven, not hardcoded in the public template.

A new admin section `Мобільне меню` is added to the existing Content group. It is flat in this phase; nested submenus are explicitly out of scope.

The admin can:

- create a menu item;
- rename its Ukrainian source name;
- add/edit translations for active non-source languages;
- edit its URL;
- enable/disable it without deleting it;
- reorder items by drag/move controls;
- delete it;
- use either an internal Anabelka URL or an external HTTP(S) URL.

Initial items after the one-time migration are:

1. `Знижки` → `/Anabelka/discounts`
2. `Новинки` → `/Anabelka/new`
3. `Доставка, оплата і повернення` → `/Anabelka/delivery-payment`
4. `Контакти` → `/Anabelka/contacts`

The source language is Ukrainian. RU/EN are not hardcoded columns; they are translation rows so future languages work without schema changes.

## 8. Mobile menu data model

### 8.1 `mobile_navigation_items`

Columns:

- `id` INT UNSIGNED PK AUTO_INCREMENT
- `name_uk` VARCHAR(160) NOT NULL
- `url` VARCHAR(1000) NOT NULL
- `is_external` TINYINT(1) NOT NULL DEFAULT 0
- `is_active` TINYINT(1) NOT NULL DEFAULT 1
- `sort_order` INT NOT NULL DEFAULT 0
- `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
- `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP

Indexes:

- primary key on `id`
- index on `(is_active, sort_order, id)`

### 8.2 `mobile_navigation_item_translations`

Columns:

- `item_id` INT UNSIGNED NOT NULL
- `language_code` VARCHAR(10) NOT NULL
- `name` VARCHAR(160) NOT NULL
- `source` VARCHAR(20) NOT NULL DEFAULT `manual`
- `status` VARCHAR(20) NOT NULL DEFAULT `approved`
- `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
- `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP

Keys:

- primary key `(item_id, language_code)`
- index on `language_code`
- FK `item_id` → `mobile_navigation_items.id` with `ON DELETE CASCADE`, `ON UPDATE RESTRICT`

When `name_uk` changes, existing non-source translations are marked `outdated` through the same translation-workflow semantics used elsewhere in Anabelka.

Missing/outdated mobile-menu translations must be discoverable through the existing translation workflow rather than being silently ignored. Public rendering falls back to `name_uk` when the current-language translation is absent or unusable.

## 9. URL validation and link behavior

A single model/service validator owns URL safety.

Internal URL rules:

- allow `/Anabelka` and paths beginning `/Anabelka/`;
- reject protocol-relative `//...` values;
- reject CR/LF/control characters;
- render in the current tab.

External URL rules:

- only absolute `http://` or `https://` URLs are accepted;
- a valid host is required;
- reject `javascript:`, `data:`, `file:`, protocol-relative URLs and control characters;
- render with `target="_blank" rel="noopener noreferrer"`.

`is_external` is derived/validated against the URL at save time rather than trusted blindly from POST data.

## 10. Admin permissions, CSRF and audit

Add permissions:

- `mobile_navigation.view`
- `mobile_navigation.manage`

They use the existing `AdminAccess::permissionForRequest()` pattern under `/admin/mobile-navigation`.

- GET index requires `.view`.
- Every POST action requires `.manage` through the router guard.
- Every POST action also verifies `AdminAccess` CSRF.
- Create/update/toggle/move/delete actions write to the existing admin audit log.
- Owner behavior remains unchanged: the existing owner bypass continues to grant access.
- Existing seeded content-management role defaults should receive the new permissions consistently with the current News/Reviews content permissions; implementation must inspect the current role seed before changing grants rather than inventing a new role model.

## 11. Public storefront routes

Add routes:

- `GET /discounts` → `StorefrontPageController@discounts`
- `GET /new` → `StorefrontPageController@newArrivals`
- `GET /delivery-payment` → `StorefrontPageController@deliveryPayment`
- `GET /contacts` → `StorefrontPageController@contacts`

The controller method is named `newArrivals`, not `new`, to avoid a reserved-keyword method name while keeping the approved public URL `/Anabelka/new`.

## 12. Shared storefront product collection

Create `StorefrontProductCollection` as the single selection layer for Discounts and New Arrivals.

It is responsible for:

- active products only;
- only products whose category is effectively visible;
- always excluding all effectively adult categories, even when the visitor has already confirmed age;
- current-language product localization via the existing `ProductTranslator` flow;
- batched color-variant loading using the existing `ProductImage::colorVariantsForProducts()` path;
- stable pagination metadata;
- 24 products per page;
- rejecting/sanitizing invalid page numbers to page 1.

No new product or category tables are introduced.

## 13. Discounts page

Route: `/Anabelka/discounts`

A product qualifies when at least one of these conditions is true:

1. it has an active product badge with `discount_percent > 0`; or
2. `old_price` is greater than the effective current price for the current visitor.

The effective current price follows existing Anabelka price semantics: current rank price when available, otherwise normal product price, then the active percentage discount is applied.

Because the approved rule compares `old_price` to the visitor's effective current price, membership/rank may affect whether condition 2 qualifies a product. Active `discount_percent` always qualifies the product.

The collection query should remain rank-aware so pagination happens after the correct qualification, not by loading the entire catalog and filtering it in PHP.

Order: newest qualifying products first (`id DESC`) unless a later product-sort feature explicitly changes it.

Card content:

- product image;
- localized name;
- color swatches using the current product-card convention;
- effective current price;
- crossed-out `old_price` when `old_price > current_price`;
- discount percentage:
  - use the active badge percentage when present;
  - otherwise calculate `(old_price - current_price) / old_price * 100` when possible;
  - do not invent a percentage when the values are invalid.

The page has an explicit empty state when no qualifying products exist.

## 14. New Arrivals page

Route: `/Anabelka/new`

Rules intentionally match the existing home `Новинки` selection:

- active products only;
- visible categories only;
- all effectively adult categories excluded;
- newest first by product `id DESC`;
- 24 products per page;
- current-language localization and batched color variants.

The home page may continue to show its current small latest-products subset. The new page is the full paginated destination and does not replace the home block.

## 15. Delivery, payment and returns page

Route: `/Anabelka/delivery-payment`

One page contains three sections in this order:

1. Доставка
2. Оплата
3. Повернення

This phase creates the route, accessible page structure and translatable interface copy. It must not fabricate business facts that are not already configured in Anabelka. If a factual detail such as a payment method or return term has no authoritative project source yet, omit it or show a neutral not-configured state rather than inventing it.

A separate CMS/editor for this informational copy is out of scope for this phase.

## 16. Contacts page

Route: `/Anabelka/contacts`

The page is a stable future destination for:

- phone;
- email;
- messengers;
- working hours;
- address/requisites when they are later approved.

This phase must not invent contact details. It renders only authoritative configured values that already exist in the project; otherwise it shows a neutral translatable not-configured state.

A dedicated contacts CMS is out of scope for this phase.

## 17. Code structure

New primary units:

- `app/Models/MobileNavigation.php`
- `app/Models/MobileNavigationTranslator.php`
- `app/Models/StorefrontProductCollection.php`
- `app/Controllers/AdminMobileNavigationController.php`
- `app/Controllers/StorefrontPageController.php`
- `views/partials/mobile-bottom-navigation.php`
- `views/partials/mobile-menu-sheet.php`
- `views/admin/mobile-navigation/index.php`
- `views/storefront/discounts.php`
- `views/storefront/new.php`
- `views/storefront/delivery-payment.php`
- `views/storefront/contacts.php`
- `css/mobile-navigation.css`
- `css/storefront-pages.css`
- `css/admin-mobile-navigation.css`
- `js/mobile-navigation.js`
- `js/admin-mobile-navigation.js`
- `routes/MobileNavigation.php`
- `routes/StorefrontPages.php`

Existing units touched narrowly:

- `views/partials/header.php` — move mobile-only Profile/Cart/Admin rendering to reusable partials and add mobile News/Reviews/Gift shortcuts; desktop behavior must remain intact.
- `app/Core/App.php` — load the new models/controllers/routes.
- `app/Models/AdminAccess.php` — permissions and current role-default grants.
- `views/admin/partials/header.php` — add `Мобільне меню` to the Content group when `.view` is allowed.
- translation dashboard/service files only as needed to expose missing/outdated mobile-menu translations through the existing workflow.

Avoid duplicating the same Profile/Cart/Admin badge logic in both header and bottom bar. The rendering data should be prepared once by the public-header bootstrap and consumed by the appropriate desktop/mobile partial.

## 18. Migration and rollout

Add a manual migration, with matching preflight and postflight SQL, for the two mobile-navigation tables.

Proposed files:

- `database/preflight/2026-09-17_mobile_navigation_preflight.sql`
- `database/migrations/2026-09-17_mobile_navigation.sql`
- `database/postflight/2026-09-17_mobile_navigation_postflight.sql`

The migration creates the two tables and seeds the four approved Ukrainian source items listed in section 7. It does not auto-create RU/EN translations; those intentionally appear as missing translations until supplied.

Rollout rules:

1. run preflight manually;
2. verify backup;
3. if state is partial, stop and inspect rather than rerunning blindly;
4. apply only the approved migration manually;
5. run postflight and verify tables, FK, indexes and four seed rows;
6. no ChatGPT/Codex process connects to or mutates the user's KSWEB MariaDB automatically.

Public rendering must fail soft if the menu tables are temporarily unavailable: log the database error and use an in-memory fallback containing the same four approved internal menu links. The admin editor must show a clear migration-required error instead of attempting to create tables at runtime.

## 19. Testing strategy

Follow TDD: new contracts are written RED before production code.

Planned tests:

- `tests/mobile_navigation_contract.test.mjs`
- `tests/mobile_navigation_runtime.test.mjs`
- `tests/storefront_pages_contract.test.mjs`
- `tests/storefront_product_collection_contract.test.mjs`
- `tests/admin_mobile_navigation_contract.test.mjs`

Coverage must include:

- 320, 360, 375, 390, 412 and 430 px width budgets;
- full unclipped logo;
- Favorites beside logo and adult-context strawberry substitution preserved;
- mobile News/Reviews/Gift icons present on normal public pages;
- those three icons absent on checkout/quick-order;
- bottom bar absent on checkout/quick-order;
- three-column guest/customer bottom bar and four-column admin bottom bar;
- Profile/Login, Cart and Admin badges preserved after relocation;
- safe-area and content bottom padding;
- no horizontal overflow;
- bottom-sheet open/close, backdrop, Escape, focus return, scroll lock and ARIA state;
- Android/browser Back closes an open sheet before navigation;
- internal/external URL validation and safe external attributes;
- admin GET/POST permissions;
- CSRF on every mutation;
- audit action calls;
- create/update/toggle/move/delete menu behavior;
- translation fallback, missing and outdated status behavior;
- 24-item pagination;
- all adult-category products excluded from Discounts and New Arrivals;
- Discounts inclusion via active badge OR `old_price > effective current price`;
- New Arrivals sorted newest first;
- empty states;
- PHP source/static delimiter and CSS-brace checks consistent with the current repository test style.

After the new module is manually checked on Android/KSWEB, update the old monolithic `category_manager_contract.test.mjs` once to remove/replace stale duplicated header assumptions, then run the complete Node test suite.

Desktop sidebar/category scrollbar manual review remains a separate final-review item and is not changed by this mobile-navigation feature.

## 20. Manual Android/KSWEB acceptance

At 320–430 px verify:

- header order is Logo → Favorites/strawberry → News → Reviews → Gift → Language;
- search is directly below;
- fixed bottom bar remains stationary while the page scrolls;
- page content is not hidden behind the bar;
- guest/customer has 3 equal bottom actions;
- active admin session has 4 equal bottom actions;
- Profile/Login, Cart, and Admin actions navigate correctly;
- existing counts/badges still update/display;
- `☰` opens the bottom sheet;
- sheet items match admin-configured order and current language;
- external item opens safely in a new tab;
- disabled item disappears without deletion;
- close/backdrop/Back behavior works;
- checkout and quick-order show neither bottom bar nor News/Reviews/Gift mobile shortcuts;
- login and registration still show the bottom bar;
- `/discounts` and `/new` never show 18+ products;
- new public pages work without horizontal overflow.

## 21. Non-goals for this phase

- No nested tree inside the hamburger menu.
- No Catalog item in the hamburger menu.
- No 18+ item is automatically inserted into the hamburger menu.
- No desktop redesign.
- No dedicated CMS for Delivery/Payment/Returns copy.
- No dedicated CMS for Contacts copy.
- No automatic database migration execution.
- No merge to `main` as part of implementation; draft PR #4 remains unmerged until separate explicit owner approval.

## 22. Acceptance summary

The design is complete when:

- the mobile header and bottom bar match the approved composition;
- the hamburger menu is fully admin-editable and multilingual;
- safe internal/external URLs are enforced;
- Discounts/New Arrivals use a shared non-adult product collection with 24-item pagination;
- Delivery/Payment/Returns and Contacts have stable public routes without invented business data;
- checkout/quick-order exclusions are respected;
- permissions, CSRF, audit, translation workflow and manual migration discipline match existing Anabelka conventions;
- new tests pass and the final full-suite run is green before any merge discussion.
