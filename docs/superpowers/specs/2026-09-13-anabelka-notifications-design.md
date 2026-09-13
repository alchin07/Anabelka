# Anabelka Unified Notifications Design

## Status and scope

This design is approved for a stacked feature branch based on
`feature/category-manager`. The first delivery milestone contains the shared
foundation and the category migration only. It must not merge or modify
`main`, merge PR #4, deploy to the working KSWEB copy, or alter the MariaDB
schema.

The later migration order is:

1. Categories
2. Delivery
3. Translations
4. Products
5. Orders
6. Users and ranks
7. Languages
8. Social authentication
9. AI and system settings
10. Remaining admin and public flows
11. Confirmed legacy cleanup

Each migrated area is a separate logical commit. A legacy implementation is
removed only after every known consumer has moved to the shared API.

## Boundaries

The component owns transient user and administrator notifications:

- notification type and presentation;
- display timing and persistent notifications;
- queueing and duplicate suppression;
- client-side flash persistence across reload or navigation;
- server-side session flash across POST/Redirect/GET;
- accessible announcements and dismissal.

It does not own business copy. Callers pass the complete localized message.
It also does not replace field validation, page-level errors, dedicated order
result pages, invitation credentials, notification centers, badges, or
counters.

Backend save/delete/toggle/AI behavior remains unchanged. Existing query
messages such as `?message=`, `?error=`, and `?saved=` will be migrated to PRG
session flash in their owning section so refresh cannot repeat the message.

## Shared files

- `css/anabelka-notifications.css` — one visual system for every type.
- `js/anabelka-notifications.js` — queue, API, timing, client flash, and ARIA.
- `app/Core/AnabelkaFlash.php` — server-side session flash bridge.
- `views/partials/anabelka-notifications.php` — guarded shared markup and
  assets, included by both public and admin header paths.
- `tests/anabelka_notifications_contract.test.mjs` — runtime and integration
  contract checks.

`app/Core/App.php` loads the PHP bridge. Both header implementations include
the shared partial; a request-level guard in the partial guarantees that its
container and assets render once even if a page traverses more than one layout
or header path.

## JavaScript API

The browser exposes a single global object:

```js
AnabelkaNotify.success(message, options)
AnabelkaNotify.info(message, options)
AnabelkaNotify.warning(message, options)
AnabelkaNotify.error(message, options)
AnabelkaNotify.show(type, message, options)
AnabelkaNotify.flash(type, message, options)
AnabelkaNotify.dismiss()
```

`show` and the type helpers enqueue an immediate notification. `flash` writes
the normalized notification to `sessionStorage` for the next page and does
not delay the caller's reload, replace, or navigation. Supported options are
`persistent` and an optional positive `duration` override. The standard
durations are centralized:

| Type | Duration |
| --- | ---: |
| success | 2800 ms |
| info | 3000 ms |
| warning | 4000 ms |
| error | 5000 ms |

Persistent notifications have no automatic timer. Warning, error, and all
persistent notifications have a close button with a touch target of at least
44 by 44 CSS pixels and an accessible label.

Only one notification is visible. Later notifications wait in a FIFO queue.
The same normalized type and message cannot be added twice while it is active,
queued, or already stored for the next page. Its signature is released after
dismissal so a later independent event can display the same text.

Client flash uses the versioned key
`anabelka.notifications.flash.v1`. The stored payload is removed before its
items are enqueued, making consumption one-shot even if rendering fails.

## PHP session bridge

The server API is:

```php
AnabelkaFlash::success($message, $options = []);
AnabelkaFlash::info($message, $options = []);
AnabelkaFlash::warning($message, $options = []);
AnabelkaFlash::error($message, $options = []);
AnabelkaFlash::push($type, $message, $options = []);
AnabelkaFlash::consume();
```

It stores only normalized notification data in the independent
`$_SESSION['anabelka_flash']` namespace. `consume()` removes the namespace
before returning its validated, de-duplicated items. This lets old session
flash systems coexist during phased migration.

The shared partial serializes consumed PHP messages into a non-executable JSON
bootstrap element. The JavaScript module feeds server and client flash through
the same queue and duplicate policy.

## DOM and accessibility

The partial renders one inert top-level container and one JSON bootstrap
element. Each visible message uses the same element structure and purple base;
type differences are modifier classes and small icon/accent colors, not
independent layouts.

Success and info use `role="status"` with polite live announcement. Warning is
also polite and dismissible. Error uses `role="alert"` with assertive live
announcement. Announcements are atomic. Showing a notification never moves
focus, so it does not summon a mobile keyboard. Consumed flash data is removed
before announcement to prevent repeated screen-reader output after reload.

## Visual system

The notification stack is fixed above the interface, horizontally centered at
the top of the viewport, and does not participate in page layout. The base is
Anabelka purple with readable white text, rounded corners, compact padding,
and a light shadow. Success, info, warning, and error differ only through a
small accent/icon.

The notification uses border-box sizing, a content-driven desktop width, and
a hard viewport-safe maximum of `calc(100vw - 24px)` with a compact maximum
width. Text may wrap and break long unspaced tokens. The required mobile review
widths are 320, 360, 375, 390, 412, and 430 pixels. Motion transitions are
disabled under `prefers-reduced-motion: reduce`.

## Category migration

Category create/update/delete/toggle failures call `AnabelkaNotify.error`.
Successful mutations store `AnabelkaNotify.flash('success', message)` and
immediately reload or replace without an artificial delay. AI translation
status and errors use the same public API.

The category index and missing-translation view stop rendering their local
`#site-message` elements and stop loading `admin-flash-message.*`. Once a
repository search confirms there are no remaining consumers, those two legacy
files are deleted. Category field validation remains inline.

## Verification

Automated checks exercise real module behavior in a small DOM/session runtime:

- immediate success and error use the same container and structure;
- types vary through accents/modifiers;
- centralized default durations are observed;
- persistent and dismissible behavior;
- FIFO queueing and active/queued duplicate suppression;
- client flash survives page recreation, is removed before display, and shows
  once;
- malformed storage is safely consumed;
- PHP bridge namespace, validation, deduplication, and one-shot consumption;
- partial inclusion guard and both header integration paths;
- mobile-safe CSS constraints and reduced motion;
- migrated category pages have no local `.site-message` or obsolete assets.

PHP syntax checks and real viewport/browser checks must be run in an
environment where PHP and a browser engine are available. Node contract tests
remain the portable repository gate.
