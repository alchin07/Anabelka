# Manual image mask preview implementation plan

> **For agentic workers:** implement the three isolated file groups with test-first verification, then review the complete integration before the single requested commit.

**Goal:** choose Auto, GrabCut or MODNet in the existing product photograph editor and publish only a confirmed preview.

**Architecture:** Python accepts an optional explicit mask mode; AUTO keeps its existing decisions. PHP stages a ready result under a server-generated token tied to administrator/session, image and immutable source hash. Confirmation publishes that result in a transaction without inference; cancellation, expiry and failure leave accepted data intact. JavaScript reuses the comparison slider and its Android history integration.

**Tech Stack:** existing PHP/MySQL, Python/Pillow/OpenCV and isolated MODNet worker, plain JavaScript/CSS.

**Spec:** user's explicit task “Анабелька — ручной выбор GrabCut / MODNet в редакторе фотографий”, starting at `46823d182abb19604fb672d5bb7c363f42659e30`.

## Global constraints

- Work only on `feature/category-manager`; do not change main, merge or touch user stash.
- Keep AUTO rules, geometry, detector, worker limits, dimensions and the three background profiles unchanged.
- Preview must never change `product_image_processing` or published status/path.
- Client supplies image IDs and opaque tokens, never filesystem paths.
- Check products.manage, administrator/session ownership, CSRF, expiry, source and output hashes.
- Forced MODNet failures are errors, never disguised GrabCut results.
- Preserve previous ready files and records on failure; cleanup targets only temporary files.
- Diagnostics use existing normalization_json, with requested mode, actual method, profile, version and worker errors.
- One final commit after verification; no database migration.

## Review focus

- Confirm vs cancellation/cleanup races: committed files remain available.
- Interrupted confirmation and repeat confirmation: a newer accepted result cannot be overwritten by a replay.
- File contents change without path change: source/output hash mismatch rejects publication.
- Nested details and preview history: Android Back unwinds the current overlay and cancels only the preview.
- Late asynchronous responses after editor dismissal: no stale preview reopens and accepted state remains correct.

## Task 1 — Python mode dispatch

Files: tools/image-processor/server.py and Python mode tests.

- [x] Add failing tests for omitted AUTO, explicit modes, both replacement profiles, original profile and worker failure/timeout.
- [x] Add `mask_mode` to /process, process_image, normalized_master and custom_background_master. Preserve the AUTO branch; forced modes skip AUTO analyses.
- [x] Require successful forced segmentation; preserve normalization geometry. Emit mask_mode_requested, mask_method, processor_version, worker_error.
- [x] Run new tests and complete Python discovery suite.

## Task 2 — PHP staged publication

Files: existing controller/client/service/model/routes, new ProductImagePreviewService and App loading, private storage guard; PHP runtime tests.

- [x] Add failing filesystem/service tests for preview isolation, confirm, cancel, replay, expiry, source change, wrong owner, output tampering, storage and DB failure, cleanup.
- [x] Implement POST image-process-preview, image-process-confirm, image-process-cancel and authenticated GET image-process-preview-file. Return preview_id, expires_at, processing and preview_url; confirm returns accepted processing.
- [x] Stage originals/master/thumb under opaque temporary jobs. Validate ownership and hashes under locks; publish in a transaction with rollback preserving previous ready result.
- [x] Preserve legacy endpoint compatibility through the safe mechanism and record accepted/error diagnostics in the administrator audit.
- [x] Verify CSRF and access using real router/controller execution and PHP syntax.

## Task 3 — Existing editor and history

Files: js/admin-products.js, css/admin-products.css, product index asset versions, UI runtime/contract tests and audit labels.

- [x] Add failing DOM tests for all mode requests, original profile disabling, preview isolation, confirm/cancel/error, late responses and nested Back.
- [x] Add method select and Попередній перегляд button. Reuse comparison window, original image and slider with private preview_url.
- [x] Add Застосувати/Скасувати; mutate image.processing only on successful confirm. Close/cancel/Back leave accepted state intact.
- [x] Keep old comparison and old photos functional; bump asset versions and add readable audit labels.
- [x] Compare full Node suite with exact starting-commit baseline and check JS syntax.

## Final verification

- [x] Independent review of complete backend security and UI/Python integration.
- [x] Python, PHP and JavaScript tests, syntax and git diff --check.
- [x] Document Android verification and environment limitations; inspect HEAD/branch again.
- [x] Commit and publish only feature/category-manager, preserving a single-parent history.
