# CH-UI-05 message read status implementation plan

**Goal:** show private outgoing delivery/view status beside time, with current group recipient counts, without equating history fetch with viewing.
**Architecture:** reuse the monotonic member watermark; batch current recipient markers and expose aggregates only for the authenticated author. The browser observes visible incoming content and advances only across a contiguous observed prefix. Initial history includes the first unread message; append bounded pages and merge receipt refreshes without replacing message DOM for receipt-only changes.
**Tech stack:** PHP 8.2, MariaDB 10.4, native JavaScript/CSS, Playwright.
**Spec:** user-provided CH-UI-05; baseline f43a0b0caf57deb072e5e46fadff7d27ba21f424.

## Constraints

Publish only feature/admin-chat with an expected-head lease. No merge, stash operations, live database writes, migrations, emoji or CH-UI-04 redesign, photo viewer/Back changes.

## Review focus

- Offscreen unread gaps, large backlogs and scroll jumps must not advance past unobserved messages.
- Background tabs, inactive conversations and modal overlays must not acknowledge views.
- Failed/stale read requests must retry without reducing the cursor or updating another thread.
- Membership changes must immediately affect current counts, including zero recipients.
- Status-only polling must preserve editing, photo nodes and scroll position.

## Tasks

- [x] Backend: add outgoing read_status {read_count,recipient_count,seen}; history own cursor, starts_at_unread and has_more; receipt_from yields batched read_statuses. Reject read ids outside conversation. Add MariaDB lifecycle, privacy, pagination and monotonicity regressions before production edits.
- [x] Frontend: add accessible one/two check renderer, partial group text, brand CSS; observe visible incoming content; acknowledge contiguous prefix only after active visible focused checks, retry failed POST. Merge history and batch receipts, append pages at bottom, preserve DOM for status-only updates. Cache-bust assets.
- [x] Verification: red/green renderer tests and browser regressions; PHP syntax and isolated PHP/MariaDB runtime, existing chat tests, full baseline comparison. Review final diff independently.
- [ ] Publish: verify unchanged protected refs, publish one linear commit only to feature/admin-chat, inspect exact-commit CI results; provide SHA/files/results and physical Android checklist.

## Local verification

PHP8.3.6 syntax passed; temporary MariaDB10.11.14 runtime83/83; JavaScript chat33/33; Chromium read suite12/12. Full Node suite606passed/90failed versus pristine baseline599passed/90failed; exact failure-name multisets unchanged. MariaDB10.4/PHP8.2 are additionally covered by branch CI. Physical Android16/KSWEB4.07 verification remains manual on Anabelka-chat-test.

Independent review found a retained-history edit/delete/quote cache regression; four regressions were observed failing then passed after the batched content refresh fix. Extra backlog tests verified sending without acknowledging gaps and preserving viewport position during page insertion.
