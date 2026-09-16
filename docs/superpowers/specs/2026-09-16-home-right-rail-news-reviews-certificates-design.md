# Home Right Rail: News, Reviews, and Gift Certificates Design

## Goal

Add a useful content rail to the right side of the Anabelka home page on wide desktop, backed by real news and product-review modules, while keeping the approved mobile home compact and exposing the same content through separate mobile-friendly pages.

## Approved product decision

- Desktop: use the approved classic right-side layout.
- Mobile: do not copy the right rail into the home-page content stream.
- News and product reviews are real working modules now.
- Gift certificates get a real public information page and a promotional home card now, but purchasing, payment, QR activation, transfer, balance, single-use redemption, Checkbox integration, and fiscal logic remain a later dedicated module.
- The existing approved mobile header top row must not gain another icon or wrap into another row.

## Existing context and constraints

The public desktop already has a global left catalog sidebar rendered through `views/partials/header.php`. The home page is the only page that receives the new right rail. Other public pages keep the current desktop structure.

The site is multilingual. Ukrainian remains the source language. News content supports localized text with fallback to the Ukrainian source. User-written product reviews are user-generated content and are displayed as written; this step does not machine-translate them.

All mutating admin and customer actions use the existing CSRF pattern. No existing category, adult-gate, checkout, cart, product-stock-matrix, notification, or mobile-header behavior may be weakened.

## Desktop home layout

The right rail is implemented inside the home page's own layout rather than by changing the global public body grid. This keeps the global left catalog sidebar reusable on every public page while allowing only the home page to split its main area into two internal columns.

At wide desktop width (`min-width: 1250px`):

- global column 1 remains the existing left catalog sidebar;
- global column 2 remains the public `<main>` area;
- inside the home `<main>`, `.home-shell` becomes a grid with:
  - primary home content: `minmax(0, 1fr)`;
  - right rail: approximately 260–300 px;
  - a compact 18–24 px gap.

The 1250 px threshold is deliberate: enabling the rail at the existing 1050 px left-sidebar breakpoint would squeeze the current home hero and product/category grids too aggressively. A normal 1366/1440 laptop/desktop gets the approved three-column composition.

The right rail is `position: sticky` with a safe top offset and its own bounded vertical overflow when needed. It must never overlap the public header or left catalog sidebar.

Below `1250px`, the right rail is hidden and the home content remains one internal column. No news/review/certificate cards are inserted into the mobile home feed.

## Right-rail composition

The desktop home right rail contains three stacked cards in this order.

### 1. News

- Heading: localized equivalent of “News”.
- Show the latest 2–3 published news items.
- Each compact item shows publication date, localized title, optional thumbnail, and links to the full article.
- A “All news” link opens `/news`.
- Draft, unpublished, or future-dated news never appears publicly.

### 2. Latest customer reviews

- Heading: localized equivalent of “Latest reviews”.
- Show the latest 2 approved reviews for standard (non-adult) products.
- Each item shows customer display name, product name, 1–5 star rating, a short escaped text excerpt, and a link to the product.
- Adult-product reviews are excluded from the home rail and the general `/reviews` page so the general public home never leaks adult-category content.
- A “All reviews” link opens `/reviews`.

### 3. Gift certificate

- Branded Anabelka card using the approved visual direction: silk/lace/gift treatment may later use the planned 3D certificate artwork.
- The card has a localized title, short explanation, and a truthful CTA such as “Learn more”.
- The CTA opens `/gift-certificates`.
- The UI must not claim that a certificate can already be purchased or activated while the commerce/QR module is not implemented.

## News module

### Public routes

- `GET /news` — published news list.
- `GET /news/{slug}` — published article detail.

### Admin routes

- `GET /admin/news` — management list/editor.
- `POST /admin/news/create` — create draft.
- `POST /admin/news/update` — edit source content, translations, image, and publication date.
- `POST /admin/news/publish` — publish/unpublish.
- `POST /admin/news/delete` — delete only through an explicit confirmed admin action.

### Data model

`site_news`

- `id` INT UNSIGNED primary key
- `slug` VARCHAR(190) unique, generated from source title and kept stable after publication unless explicitly changed by system logic
- `title` VARCHAR(255) source-language title
- `summary` TEXT nullable source-language summary
- `body` MEDIUMTEXT source-language body
- `image_path` VARCHAR(500) nullable
- `status` VARCHAR(20), constrained in application code to `draft` or `published`
- `published_at` DATETIME nullable
- `created_at`, `updated_at`

`site_news_translations`

- `news_id` INT UNSIGNED
- `language_code` VARCHAR(10)
- `title` VARCHAR(255)
- `summary` TEXT nullable
- `body` MEDIUMTEXT
- `source` VARCHAR(20) using the existing translation-workflow vocabulary
- `status` VARCHAR(20) using the existing translation-workflow vocabulary
- timestamps
- primary key `(news_id, language_code)`
- foreign key to `site_news(id)` with delete cascade

### News behavior

- Ukrainian source content is required.
- Other active-language translations are optional and fall back to Ukrainian source content.
- Public queries select only `status='published'` and `published_at <= NOW()`.
- News admin is added to the existing admin navigation.
- Admin access receives a dedicated news-management permission through the existing `AdminAccess` permission system rather than relying only on page hiding.
- AI translation integration with the global missing-translation workflow is deliberately not expanded in this step; the schema and translation metadata make that later integration straightforward.

## Product-review module

### Public/customer routes

- `POST /product/{slug}/reviews` — authenticated customer submits one review for the product.
- `GET /reviews` — latest approved reviews for standard products.

The existing `GET /product/{slug}` page also renders approved reviews for that product and, for authenticated customers, the review form when the customer has not already submitted a review.

### Admin routes

- `GET /admin/reviews` — moderation list with pending/approved/rejected filters.
- `POST /admin/reviews/approve` — approve.
- `POST /admin/reviews/reject` — reject.
- `POST /admin/reviews/delete` — delete through explicit confirmed admin action.

### Data model

`product_reviews`

- `id` INT UNSIGNED primary key
- `product_id` INT UNSIGNED, foreign key to `products(id)` with delete cascade
- `user_id` INT UNSIGNED, foreign key to `users(id)` with delete cascade
- `rating` TINYINT UNSIGNED, application-validated from 1 through 5
- `body` TEXT
- `status` VARCHAR(20), application-constrained to `pending`, `approved`, `rejected`
- `moderated_by_admin_id` INT UNSIGNED nullable, foreign key to `admin_users(id)` with `ON DELETE SET NULL`
- `moderated_at` DATETIME nullable
- `created_at`, `updated_at`
- unique key `(product_id, user_id)` so one customer cannot spam multiple reviews for the same product
- indexes on `(status, created_at)` and `product_id`

### Review behavior

- Only signed-in customers can submit.
- Rating and non-empty review text are required.
- Input length is bounded in controller/model validation and escaped on output.
- New reviews always start as `pending` and are invisible publicly until approved.
- This step does not add public review editing. A later version may allow editing with automatic return to `pending`.
- Public product pages show only approved reviews.
- General home/right-rail and `/reviews` queries exclude products under effectively adult categories.
- Adult product pages may show their own approved reviews only within the existing adult-access flow.
- Admin moderation is added to the existing admin navigation and receives a dedicated review-moderation permission through `AdminAccess`.

## Gift-certificate information surface

### Public route

- `GET /gift-certificates`

### Current behavior

The page explains the planned Anabelka electronic gift certificate concept and can show the approved visual direction, benefits, and “coming soon”/information state. It must not create orders, accept payment, issue codes, generate QR tokens, or mark certificates as active.

No certificate database table is created in this step. This prevents the temporary marketing surface from constraining the later secure certificate-commerce design.

## Mobile and compact-screen navigation

The approved mobile header variant A remains unchanged: Logo → Favorites → Profile → Cart → Catalog → Language in one row, followed by search.

No seventh top-row control is added.

Below `1250px`, the desktop right rail is absent. At the top of `/catalog`, a compact links-only “Useful / More” navigation group exposes:

- News → `/news`
- Customer reviews → `/reviews`
- Gift certificates → `/gift-certificates`

This group is navigation only, not a copy of the desktop news/review/certificate cards. It is designed to fit the mobile/tablet catalog page without changing the approved header row. The three destination pages are independently responsive and directly addressable.

## Home controller/data flow

`HomeController` fetches, in bounded queries:

- latest 3 published localized news items;
- latest 2 approved standard-product reviews with customer display name and product metadata;
- existing directions, category tree, and latest products.

The right rail receives already-prepared view data. The view must not perform per-item database queries.

When the new database tables are not yet present during rollout, the public home must fail soft: log/report through the existing error handling where appropriate and render empty news/review states rather than taking down the home page. The certificate card remains available because it does not depend on the new tables.

## Database rollout

Create one migration for the news/review tables and indexes. The migration is not executed automatically by ChatGPT/Codex against the user’s KSWEB/MariaDB database.

Before applying it on the working database:

1. take/verify the project database backup;
2. inspect the migration;
3. apply it manually in the maintenance workflow;
4. verify tables, keys, and sample read queries.

No existing category/product/order table columns are altered by this feature; only the new tables and their foreign keys/indexes are added. The review moderation foreign key targets the existing `admin_users` table created by `AdminAccess`.

## Security and moderation

- CSRF validation on every POST route.
- Admin authorization and explicit permissions on all `/admin/news*` and `/admin/reviews*` routes.
- Customer authentication for review submission.
- Server-side rating/body validation.
- Escape user-generated review text and customer display names.
- No raw HTML is accepted in reviews.
- News body rendering uses a deliberately safe formatting strategy; arbitrary stored HTML is not trusted by default.
- Adult-category filtering is enforced in the query/model layer, not only hidden by CSS.

## Likely files

Create:

- `app/Models/SiteNews.php`
- `app/Models/SiteNewsTranslator.php`
- `app/Models/ProductReview.php`
- `app/Controllers/NewsController.php`
- `app/Controllers/ProductReviewController.php`
- `app/Controllers/AdminNewsController.php`
- `app/Controllers/AdminReviewController.php`
- `views/news/index.php`
- `views/news/show.php`
- `views/reviews/index.php`
- `views/gift-certificates/index.php`
- `views/admin/news/index.php`
- `views/admin/reviews/index.php`
- `views/home/partials/right-rail.php`
- focused CSS for home right rail, news/review pages, and admin management
- `database/migrations/2026-09-16_home_content_modules.sql`
- contract/runtime tests for news visibility, review moderation/adult filtering, desktop rail, and compact-screen navigation.

Modify:

- `app/Core/App.php` for model/controller availability following existing bootstrap conventions
- `app/Models/AdminAccess.php` for news/review permission definitions
- `app/Controllers/HomeController.php`
- `app/Controllers/ProductController.php`
- `views/home.php`
- `views/product/show.php`
- `views/catalog/index.php` for the compact utility navigation group
- `views/admin/partials/header.php` for News/Reviews admin navigation
- `routes/Web.php`
- translation seed/model files needed for the new interface labels.

## Testing strategy

Use TDD for each behavior group.

Required automated coverage includes:

1. published news visibility and draft/future exclusion;
2. language fallback for news;
3. review submission requires login, CSRF, rating 1–5, and non-empty bounded text;
4. new review starts pending;
5. pending/rejected reviews never render publicly;
6. approved reviews render on their product;
7. one-review-per-user-per-product invariant;
8. home and `/reviews` exclude effectively adult products;
9. admin moderation changes only allowed states and requires the correct admin permission;
10. home right rail is present and sticky only from 1250 px upward;
11. narrower/mobile home does not contain duplicated news/review/certificate content blocks;
12. `/catalog` exposes the three compact-screen destination links without altering the approved six-control header row;
13. gift-certificate page makes no purchase/activation claims or order writes;
14. home view performs no per-item news/review queries;
15. existing category-manager, public-header, catalog sidebar, product matrix, cart/checkout, notifications, and adult-gate contracts continue to pass.

## Manual review after implementation

When laptop access is available:

- desktop 1366/1440 widths: verify left catalog, main content, and right rail proportions;
- 1050–1249 widths: verify the home remains readable without the right rail and `/catalog` exposes the utility links;
- confirm both desktop side rails remain usable while scrolling without overlapping the header;
- verify news/review cards do not squeeze the main product/category grids;
- mobile 320/360/390/430 widths: verify the approved header row is unchanged and no right-rail content is inserted into the home feed;
- open the catalog utility group and each `/news`, `/reviews`, `/gift-certificates` page;
- submit a test product review, confirm it is pending, approve it in admin, and confirm it appears on the product and eligible public surfaces;
- verify an adult-category review never appears on the general home or `/reviews` page.
