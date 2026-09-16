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

The public header is the common entry point for the public site. It will own the global sidebar integration because every public view already requires `views/partials/header.php`, while the same file exits early to the separate admin header for admin requests.

The sidebar itself will be a dedicated reusable partial: `views/partials/public-catalog-sidebar.php`. Tree data will come from the existing `HomePage::navigationTree()` rules, but localization will be moved into a reusable model method so the home controller and global sidebar do not duplicate recursive localization logic.

The home page will stop rendering its own sidebar. There must be only one sidebar instance in the DOM.

## Desktop layout

A new generic stylesheet `css/public-catalog-sidebar.css` will turn public `body` into a two-column CSS grid only at `min-width: 1050px`:

- row 1: public header spans both columns;
- row 2, column 1: catalog sidebar;
- row 2, column 2: the page's direct `<main>` element.

The left column keeps the current 280px width and grows to 300px at 1250px. The sidebar is `position: sticky`, so it remains available while the visitor scrolls a long page. Its tree area may scroll vertically if the viewport is shorter than the menu.

Below 1050px the body returns to its existing normal layout and the sidebar is hidden, so current mobile pages and the approved mobile header are unaffected.

The desktop-only home department strip remains hidden because the persistent sidebar replaces it on desktop, while it stays available below the desktop breakpoint.

## Rendering and navigation

`views/partials/public-catalog-sidebar.php` renders the same recursive structure currently embedded in `views/home.php`:

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
- `app/Models/HomePage.php` — add reusable localized navigation-tree method.
- `app/Controllers/HomeController.php` — use the reusable localization method and stop owning private sidebar localization code.
- `views/partials/header.php` — load/render the global public sidebar only after the admin early-return path.
- `views/home.php` — remove the old embedded sidebar and its private renderer/assets.
- existing home desktop sidebar contract coverage if required by renamed generic assets.

Remove after migration:
- `css/home-desktop-sidebar.css`
- `js/home-desktop-sidebar.js`

## Verification

Add a Node contract test that fails before implementation and verifies:

1. the public header loads the generic sidebar CSS/JS and renders the sidebar partial;
2. admin requests return before public sidebar rendering;
3. `views/home.php` no longer contains a second sidebar or private recursive renderer;
4. sidebar links use the canonical category/adult routing helpers;
5. desktop CSS uses a two-column public layout at `1050px+`, with the header spanning both columns, sidebar in column 1, direct public `<main>` in column 2, and sticky sidebar behavior;
6. the sidebar is hidden below the desktop breakpoint;
7. the existing collapse storage key is preserved;
8. current category-manager, public-header, adult-brand, product-color, matrix/mobile, notification, and public-error contracts continue to pass.

Because the connected environment does not provide the live KSWEB/PHP runtime or a browser binary, final pixel-level desktop review on the laptop remains a later manual check. Static contracts must still protect layout structure and prevent mobile/admin regressions.