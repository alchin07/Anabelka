# Full-resolution close-up MODNet validation plan

> **For agentic workers:** Execute the scoped fix with test-driven development and independent final review.

**Goal:** Recover source evidence on the exact 2500×3250 close-up without accepting defective alpha masks.

**Architecture:** Retain the original corner-spread check. Only the two upper samples in the validation-only close-up path may additionally corroborate a small spread excess with a smooth, bounded achromatic illumination trend. Lower-corner ownership, colour/chroma limits, connectivity and all alpha-retention checks remain unchanged.

**Tech stack:** Python, NumPy, Pillow, OpenCV; the unchanged ONNX MODNet worker.

**Spec:** User's 2026-10-11 full-resolution MODNet request and attached `anabelka-fullres-closeup-diagnostic.md`.

## Constraints

- Branch `feature/category-manager`, parent `1467a9779bd688bd81932885e4f913a6e1985687`; no main, merge or stash operations.
- Production changes only in close-up source validation without bbox. No alpha repair, GrabCut, geometry, PHP, UI, profiles or preview/confirm changes.
- Keep `source_component_loss`, `source_local_material_loss`, `source_thin_material_loss`, `source_background_retained` operative.

## Review focus

- A sharp two-tone upper corner must not become smooth background.
- High-frequency noise and chromatic changes must remain unsupported.
- An unsupported uniform lower supplier tone must remain ambiguous.
- Real local material loss must reject even after source evidence becomes available.
- Existing detector/bbox routes and the four other originals must not regress.

## Task 1: Bounded upper-corner source evidence

Files: `tools/image-processor/server.py`, `tests/test_image_processor_no_bbox_closeup_fullres.py`, exact original and reduced JPEG fixtures under `tests/fixtures/closeup/`.

- [ ] Pin original size and SHA-256, assert a source seed on both exact original and the earlier 1182×1536 copy; also exercise a generated resize.
- [ ] Add sharp step, noise, chromatic and excessive gradient negatives; retain existing synthetic material-loss and lower-corner negatives.
- [ ] Run regressions on baseline and observe the original's expected missing-seed failure.
- [ ] Add the minimal bounded illumination corroboration only for upper validation samples, retaining the original acceptance path and downstream checks.
- [ ] Run regressions and the complete Python suite.

## Task 2: Real processing and publication

Files: a committed evidence report under `docs/`; detailed diagnostics outside the repository.

- [ ] Capture baseline and candidate real MODNet process results on all five exact originals with unmodified detectors and worker.
- [ ] Preserve raw alpha; compare validators on identical alpha and save local loss components if rejected. Never repair alpha to pass validation.
- [ ] Run PHP/preview tests, full Node baseline comparison, Python/PHP syntax and `git diff --check`.
- [ ] Have an independent reviewer check scope, negative cases and all four retention guards.
- [ ] Create one scoped commit and publish only `feature/category-manager`, checking the remote parent before update; report SHA and Android verification limits.
