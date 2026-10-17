# UI notifications, stage 1 implementation plan

> **For agentic workers:** Execute the two authorized commits with test-first changes and fresh code review. Steps use checkbox syntax.

**Goal:** Extend the existing AnabelkaNotify core and migrate only category notifications.

**Architecture:** Keep the public API and single centered host. Use a FIFO queue with active/pending dedup by type and trimmed text; persist an array under the existing sessionStorage key and accept its legacy single-object format. AnabelkaFlash queues PHP session messages and NotificationBootstrap encodes them as a safe JSON array consumed by the same JS queue.

**Tech stack:** PHP 8.2-compatible code, plain JavaScript/CSS, Node test runner and Chromium/Playwright.

**Spec:** User task “Анабелька — UI-уведомления, этап 1: единое ядро и категории” (2026-10-12).

## Global constraints

- Only feature/category-manager; no main changes, merges or user stash operations.
- Do not overwrite unfinished work. Baseline remote/local HEAD: 0ba6f3454ec33703cd1d0f67bfa1411cd6ff997f; clean local copy.
- No MariaDB/schema changes, image algorithm changes, category business-rule or badge permission changes.
- Keep old notification mechanisms outside the completed category migration.
- Keep default durations: success 2800, error 4800, warning 4200, info 3500 ms and approved light palette/dark-theme hooks.
- Do not claim physical Android verification.

## Review focus

- A following success cannot overwrite an error; repeated errors must not create a queue flood.
- A missing/inaccessible storage or not-yet-created body must not break the page.
- Multiple flash entries, legacy payload and PHP/sessionStorage overlap must be consumed once without losing distinct messages.
- Flash encoding and display must never execute user markup; JSON/API output must not consume page flash.
- Category CRUD and translations must retain forms, server errors, focus, Back, sorting and permission behavior.

## Task 1: core, PHP bridge and assets

**Files:** js/anabelka-notify.js, css/anabelka-notify.css, app/Core/NotificationBootstrap.php, new app/Core/AnabelkaFlash.php; existing notification contract and new PHP/browser regressions.

**Interfaces:** Existing success/error/warning/info/show/store/consume/formatCount remain callable. PHP flash JSON element is #anabelka-flash containing [{type,message,options}]. AdminFlashMessage remains a wrapper.

- [ ] Write and run regressions exposing message replacement, persisted replacement, dedup, legacy flash, safe PHP serialization and version-independent resource detection.
- [ ] Extend existing core with ordered delivery; consume both stores through one queue and clear before display. Preserve one host and textContent rendering.
- [ ] Make long messages keyboard reachable, pause on focus/hover, dismiss a focused notification with Escape; honor reduced motion and dark palette.
- [ ] Run relevant Node/PHP/browser suites, syntax and git diff --check; compare baseline. Commit only stage-1 files.

## Task 2: categories

**Files:** js/admin-categories.js, js/admin-category-ai-translation.js, category view/shared category asset versions and new category browser fixture/test; contract expectations only where intentionally changed.

**Interfaces:** Category actions use AnabelkaNotify show/store without a second writer or timer; legacy compatibility wrapper remains available. Field validation and form payloads unchanged.

- [ ] Write and run failing regressions for category/AI notification overlap and type handling.
- [ ] Route category manager and category AI helper through shared API; keep other modules untouched. Bump cache keys for edited assets.
- [ ] Exercise actual category view/form handlers with mocked API responses (no DB): create/edit/delete/toggle adult/activity/move/reorder/translation, server/malformed/network errors, reload/return and native validation.
- [ ] Run targeted and full Node suites against identical baseline runtime, PHP lint, mobile Chromium and badge regressions. Review diff, create second commit.

## Delivery

- [ ] Fresh review of both changes; fix verified issues before publishing.
- [ ] Recheck remote feature HEAD, push without force only to feature/category-manager, verify SHA and main unchanged.
- [ ] Save a full report with changed files, commits, tests, prior failures, limitations, Android steps and remaining migration modules. Stop at this stage.
