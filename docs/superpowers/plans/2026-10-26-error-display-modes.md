# ERR-01 Error Display Modes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Owner-only persistent error modes with useful, safe diagnostics and unchanged public privacy, authorization, HTTP statuses and logging.

**Architecture:** Extend existing error rendering and logging. A fail-closed ErrorDisplayMode service uses read-only AppSetting and verified AdminAccess paths; settings use ordinary protected Router/CSRF/audit mechanisms.

**Tech Stack:** PHP 8.2-compatible PHP, MariaDB 10.4-compatible SQL, existing JavaScript notification/confirmation components, Node runtime tests and Playwright Chromium.

**Spec:** `docs/superpowers/specs/2026-10-26-error-display-modes-design.md` and the user's ERR-01 request.

## Global Constraints

- Branch `feature/error-display-modes` only, base `69fe3bcacd64b2d74e7728d6c869d61ee48f0d37`.
- Existing `app_settings`, key `error_display_mode`, values `development`/`production`, default `production`; no new tables.
- Switch and diagnosis restricted to active server-verified role `owner`; `store_owner` excluded.
- No automatic schema creation/reinitialization in emergency handlers, no key reads on successful ordinary public requests.
- No raw PHP/SQL/trace, credentials or personal request data in diagnostic HTML; keep safe JSON and HTTP statuses.
- Palette #8A2BE2, #6519B9, #F4EAFF, #20202A, #EADCF7; 320–430 px layouts.
- Isolated test DB/session directories only. No deployment, merges, stash or protected branch changes.

## Review Focus

- Session revocation or role change between requests must remove access immediately.
- Arbitrary exception messages, malformed paths and URL-embedded secrets must never reach HTML.
- A failure while writing a log or rendering a diagnostic must produce one safe response without recursion or partial output.
- Missing schemas and DB/config failures must never trigger emergency DDL.
- Explicit JSON Content-Type, API routes and AJAX requests must remain JSON, including 403/404/500.

### Task 1: Read-only access and diagnostic policy

**Files:** create `app/Services/ErrorDisplayMode.php`, `tests/error_display_mode_runtime.php`; modify `app/Models/AppSetting.php`, `app/Models/AdminAccess.php`.

**Interfaces:** AppSetting::getExisting($key,$default=null), setExisting($key,$value); AdminAccess::currentForDiagnostics(): ?array; ErrorDisplayMode::current(): string, diagnostics($status,array $context=[]): array, save($mode,$confirmed): void.

- [x] Write runtime tests for default/unknown modes, missing tables, active/inactive/other-role sessions, forged role text and closed session, safe summaries and relative paths.
- [x] Run `php tests/error_display_mode_runtime.php`; observe missing feature failure.
- [x] Implement read-only policy and allowlisted diagnostic payload; use real PDO and sessions in tests.
- [x] Repeat runtime test; all policy cases pass; record outcome.

### Task 2: Existing handler/router integration and resilient output

**Files:** modify ErrorHandler.php, PublicErrorPage.php, Router.php, views/errors/public.php, css/public-error.css, .htaccess and notification schema access as needed; add runtime/HTTP coverage.

**Consumes:** Task 1 diagnostics; **Produces:** branded HTML with optional protected diagnostics and real journal correlation, preserved safe JSON.

- [x] Write failing tests for 403/404/500, existing JSON Content-Type/AJAX/API, partial buffers, missing view/log directory and no emergency DDL.
- [x] Run tests and observe missing behavior.
- [x] Pass throwable/reference metadata through current handler; add existing-logger HTTP events; preserve guards; fail closed on secondary failures; block direct logs HTTP access.
- [x] Add escaped diagnostic card/noindex and responsive styling; run all task tests and PHP syntax checks.

### Task 3: Persistent owner-only settings and examples

**Files:** create AdminErrorDisplayController.php, views/admin/system/error-display.php, css/admin-error-display.css; modify routes/AdminSecurity.php, AdminDashboardServiceRegistry.php, safe error-test controller/view, existing audit labels if necessary.

**Consumes:** Task 1 mode/save and Task 2 renderer; **Produces:** protected GET/POST settings, confirmation, flash, journal/test/preview links.

- [x] Write failing HTTP tests for roles, CSRF, rejected GET mutation, validation, confirmation, persistence across requests, old/new audited actor, static examples with no logged error.
- [x] Run isolated MariaDB/HTTP tests; confirm expected failures.
- [x] Implement server validation, persistence/audit and existing branded controls; static examples do not throw/log.
- [x] Run HTTP suite and Chromium tests at 320/360/390/430 and desktop, including confirm cancel/save and safe visitors.

### Task 4: Verification, review and delivery

**Files:** create `docs/ERR-01-install-android.md`, `docs/ERR-01-rollback.md`, `docs/ERR-01-verification.md`; reproducible test harness under tests.

- [x] Compare full existing Node suite with the base, run PHP runtime/lint and JS syntax checks, MariaDB HTTP tests and Chromium screenshots.
- [x] Review diff and use one fresh security reviewer; fix material findings with failing-then-passing regression tests.
- [x] Record exact environment, counts, existing failures and device limitations; document safe installation and rollback without changing the live store.
- [x] Prepare verified files and documentation for expected-head publication only to `feature/error-display-modes`; final remote verification and SHA are recorded in the delivery message.
