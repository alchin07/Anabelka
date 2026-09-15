# Stable Product Stock Matrix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the current focus-repair-based stock matrix with a stable mobile-first `size + color` editor whose input nodes are never replaced while the user types.

**Architecture:** `js/admin-product-variant-stock.js` becomes the sole owner of variant-stock inputs and their focus behavior. The matrix rebuilds only when dimensions change (size/color/product), while typing and `+/-` update only the existing input value and in-place totals. `js/admin-product-editor-fixes.js` stops touching variant-stock fields entirely.

**Tech Stack:** PHP templates, vanilla JavaScript, DOM APIs, Node `node:test` contract tests, Android/KSWEB manual verification.

**Spec:** `docs/superpowers/specs/2026-09-15-stable-product-stock-matrix-design.md`

## Global Constraints

- Preserve the existing `variant_stock_json` server payload and existing variant-stock endpoints.
- Do not change database schema, cart reservation logic, public product routes, or PHP stock models.
- Matrix inputs are created directly as `type="text"`, `inputmode="numeric"`, `pattern="[0-9]*"`.
- No timer-based focus restoration is allowed for variant-stock inputs.
- Typing, `+/-`, per-size totals, grand total updates, and legacy summary mirroring must not rebuild matrix DOM.
- Matrix rebuild is permitted only when size set, size names, color set/color values, or loaded product changes.
- Existing `size_stock[]` fields are read-only summaries while matrix mode is active.
- PR #4 stays draft; do not merge `main` during this work.

---

### Task 1: Lock the stable-input contract

**Files:**
- Modify: `tests/product_variant_stock_mobile_contract.test.mjs`
- Test: `tests/product_variant_stock_mobile_contract.test.mjs`

**Interfaces:**
- Consumes: current matrix implementation in `js/admin-product-variant-stock.js` and current editor helper in `js/admin-product-editor-fixes.js`.
- Produces: regression guards that later tasks must satisfy.

- [ ] **Step 1: Write failing ownership/focus tests**

Add assertions equivalent to:

```js
const matrix = read('js/admin-product-variant-stock.js');
const fixes = read('js/admin-product-editor-fixes.js');

assert.match(matrix, /input\.type\s*=\s*['"]text['"]/);
assert.match(matrix, /input\.inputMode\s*=\s*['"]numeric['"]/);
assert.match(matrix, /input\.pattern\s*=\s*['"]\[0-9\]\*['"]/);
assert.match(matrix, /input\.select\(\)/);

assert.doesNotMatch(fixes, /data-variant-stock-input/);
assert.doesNotMatch(fixes, /variantRoot/);
assert.doesNotMatch(fixes, /activeVariantKey/);
assert.doesNotMatch(fixes, /restoreVariantFocus/);
```

Add a second contract that extracts the body of the matrix stock input handler and asserts it does not call `render(`:

```js
const inputHandler = matrix.match(
    /input\.addEventListener\(['"]input['"],[\s\S]*?\n\s*}\);/
)?.[0] || '';

assert.notEqual(inputHandler, '');
assert.doesNotMatch(inputHandler, /\brender\s*\(/);
assert.match(inputHandler, /updateTotals\s*\(/);
```

- [ ] **Step 2: Run the contract and verify RED**

Run:

```bash
node tests/product_variant_stock_mobile_contract.test.mjs
```

Expected: failure because `admin-product-editor-fixes.js` still owns variant focus/input retagging and the matrix still creates number inputs.

- [ ] **Step 3: Commit the RED test**

```bash
git add tests/product_variant_stock_mobile_contract.test.mjs
git commit -m "test: lock stable variant stock input ownership"
```

---

### Task 2: Make the matrix the only owner of stock inputs

**Files:**
- Modify: `js/admin-product-variant-stock.js`
- Modify: `js/admin-product-editor-fixes.js`
- Test: `tests/product_variant_stock_mobile_contract.test.mjs`

**Interfaces:**
- Consumes: existing `buildColorRow(sizeName, color)` and `updateTotals()` in `admin-product-variant-stock.js`.
- Produces: stable `[data-variant-stock-input]` nodes with browser-native focus behavior.

- [ ] **Step 1: Remove variant-specific focus code from `admin-product-editor-fixes.js`**

Delete the entire variant block beginning with:

```js
const productIdField = document.getElementById('product-edit-id');
const variantRoot = form.querySelector(...);
```

through the variant `focusin`, `focusout`, and numeric-cleaning `input` listeners. Keep unrelated size-stock and translation editor fixes unchanged.

- [ ] **Step 2: Create stable text/numeric inputs directly in `buildColorRow()`**

Replace number-input setup with:

```js
input.type = 'text';
input.inputMode = 'numeric';
input.pattern = '[0-9]*';
input.autocomplete = 'off';
input.enterKeyHint = 'next';
input.className = 'product-variant-stock-input';
input.dataset.variantStockInput = '';
```

Do not set `min` or `step`.

- [ ] **Step 3: Normalize input without replacing the node**

Use a dedicated helper:

```js
function normalizeStockValue(value)
{
    const digits = String(value || '').replace(/[^0-9]/g, '');
    return digits === '' ? '' : String(Math.max(0, Number(digits)));
}
```

The input handler must mutate only `input.value`, `matrixTouched`, cache/totals:

```js
input.addEventListener('input', function () {
    const normalized = normalizeStockValue(input.value);

    if (normalized !== input.value) {
        input.value = normalized;
    }

    matrixTouched = true;
    updateTotals();
});
```

- [ ] **Step 4: Select the current value on direct focus**

```js
input.addEventListener('focus', function () {
    window.requestAnimationFrame(function () {
        if (document.activeElement === input) {
            input.select();
        }
    });
});
```

This lets typing replace the existing `0`/count immediately.

- [ ] **Step 5: Normalize empty values only on blur**

```js
input.addEventListener('blur', function () {
    const normalized = normalizeStockValue(input.value);
    input.value = normalized === '' ? '0' : normalized;
    updateTotals();
});
```

- [ ] **Step 6: Keep `+/-` on the same node**

`adjustStock(input, delta)` must update `input.value`, call `updateTotals()`, and may call `input.focus()` on the existing node. It must not call `render()`.

- [ ] **Step 7: Run the ownership tests**

```bash
node tests/product_variant_stock_mobile_contract.test.mjs
```

Expected: ownership/focus assertions pass; later dimension-rebuild assertions may still fail until Task 3.

- [ ] **Step 8: Commit**

```bash
git add js/admin-product-variant-stock.js js/admin-product-editor-fixes.js tests/product_variant_stock_mobile_contract.test.mjs
git commit -m "refactor: make stock matrix own stable inputs"
```

---

### Task 3: Rebuild only when dimensions change

**Files:**
- Modify: `js/admin-product-variant-stock.js`
- Modify: `tests/product_variant_stock_mobile_contract.test.mjs`

**Interfaces:**
- Consumes: `currentSizes()`, `currentColors()`, `cacheCurrentInputs()`, and `render()`.
- Produces: a dimension signature and guarded rebuild path.

- [ ] **Step 1: Add failing dimension-rebuild contract**

Add assertions requiring these identifiers:

```js
assert.match(matrix, /function\s+dimensionSignature\s*\(/);
assert.match(matrix, /function\s+rebuildIfDimensionsChanged\s*\(/);
assert.match(matrix, /lastDimensionSignature/);
```

Guard against the old unconditional size observer:

```js
assert.doesNotMatch(
    matrix,
    /new MutationObserver\(function\s*\(\)\s*\{\s*window\.setTimeout\(render,\s*0\)/
);
```

- [ ] **Step 2: Run RED**

```bash
node tests/product_variant_stock_mobile_contract.test.mjs
```

Expected: failure because guarded dimension signatures do not exist yet.

- [ ] **Step 3: Add dimension signature**

Implement:

```js
let lastDimensionSignature = '';

function dimensionSignature()
{
    return JSON.stringify({
        productId: Number(productIdField.value || 0),
        sizes: currentSizes().map(textKey),
        colors: currentColors().map(function (color) {
            return colorKey(color.name, color.hex);
        })
    });
}
```

- [ ] **Step 4: Add guarded rebuild**

```js
function rebuildIfDimensionsChanged(force)
{
    const nextSignature = dimensionSignature();

    if (!force && nextSignature === lastDimensionSignature) {
        return false;
    }

    cacheCurrentInputs();
    lastDimensionSignature = nextSignature;
    render({ preferLoadedRows: false, force: true });
    return true;
}
```

Adjust `render()` so normal total/input updates do not call it; only explicit dimension/product events do.

- [ ] **Step 5: Route structural events through the guard**

Use:

```js
form.addEventListener('input', function (event) {
    if (event.target.matches('[data-size-name]')) {
        window.setTimeout(function () {
            rebuildIfDimensionsChanged(false);
        }, 0);
    }
});
```

Size list observer stays structural only:

```js
const sizeObserver = new MutationObserver(function () {
    rebuildIfDimensionsChanged(false);
});
sizeObserver.observe(sizeList, { childList: true });
```

Color/image events call `rebuildIfDimensionsChanged(false)` instead of unconditional `render()`.

- [ ] **Step 6: Force one rebuild when product changes**

At the end of `loadForProduct(productId)`, after loading rows and clearing cache:

```js
lastDimensionSignature = '';
rebuildIfDimensionsChanged(true);
```

- [ ] **Step 7: Confirm total updates are in-place only**

`updateTotals()` may update:
- `input.value` normalization;
- per-size total text;
- grand total text;
- legacy `size_stock[]` summary value/readOnly state;
- hint text.

It must not call `render()` or `rebuildIfDimensionsChanged()`.

- [ ] **Step 8: Run tests**

```bash
node tests/product_variant_stock_mobile_contract.test.mjs
```

Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add js/admin-product-variant-stock.js tests/product_variant_stock_mobile_contract.test.mjs
git commit -m "fix: rebuild stock matrix only on dimension changes"
```

---

### Task 4: Preserve payload compatibility and cache-bust the refactor

**Files:**
- Modify: `tests/product_variant_stock_mobile_contract.test.mjs`
- Modify: `views/admin/partials/header.php`
- Test: `tests/product_variant_stock_mobile_contract.test.mjs`

**Interfaces:**
- Consumes: `matrixRows()` and existing `/admin/products/variant-stock/save` request.
- Produces: unchanged backend contract and fresh browser assets.

- [ ] **Step 1: Add payload compatibility assertions**

```js
assert.match(matrix, /variant_stock_json/);
assert.match(matrix, /size_name/);
assert.match(matrix, /color_name/);
assert.match(matrix, /color_hex/);
assert.match(matrix, /stock/);
assert.match(matrix, /\/Anabelka\/admin\/products\/variant-stock\/save/);
```

- [ ] **Step 2: Update cache-busting versions**

In `views/admin/partials/header.php` use:

```html
<script defer src="/Anabelka/js/admin-product-variant-stock.js?v=5"></script>
<script defer src="/Anabelka/js/admin-product-editor-fixes.js?v=3"></script>
```

Update the test to require `v=5` and `v=3`.

- [ ] **Step 3: Run contract tests**

```bash
node tests/product_variant_stock_mobile_contract.test.mjs
```

Expected: PASS with zero failures.

- [ ] **Step 4: Run syntax checks**

```bash
node --check js/admin-product-variant-stock.js
node --check js/admin-product-editor-fixes.js
node --check tests/product_variant_stock_mobile_contract.test.mjs
```

Expected: all commands exit 0.

- [ ] **Step 5: Commit**

```bash
git add views/admin/partials/header.php tests/product_variant_stock_mobile_contract.test.mjs
git commit -m "chore: cache-bust stable stock matrix"
```

---

### Task 5: Android/KSWEB acceptance verification

**Files:**
- No code changes unless a reproduced defect is found.

**Interfaces:**
- Consumes: completed stable matrix from Tasks 1-4.
- Produces: owner-verified mobile acceptance evidence before PR #4 merge consideration.

- [ ] **Step 1: Pull the feature branch on Android**

```bash
git pull origin feature/category-manager
```

- [ ] **Step 2: Verify continuous manual typing**

Open an existing product, tap a matrix value, type `12`, then `34` in another cell.

Expected:
- numeric keyboard stays open;
- cursor remains in the same field;
- field does not jump, reset, or disappear after the first digit;
- current value is selected on initial tap, so typing replaces it.

- [ ] **Step 3: Verify `+/-` controls**

Expected:
- `+` increments by one;
- `-` decrements by one but never below zero;
- the same input node remains visible;
- no card redraw/flicker is visible.

- [ ] **Step 4: Verify totals**

Expected:
- per-size total changes immediately;
- grand total changes immediately;
- legacy size rows show read-only `Підсумок` values matching the sum of colors.

- [ ] **Step 5: Verify persistence**

Save the product, close editor, reopen it.

Expected: every `size + color` value matches what was saved.

- [ ] **Step 6: Verify public product stock**

Open the public product page and switch colors.

Expected: each size availability/quantity matches the saved matrix for the selected color.

- [ ] **Step 7: Keep PR #4 draft**

Do not merge. Record any remaining defect before final PR verification.
