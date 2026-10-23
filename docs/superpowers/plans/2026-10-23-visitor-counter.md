# VC-01 implementation and verification plan

Source: the user's VC-01 specification, 2026-10-23. Base: `69fe3bcacd64b2d74e7728d6c869d61ee48f0d37`, `feature/category-manager`. Target: `feature/visitor-counter` only.

## Design

Use explicit post-render controller hooks and a request-scoped counter. An allowlisted successful GET produces one atomic daily increment, keyed by a fixed page type and actual entity ID. No visitor identity or individual history exists. The writer reuses the store connection and skips active transactions, with statement-local time/lock budgets. All failures fail open. Collection is disabled in the versioned config; exact production hosts and deployment base path are required.

Alternative approaches considered: global request middleware risks counting API/errors without entity context; JavaScript beacons introduce extra requests and browser storage concerns. Explicit post-render hooks fit the requested PHP architecture. The detailed VC-01 specification supplies the intended behavior; routine implementation choices are recorded here without another approval round.

## Tasks

1. Verify remote HEAD, clean checkout and active PRs; record existing regression failures from an immutable baseline snapshot.
2. Write isolated failing counter tests; implement allowlist/filtering, site timezone, once-per-request behavior and fail-open SQL; add only successful public controller hooks.
3. Independently write and test the read-only reporting model and mobile admin view; register permission, route/menu and existing builder service without granting ordinary administrators default access.
4. Independently write the idempotent migration, guarded disposable MariaDB suite, concurrent workers and offline retention command. Run real atomic increment, contention, migration and retention tests.
5. Run PHP/JS syntax, new runtime/DB tests, browser layout and baseline-equivalent regression checks; review the complete diff independently and fix material findings.
6. Document installation in an isolated Android copy, constraints and exact test evidence. Commit/publish only the new branch; verify remote SHA/tree. No merge or deployment.

## Shared interfaces

- `VisitorPageViewCounter(array $config, callable $connection, ?callable $clock)` exposes `count(type,id,server,admin,status,headers,adult): bool` and the static `recordRendered()` facade. The connection factory returns PDO; clock returns DateTimeImmutable.
- Fixed mutually exclusive page types: `home`, `catalog`, `category`, `product`, `news_index`, `news`, `discounts`, `new`.
- Config: enabled (strict boolean), allowed_hosts (exact lowercase hostnames), base_path, timezone, retention_days.
- `site_page_view_daily`: stat_date, page_type, entity_id, view_count; primary key (stat_date,page_type,entity_id).
- `VisitorStats(PDO,DateTimeZone,clock)->report(period)` returns summary today/yesterday/week/month (last7/last30 calendar days), fixed30 chart, top_categories/top_products and has_data. Read errors propagate to a separate unavailable state.

## Review focus

Check inherited 18+ privacy after category reclassification; no automatic permission grants through the legacy seedRoles all-permissions list; calendar windows across month/DST boundaries; host/base-path isolation for a test copy on the same host; fail-open behavior during lock contention without changing shared DB session settings. Target-device manual checks remain explicitly separate from Linux evidence.
