# Global Public Catalog Sidebar Design

## Goal

Make the existing desktop catalog tree from the home page available on every public Anabelka page so a visitor can switch sections, categories, and products without returning to the home page or catalog root.

## Scope

- Public pages only.
- Admin pages remain unchanged.
- Desktop layout only: sidebar is visible from `1050px` and wider, matching the existing breakpoint.
- Mobile/tablet behavior below `1050px` remains unchanged.
- The sidebar keeps the current recursive category tree, collapse/expand behavior, sessionStorage persistence, adult-root treatment, translated names, `Category::catalogUrl()` links, and `AdultAccess::gateUrl()` for adult roots.
- No database, route, product, checkout, cart, authentication, or adult-access logic changes.

## Architecture

The public header is the common entry point for the public site. It owns the global sidebar integration because every public view already requires `views/partials/header.php`, while the same file exits early to the separate admin header for admin requests.

The sidebar itself is a dedicated reusable partial: `views/partials/public-catalog-sidebar.php`. Tree data comes from the existing `HomePage::navigationTree()` rules. `HomePage::localizedNavigationTree()` delegates tree localization to `CategoryTranslator::localizeTree()`, which gathers category IDs and loads translations for the active language in one batch query before applying them in memory. This avoids an N+1 translation query on every public page.

The home controller and global sidebar therefore share one localized-tree path. On the home page the already prepared `$navigationTree` is reused by the sidebar partial so the same tree is not built twice in one request.

The home page no longer renders its own sidebar. There must be only one sidebar instance in the DOM.

## Desktop layout

The generic stylesheet `css/public-catalog-sidebar.css` turns public `body` into a two-column CSS grid only at `min-width: 1050px`:

- row 1: public header spans both columns;
- row 2, column 1: catalog sidebar;
- row 2, column 2: the page's direct `<main>` element.

The left column keeps the current 280px width and grows to 300px at 1250px. The sidebar is `position: sticky`, so it remains available while the visitor scrolls a long page. Its tree area may scroll vertically if the viewport is shorter than the menu.

Below 1050px the body returns to its existing normal layout and the sidebar is hidden, so current mobile pages and the approved mobile header are unaffected.

The desktop-only home department strip remains hidden because the persistent sidebar replaces it on desktop, while it stays available below the desktop breakpoint.

## Rendering and navigation

`views/partials/public-catalog-sidebar.php` renders the same recursive structure previously embedded in `views/home.php`:

- all levels begin on the same left tree axis;
- categories with children have collapse controls;
- normal nodes use `Category::catalogUrl()`;
- true adult root nodes use `AdultAccess::gateUrl()` and the existing strawberry presentation;
- translated names use the active public language;
- adult roots remain ordered after standard roots according to the existing navigation-tree logic.

The collapse state continues to use `sessionStorage` with the existing `anabelka-public-sidebar-collapsed` key so state survives navigation between public pages during the same browser session.

## Files

Create:
- `views/partials/public-catalog-sidebar.php`
- `css/public-catalog-sidebar.css`
- `js/public-catalog-sidebar.js`
- `tests/public_catalog_sidebar_contract.test.mjs`

Modify:
- `app/Models/HomePage.php` — add reusable localized navigation-tree method using the batched `CategoryTranslator::localizeTree()` path.
- `app/Controllers/HomeController.php` — use the reusable localization method and stop owning private sidebar localization code.
- `views/partials/header.php` — load/render the global public sidebar only after the admin early-return path.
- `views/home.php` — remove the old embedded sidebar and its private renderer/assets.
- existing home desktop sidebar contract coverage for the renamed generic component.

Remove after migration:
- `css/home-desktop-sidebar.css`
- `js/home-desktop-sidebar.js`

## Verification

The Node contract test verifies:

1. the public header loads the generic sidebar CSS/JS and renders the sidebar partial;
2. admin requests return before public sidebar rendering;
3. `views/home.php` no longer contains a second sidebar or private recursive renderer;
4. sidebar links use the canonical category/adult routing helpers;
5. localized sidebar trees use `CategoryTranslator::localizeTree()` and do not call `CategoryTranslator::localize()` per node;
6. desktop CSS uses a two-column public layout at `1050px+`, with the header spanning both columns, sidebar in column 1, direct public `<main>` in column 2, and sticky sidebar behavior;
7. the sidebar is hidden below the desktop breakpoint;
8. the existing collapse storage key is preserved;
9. existing public-header and adult-brand contracts continue to protect prior behavior.

The connected container cannot clone GitHub because outbound DNS to `github.com` is unavailable, so a repository-wide test-suite run is not possible from this session. Targeted Node syntax/contract checks and PHP lint are run against the changed component sources, while final pixel-level desktop review remains a later laptop check.