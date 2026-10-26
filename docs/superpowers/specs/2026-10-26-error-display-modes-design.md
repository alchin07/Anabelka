# ERR-01: режими відображення помилок

Implement the user's ERR-01 specification on `feature/error-display-modes`, based exactly on `feature/category-manager` at `69fe3bcacd64b2d74e7728d6c869d61ee48f0d37`. No merge, deployment, changes to other branches, real databases, photographs, orders, or user stash.

## Design

Keep ErrorHandler, PublicErrorPage, Router, SystemErrorLog and the existing `storage/logs/app-YYYY-MM-DD.log` / ERR-* references. Add a focused ErrorDisplayMode service; it reads `app_settings.error_display_mode` on demand and accepts only `development` or `production`, defaulting to `production` on every failure. Ordinary successful public requests do not read this key.

Only an active, PHP-managed administrative session whose administrator is freshly verified as active with database role `owner` can see diagnostic cards or save the setting. Neither session role text, cookies alone, client IDs, URL flags nor headers confer access. A new read-only AdminAccess verification path and AppSetting read path must not initialize schemas. Database/config/session failures deny diagnostics. No global display_errors changes.

Diagnostics are an allowlist: HTTP status; fixed Ukrainian event/reason text; a real exception class; project-relative file/line; valid ERR reference and protected journal link; allowlisted permission and verified role; HTTP method and a route with dynamic/untrusted segments hidden and query removed. Raw exception messages, SQL, trace, request variables and arbitrary context never enter the HTML. Fixed safe summaries intentionally trade verbosity for reliable secrecy; full details stay in the existing protected journal.

PublicErrorPage retains the branded view/navigation and protects HTML with escaping, no-store and noindex. ErrorHandler clears incomplete buffered output before responding and remains recursion-safe when storage, diagnostics, notifications, or rendering fail. API/AJAX responses keep JSON with their existing handler payload and status; HTML diagnostics never replace JSON.

Add owner-only GET/POST `/admin/system/error-display`, central and local CSRF checks, server-confirmed intent, validation, persistent AppSetting storage and existing administrator audit. Use current Anabelka confirmation and flash components. Register an owner-only service in System. Include two static examples, journal and safe preview/test links. Examples do not log or throw production errors. Existing deliberate error-test is extended with non-throwing previews; actual 403/404/500 behavior is exercised only by the isolated test harness.

## Approaches

A second logger or independent error middleware would duplicate existing behavior and is excluded by ERR-01. Globally enabling PHP debug output would expose unsafe messages and is excluded. The selected approach extends the existing rendering/logger boundaries with a small access-and-display service, retaining Router authorization.

## Verification and delivery

Test-first runtime tests for defaults, invalid setting, no schema creation, verified sessions/roles, secret exclusion, paths, correlation, output-buffer failures and JSON. Real MariaDB and isolated HTTP sessions exercise persistence, CSRF, roles and audit; Chromium checks 320/360/390/430 px plus desktop and confirmation/save behavior. Compare the complete existing Node suite against the exact base; list pre-existing failures. Run PHP syntax/runtime and JavaScript checks, review security independently, publish only the requested branch, supply SHA, changed files, Android/KSWEB installation and rollback instructions.

The available execution host is Linux with PHP 8.3 / MariaDB 10.11. Actual Android 16/KSWEB PHP 8.2/MariaDB 10.4 and Windows/XAMPP verification requires those devices and must be reported separately from Chromium viewport emulation. One existing photograph larger than GitHub's text API limit is sparse-excluded locally; its Git object reference is preserved, never changed.
