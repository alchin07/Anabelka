# Android preview fixes implementation plan

> **For agentic workers:** use systematic debugging and test-first implementation in the two independent PHP and JavaScript file groups. Root integrates and reviews the complete diff before one final commit.

**Goal:** preview works without stream exclusive locks in KSWEB and preserves the user's profile/mask selection.

**Architecture:** serialize a manifest completely into an exclusive, uniquely named private temporary file, then rename it in place; retain the existing operation locks. Keep draft UI choices separate from accepted processing diagnostics, and reset the draft only after successful confirmation.

**Tech Stack:** existing PHP 8.2+, plain JavaScript, native PHP filesystem tests and Node/jsdom.

**Spec:** user's task “Анабелька — исправление preview на Android KSWEB”, baseline `c835f8f473d0d03d820385c5a909443603d89310`.

## Global constraints

- Work only on `feature/category-manager`; never merge, change main or touch user stash.
- Preserve preview/confirm operation locks, owner/session/token/CSRF/permission checks, transactional publication and previous ready results.
- Keep GrabCut/MODNet algorithms, dimensions, geometry, profiles and database schema unchanged.
- Publish one commit only after verification; real KSWEB acceptance remains required when unavailable here.

## Review focus

- Short writes and failed flush/close/rename preserve the previous complete manifest and remove the owned temporary file.
- Concurrent confirms serialize on real filesystem locks; stale tokens cannot overwrite a winner.
- Busy/error/cancel/close/reopen/Android Back preserve Studio Light/MODNet draft choices.
- Confirm discards the draft and displays the returned applied diagnostics.
- Original+Canvas disables removal choices; existing comparison/details/slider/history remain intact.

## Tasks

- [x] PHP: reproduce unsupported stream locks with a manifest-only filesystem wrapper; test partial writes/failures, then replace only `writeManifest()` with checked complete writes and atomic rename.
- [x] JavaScript: reproduce selection loss in DOM tests; retain per-image draft settings through refresh/rebuild and clear them on successful confirm. Bump the existing JS asset version.
- [x] Integration: run actual overlapping PHP confirms plus existing preview rollback/security/runtime tests; run PHP 8.2-compatible tests/lint, JS syntax and `git diff --check`.
- [x] Compare the full Node suite against exact baseline with identical external dependencies; report every unchanged failing case.
- [x] Review the complete diff, document Android commands/acceptance limits, publish one commit to the existing feature branch only.
