# Home Right Rail, News, Reviews, and Gift Certificates Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add real news and moderated product-review modules, a truthful gift-certificate information surface, a desktop-only home right rail at 1250px+, and compact mobile/tablet links without changing the approved six-control mobile header.

**Architecture:** New content lives in focused models/controllers/views. `HomeController` receives bounded, already-localized news/review rows and renders a dedicated right-rail partial; no per-card database queries are allowed in views. Admin access reuses `AdminAccess` permissions and CSRF; customer review submission reuses `CustomerAccount` authentication/CSRF.

**Tech Stack:** PHP MVC, MariaDB/MySQL, HTML/CSS, vanilla JavaScript only where existing admin interactions require it, Node contract tests.

**Spec:** `docs/superpowers/specs/2026-09-16-home-right-rail-news-reviews-certificates-design.md`

## Global Constraints

- Work only in `feature/category-manager`; do not merge `main`.
- Right rail appears only on the home page and only from `min-width: 1250px`.
- Existing global left catalog sidebar remains unchanged on every public page.
- Approved mobile header row stays Logo → Favorites → Profile → Cart → Catalog → Language, followed by search; do not add a seventh top-row control.
- News source language is Ukrainian; localized news falls back to source text.
- User reviews are not machine-translated.
- General home and `/reviews` must exclude effectively adult products in the model/query layer.
- All new POST routes require CSRF; admin routes require existing admin permission checks; review POST requires an authenticated customer.
- Gift-certificate UI must not claim purchase, payment, QR activation, issue, balance, or redemption exists yet.
- Migration is created but not executed automatically against KSWEB/MariaDB.

---

### Task 1: Schema, bootstrap, permissions, and data models

**Files:**
- Create: `database/migrations/2026-09-16_home_content_modules.sql`
- Create: `app/Models/SiteNews.php`
- Create: `app/Models/SiteNewsTranslator.php`
- Create: `app/Models/ProductReview.php`
- Modify: `app/Core/App.php`
- Modify: `app/Models/AdminAccess.php`
- Test: `tests/home_content_models_contract.test.mjs`

**Interfaces:**
- `SiteNews::latestPublished(int $limit, string $languageCode): array`
- `SiteNews::publishedPage(string $languageCode): array`
- `SiteNews::findPublishedBySlug(string $slug, string $languageCode): ?array`
- `SiteNews::adminAll(): array`
- `SiteNews::createDraft(array $input): int`
- `SiteNews::update(int $id, array $input): void`
- `SiteNews::setPublished(int $id, bool $published): void`
- `SiteNews::delete(int $id): void`
- `SiteNewsTranslator::localize(array $news, string $languageCode): array`
- `SiteNewsTranslator::localizeList(array $items, string $languageCode): array`
- `SiteNewsTranslator::save(int $newsId, string $languageCode, array $input): void`
- `ProductReview::latestApprovedStandard(int $limit): array`
- `ProductReview::approvedForProduct(int $productId): array`
- `ProductReview::hasReview(int $productId, int $userId): bool`
- `ProductReview::submit(int $productId, int $userId, int $rating, string $body): int`
- `ProductReview::adminList(string $status = ''): array`
- `ProductReview::moderate(int $reviewId, string $status, int $adminId): void`
- `ProductReview::delete(int $reviewId): void`

- [ ] **Step 1: Write the failing model/schema contract**

Create `tests/home_content_models_contract.test.mjs` asserting that the migration contains `site_news`, `site_news_translations`, and `product_reviews`; `product_reviews` has `UNIQUE KEY ... (product_id, user_id)`; models expose the interfaces above; `ProductReview` filters general-review queries against effectively adult categories; `App.php` requires the three models; `AdminAccess` contains `news.view/manage`, `reviews.view/manage`, maps `/admin/news` and `/admin/reviews`, and grants them to `content_manager`.

- [ ] **Step 2: Run RED**

Run:
```bash
node tests/home_content_models_contract.test.mjs
```
Expected: FAIL because the migration/models/permissions do not exist.

- [ ] **Step 3: Add the migration**

Create the three tables with these invariants:

```sql
CREATE TABLE site_news (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(190) NOT NULL,
    title VARCHAR(255) NOT NULL,
    summary TEXT NULL,
    body MEDIUMTEXT NOT NULL,
    image_path VARCHAR(500) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    published_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_site_news_slug (slug),
    KEY idx_site_news_public (status, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE site_news_translations (
    news_id INT UNSIGNED NOT NULL,
    language_code VARCHAR(10) NOT NULL,
    title VARCHAR(255) NOT NULL,
    summary TEXT NULL,
    body MEDIUMTEXT NOT NULL,
    source VARCHAR(20) NOT NULL DEFAULT 'manual',
    status VARCHAR(20) NOT NULL DEFAULT 'approved',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (news_id, language_code),
    KEY idx_site_news_translations_language (language_code),
    CONSTRAINT fk_site_news_translations_news
        FOREIGN KEY (news_id) REFERENCES site_news(id) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_reviews (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    moderated_by_admin_id INT UNSIGNED NULL,
    moderated_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_product_reviews_product_user (product_id, user_id),
    KEY idx_product_reviews_status_created (status, created_at),
    KEY idx_product_reviews_product (product_id),
    CONSTRAINT fk_product_reviews_product
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT fk_product_reviews_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT fk_product_reviews_admin
        FOREIGN KEY (moderated_by_admin_id) REFERENCES admin_users(id) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 4: Implement focused models**

`SiteNews` must use parameterized queries, validate `draft|published`, normalize generated slug with a collision suffix, and public-read only `status='published' AND published_at IS NOT NULL AND published_at <= NOW()`.

`SiteNewsTranslator` must return source rows unchanged for Ukrainian and otherwise load approved/outdated translation with source fallback.

`ProductReview::submit()` must validate product/user IDs, rating 1–5, trim body, require body length 3–1500 characters, always insert `status='pending'`, and translate duplicate-key violations into a domain error. `latestApprovedStandard()` must join `products` and category ancestry data through the existing category helper IDs (use `Category::adultCategoryIds()` + `Category::visibleCategoryIds()` to build the allowed category ID set before the review query), so adult rows cannot leak through view-only hiding.

- [ ] **Step 5: Register models and admin permissions**

Add model `require_once` entries to `App.php`. Add:

```php
['news.view', 'Перегляд новин', 'content', 122],
['news.manage', 'Керування новинами', 'content', 123],
['reviews.view', 'Перегляд відгуків', 'content', 124],
['reviews.manage', 'Модерація відгуків', 'content', 125],
```

Map `/admin/news` and `/admin/reviews` in `permissionForRequest()`, and grant both view/manage permissions to `content_manager`; owner/store_owner/administrator inherit them from the all-permissions flow.

- [ ] **Step 6: Run GREEN and syntax checks**

```bash
node tests/home_content_models_contract.test.mjs
php -l app/Models/SiteNews.php
php -l app/Models/SiteNewsTranslator.php
php -l app/Models/ProductReview.php
php -l app/Models/AdminAccess.php
```
Expected: PASS / no syntax errors.

---

### Task 2: Public news pages and admin news management

**Files:**
- Create: `app/Controllers/NewsController.php`
- Create: `app/Controllers/AdminNewsController.php`
- Create: `views/news/index.php`
- Create: `views/news/show.php`
- Create: `views/admin/news/index.php`
- Create: `css/news.css`
- Create: `css/admin-news.css`
- Modify: `app/Core/App.php`
- Modify: `routes/Web.php`
- Modify: `views/admin/partials/header.php`
- Test: `tests/news_module_contract.test.mjs`

**Interfaces:**
- `NewsController::index()` renders `news/index` with published localized rows.
- `NewsController::show(string $slug)` renders only a published article or 404.
- `AdminNewsController::index/create/update/publish/delete()` follows existing admin JSON/redirect conventions and verifies `AdminAccess::csrfToken()` for writes.

- [ ] **Step 1: Write failing news module contract**

Assert public/admin routes, controller/view files, admin nav permission/link, public list/detail links, published-only model calls, CSRF verification on all write methods, escaped titles/summaries, and safe article formatting (`nl2br(htmlspecialchars(...))`, not raw stored HTML).

- [ ] **Step 2: Run RED**

```bash
node tests/news_module_contract.test.mjs
```
Expected: FAIL because routes/controllers/views do not exist.

- [ ] **Step 3: Implement public news**

Add:

```php
$router->get('/news', 'NewsController@index');
$router->get('/news/{slug}', 'NewsController@show');
```

Use the active language and `SiteNews` localized public queries. `views/news/index.php` shows date/title/summary/image when present; `show.php` renders body as escaped text with line breaks.

- [ ] **Step 4: Implement admin news**

Add GET `/admin/news` plus POST create/update/publish/delete routes. Every POST begins with `AdminAccess::verifyCsrf($_POST['_csrf'] ?? '')`; invalid CSRF returns/redirects with an error consistent with current admin forms. Editor includes Ukrainian source fields, active-language translation fields, image-path text input, publication datetime, and publish toggle. No upload subsystem is introduced in this task.

- [ ] **Step 5: Add admin navigation**

Define `$canNews = $adminCan('news.view')`; add a “Контент” group containing “Новини” and later “Відгуки”. News link uses `/Anabelka/admin/news`.

- [ ] **Step 6: Register controllers and run GREEN**

Add controller requires to `App.php`, then run:

```bash
node tests/news_module_contract.test.mjs
php -l app/Controllers/NewsController.php
php -l app/Controllers/AdminNewsController.php
php -l views/news/index.php
php -l views/news/show.php
php -l views/admin/news/index.php
```

---

### Task 3: Product reviews, moderation, product-page integration, and general review page

**Files:**
- Create: `app/Controllers/ProductReviewController.php`
- Create: `app/Controllers/AdminReviewController.php`
- Create: `views/reviews/index.php`
- Create: `views/admin/reviews/index.php`
- Create: `css/reviews.css`
- Create: `css/admin-reviews.css`
- Modify: `app/Core/App.php`
- Modify: `app/Controllers/ProductController.php`
- Modify: `views/product/show.php`
- Modify: `routes/Web.php`
- Modify: `views/admin/partials/header.php`
- Test: `tests/product_reviews_contract.test.mjs`

**Interfaces:**
- POST `/product/{slug}/reviews` → `ProductReviewController::store($slug)`.
- GET `/reviews` → `ProductReviewController::index()`.
- Admin moderation routes update `pending -> approved|rejected`; explicit delete is separate.

- [ ] **Step 1: Write failing review contract**

Assert login guard, customer CSRF verification, rating/body validation delegated to `ProductReview`, pending-only creation, one-review invariant, approved-only product display, general adult exclusion, admin moderation routes/permissions, and no raw review HTML.

- [ ] **Step 2: Run RED**

```bash
node tests/product_reviews_contract.test.mjs
```
Expected: FAIL because review UI/controllers/routes are missing.

- [ ] **Step 3: Implement customer review submission**

`store($slug)` resolves the product, requires `CustomerAccount::currentId() > 0`, verifies `CustomerAccount::verifyCsrf()`, calls `ProductReview::submit()`, stores a session flash message/error, and redirects back to `/Anabelka/product/{slug}#product-reviews`. Never accept `status`, `user_id`, or `product_id` from the customer form.

- [ ] **Step 4: Integrate product detail**

`ProductController::show()` passes:

```php
'reviews' => ProductReview::approvedForProduct($productId),
'canReview' => CustomerAccount::currentId() > 0
    && !ProductReview::hasReview($productId, CustomerAccount::currentId()),
'reviewCsrfToken' => CustomerAccount::csrfToken()
```

`views/product/show.php` renders a `#product-reviews` section, 1–5 stars as text/icons, escaped display name/body, and a form only for eligible signed-in users. Adult product reviews remain reachable only after the product controller’s existing adult gate.

- [ ] **Step 5: Implement `/reviews` and admin moderation**

General `/reviews` uses `ProductReview::latestApprovedStandard(100)` and therefore cannot contain adult products. Admin `/admin/reviews` supports status filter; approve/reject/delete POST routes verify admin CSRF and call model methods. Add `$canReviews` and “Відгуки” link to the admin Content group.

- [ ] **Step 6: Run GREEN and syntax checks**

```bash
node tests/product_reviews_contract.test.mjs
php -l app/Controllers/ProductReviewController.php
php -l app/Controllers/AdminReviewController.php
php -l app/Controllers/ProductController.php
php -l views/reviews/index.php
php -l views/admin/reviews/index.php
php -l views/product/show.php
```

---

### Task 4: Gift-certificate page, desktop right rail, and mobile/tablet utility links

**Files:**
- Create: `app/Controllers/GiftCertificateController.php`
- Create: `views/gift-certificates/index.php`
- Create: `views/home/partials/right-rail.php`
- Create: `css/gift-certificates.css`
- Create: `css/home-right-rail.css`
- Modify: `app/Core/App.php`
- Modify: `app/Controllers/HomeController.php`
- Modify: `views/home.php`
- Modify: `views/catalog/index.php`
- Modify: `routes/Web.php`
- Modify: `app/Models/HomeInterfaceTranslator.php`
- Test: `tests/home_right_rail_contract.test.mjs`

**Interfaces:**
- `GET /gift-certificates` renders information only.
- `HomeController` passes `homeNews` (max 3) and `homeReviews` (max 2).
- Right rail partial consumes prepared arrays and performs no database queries.

- [ ] **Step 1: Write failing layout/content contract**

Assert `/gift-certificates` route/page, `HomeController` bounded data calls, right-rail partial with three cards, no DB/model calls from the partial, CSS breakpoint exactly `min-width: 1250px`, sticky right rail, mobile rail hidden, and catalog utility links to `/news`, `/reviews`, `/gift-certificates`. Assert public header still has the approved six mobile controls and no new certificate/news/review top-row action.

- [ ] **Step 2: Run RED**

```bash
node tests/home_right_rail_contract.test.mjs
```
Expected: FAIL because the right rail and gift-certificate page do not exist.

- [ ] **Step 3: Implement gift-certificate information page**

Render localized copy that says the electronic certificate is being prepared / information is available. Use CTA wording equivalent to “Дізнатися більше”; do not render checkout forms, price selectors, QR codes, activation fields, or purchase claims.

- [ ] **Step 4: Implement bounded home data flow**

In `HomeController::index()`:

```php
try {
    $homeNews = SiteNews::latestPublished(3, $languageCode);
} catch (Throwable $e) {
    error_log('Home news: ' . $e->getMessage());
    $homeNews = [];
}

try {
    $homeReviews = ProductReview::latestApprovedStandard(2);
} catch (Throwable $e) {
    error_log('Home reviews: ' . $e->getMessage());
    $homeReviews = [];
}
```

Pass both arrays to `home` view. This is the fail-soft rollout behavior before the new migration is manually applied.

- [ ] **Step 5: Implement desktop right rail**

Wrap existing home primary sections in `.home-primary-content`, place `views/home/partials/right-rail.php` beside it, and load `home-right-rail.css`. At `<1250px`, `.home-right-rail { display:none; }`. At `>=1250px`, `.home-shell` becomes a two-column internal grid `minmax(0,1fr) 280px` (or equivalent within 260–300px) with 18–24px gap; rail uses `position: sticky` and a safe top offset. News/reviews render compact empty states when arrays are empty; certificate card always renders.

- [ ] **Step 6: Add mobile/tablet utility navigation**

At the top of `/catalog`, add a responsive links-only block for News, Reviews, Gift Certificates. Keep it available below 1250px and optionally unobtrusive on desktop; do not alter `views/partials/header.php` control count/order.

- [ ] **Step 7: Seed interface translation keys and run GREEN**

Add `home.news_title`, `home.all_news`, `home.reviews_title`, `home.all_reviews`, `home.gift_title`, `home.gift_text`, `home.gift_more`, plus catalog utility labels via the existing translator seeding pattern.

Run:
```bash
node tests/home_right_rail_contract.test.mjs
php -l app/Controllers/HomeController.php
php -l app/Controllers/GiftCertificateController.php
php -l views/home.php
php -l views/home/partials/right-rail.php
php -l views/catalog/index.php
php -l views/gift-certificates/index.php
```

---

### Task 5: Regression verification and PR documentation

**Files:**
- Modify if needed: tests only for discovered regressions
- Modify: draft PR #4 body with a concise new feature/verification section

- [ ] **Step 1: Run all new contracts**

```bash
node tests/home_content_models_contract.test.mjs
node tests/news_module_contract.test.mjs
node tests/product_reviews_contract.test.mjs
node tests/home_right_rail_contract.test.mjs
```

- [ ] **Step 2: Run affected existing contracts**

```bash
node tests/public_catalog_sidebar_contract.test.mjs
node tests/home_desktop_adult_brand_contract.test.mjs
node tests/public_header_admin_contract.test.mjs
node tests/category_manager_contract.test.mjs
node tests/product_variant_color_contract.test.mjs
node tests/product_variant_stock_mobile_contract.test.mjs
node tests/anabelka_notify_contract.test.mjs
node tests/public_error_notify_cleanup_contract.test.mjs
```

- [ ] **Step 3: Run syntax checks**

Run `php -l` over every new/modified PHP file in this feature and `node --check` over every new/modified `.mjs`/`.js` file. Run `git diff --check` when a local git workspace is available; otherwise verify the GitHub compare diff contains no unrelated files and disclose the environment limitation.

- [ ] **Step 4: Verify migration safety**

Confirm migration only creates `site_news`, `site_news_translations`, `product_reviews` and indexes/FKs; it must not `ALTER` existing product/category/order tables. Do not execute the migration against KSWEB.

- [ ] **Step 5: Update PR #4 documentation**

Append a section covering news/reviews/gift surface, 1250px desktop rail, mobile catalog links, TDD results, migration-not-run status, and required manual laptop/KSWEB review.

- [ ] **Step 6: Preserve branch**

Keep `feature/category-manager` and draft PR #4 unmerged for the user’s later visual/database review and remaining desktop corrections.
