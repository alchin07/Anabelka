# Anabelka Notifications Foundation and Categories Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan.

**Goal:** Add the approved shared notification foundation and migrate all
category notification consumers without changing their business operations.

**Architecture:** A guarded shared PHP partial exposes one top-center DOM
container and JSON bootstrap. `AnabelkaNotify` owns browser rendering, queueing,
deduplication, timing, and versioned `sessionStorage` flash. `AnabelkaFlash`
owns one-shot PHP session flash in an isolated namespace. Both flash sources
enter the same browser queue.

**Tech Stack:** PHP 7+/sessions, framework-free browser JavaScript, CSS,
Node.js `node:test`, Git/GitHub stacked draft PR.

**Spec:** `docs/superpowers/specs/2026-09-13-anabelka-notifications-design.md`

## Global Constraints

- Base the branch exactly on `origin/feature/category-manager`.
- Do not modify or merge `main`; do not merge PR #4.
- Do not create or run MariaDB migrations.
- Do not deploy to the working KSWEB copy.
- Preserve backend operation semantics and business copy ownership.
- Commit foundation and category migration separately.
- Remove legacy code only after proving all its consumers migrated.

---

## Task 1: Establish the isolated stacked branch

**Files:**

- Modify: `.gitignore`
- Create: this plan and the approved design spec

1. Fetch `origin/feature/category-manager` and verify its SHA.
2. Create `feature/unified-notifications` from that remote ref.
3. Ignore `.worktrees/`, commit the design/plan, return the primary checkout to
   `feature/category-manager`, and create a linked worktree for the new branch.
4. Run the existing category contract test as the clean baseline.

## Task 2: Write the foundation contracts (RED)

**Files:**

- Create: `tests/anabelka_notifications_contract.test.mjs`

1. Build a minimal real DOM/session runtime around `vm.runInNewContext`.
2. Add behavior tests for immediate rendering, shared structure, type accents,
   ARIA, durations, close controls, FIFO queueing, duplicate suppression,
   persistent messages, and one-shot cross-page client flash.
3. Add repository integration tests for the PHP bridge, guarded partial,
   header includes, safe mobile CSS, and reduced-motion handling.
4. Run `node tests/anabelka_notifications_contract.test.mjs` and confirm the
   expected missing-foundation failure.

## Task 3: Implement the shared foundation (GREEN)

**Files:**

- Create: `css/anabelka-notifications.css`
- Create: `js/anabelka-notifications.js`
- Create: `app/Core/AnabelkaFlash.php`
- Create: `views/partials/anabelka-notifications.php`
- Modify: `app/Core/App.php`
- Modify: `views/partials/header.php`
- Modify: `views/admin/partials/header.php`

1. Implement the single-purple visual system with small type modifiers,
   viewport-safe width, wrapping, fixed top-center placement, and reduced
   motion support.
2. Implement `AnabelkaNotify`, its queue, normalized options, active/queued and
   stored duplicate suppression, centralized timings, one-shot storage
   consumption, accessible markup, and dismissal.
3. Implement `AnabelkaFlash` with the dedicated session namespace, validated
   types/options, duplicate suppression, and destructive one-shot consume.
4. Add the guarded partial, safe JSON bootstrap, and shared header includes.
5. Load the PHP bridge in the application bootstrap.
6. Run the new test to green, then run JavaScript syntax checks and the existing
   category contract test.
7. Commit as `feat: add unified notification foundation`.

## Task 4: Write category migration contracts (RED)

**Files:**

- Modify: `tests/category_manager_contract.test.mjs`
- Modify: `tests/anabelka_notifications_contract.test.mjs`

1. Replace obsolete category-local flash expectations with behavior and
   integration expectations for `AnabelkaNotify`.
2. Assert successful category mutations store flash before immediate reload or
   replace; failures and AI translation states use the shared immediate API.
3. Assert category and missing-translation views have no local message
   container or obsolete category flash assets.
4. Run both tests and confirm they fail specifically on the legacy consumers.

## Task 5: Migrate categories (GREEN)

**Files:**

- Modify: `js/admin-categories.js`
- Modify: `js/admin-category-ai-translation.js`
- Modify: `views/admin/categories/index.php`
- Modify: `views/admin/translations/missing.php`
- Delete: `js/admin-flash-message.js`
- Delete: `css/admin-flash-message.css`

1. Replace category-local immediate and stored message calls with
   `AnabelkaNotify.error/info/warning` and
   `AnabelkaNotify.flash('success', message)`.
2. Keep reload/replace immediate and preserve all fetch endpoints, payloads,
   confirmation flows, and inline field validation.
3. Remove the local containers and old asset references from both views.
4. Search the repository for remaining `admin-flash-message` consumers. Delete
   its JS/CSS only if the result is empty outside tests/history.
5. Run both contract suites and JavaScript syntax checks to green.
6. Commit as `refactor: migrate category notifications`.

## Task 6: Review, verify, and publish the milestone

**Files:** Review all changes from the base SHA to branch HEAD.

1. Request an independent code review against the approved spec and address all
   Critical and Important findings.
2. Run the complete Node contract suites, JavaScript syntax checks,
   `git diff --check`, legacy searches, and a no-database-change check.
3. Confirm the branch ancestry is `origin/feature/category-manager` and the
   primary base checkout remains unchanged.
4. Push `feature/unified-notifications`.
5. Open a draft PR with base `feature/category-manager`; explicitly state that
   it must not be merged or deployed before manual PR #4 verification.
6. Report created files, public API, DOM/CSS/PHP bridge examples, test evidence,
   the new branch SHA, and draft PR URL before starting the delivery migration.
