# Stable product stock matrix design

## Goal

Replace the current variant-stock editor behavior with a stable mobile-first matrix for editing stock by `size + color` without focus jumps, keyboard dismissal, or DOM replacement while the user types.

The server contract stays unchanged: the editor continues to submit `variant_stock_json` to the existing variant-stock endpoint. No database schema, PHP stock model, cart logic, or public product route changes are part of this refactor.

## Problem

The current editor renders the matrix dynamically and several scripts then post-process its inputs. Totals are mirrored back into the legacy size rows, observers watch those rows, and changes can trigger another render. During manual input the active field can therefore be replaced by a newly rendered element. On Android that destroys focus and closes or repositions the keyboard.

The new design must have a single owner for matrix inputs and a strict rule: typing in a stock field may update only values and totals; it must never rebuild the matrix DOM.

## Architecture

`js/admin-product-variant-stock.js` becomes the sole owner of the variant-stock matrix UI. It creates and manages the matrix fields itself and exposes no focus-repair responsibility to other scripts.

`js/admin-product-editor-fixes.js` must not modify, retag, restore focus to, or otherwise post-process `[data-variant-stock-input]` fields. Its variant-specific focus code will be removed.

The matrix keeps stable DOM nodes until the set of dimensions actually changes. A rebuild is allowed only when at least one of these changes:

- a size row is added or removed;
- a size name changes;
- a product color is added, removed, or changed;
- a different product is loaded into the editor.

Manual stock input, `+/-`, total calculation, and mirroring totals into the legacy size summary fields must not rebuild the matrix.

## Matrix UI

Each size is shown as a vertical card. Each card contains one row per color.

Each color row contains:

- color swatch;
- color name;
- decrement button;
- stable manual input;
- increment button.

The input is created directly as `type="text"`, `inputmode="numeric"`, `pattern="[0-9]*"`, with numeric-only normalization. No other script changes its type after creation.

On direct user focus, the current value is selected so that entering a digit replaces the current value instead of appending to it. The field remains focused while digits are entered.

`+` and `-` update the same stable input node. Stock is never allowed below zero.

## State and data flow

The matrix keeps an in-memory map keyed by normalized `size + color`.

When a product is opened:

1. Existing variant rows are loaded from the current endpoint.
2. Current sizes and colors are read from the product editor.
3. The matrix is built once for those dimensions.
4. Existing stock values populate the stable inputs.

When the user edits stock:

1. Normalize the edited value to a non-negative integer.
2. Update the in-memory map.
3. Update the size total text.
4. Update the grand total text.
5. Mirror the size total into the legacy size row as read-only summary data.
6. Do not rebuild any matrix row or input.

When a real dimension changes, the current matrix values are first cached, then the matrix is rebuilt once from the new dimensions, preserving values for combinations that still exist.

## Legacy size fields

When the matrix is active, the existing `size_stock[]` fields are summaries only.

They must:

- be read-only;
- display the sum of all colors for that size;
- be labelled `Підсумок`;
- never act as a second source of stock data.

The hint remains `Підсумок за розміром рахується з матриці нижче.`

If there is no active color matrix, the legacy behavior may remain available for compatibility.

## Focus rules

The browser owns normal focus behavior. The matrix will not use timers to restore focus and will not call `focus()` after each keystroke.

The only programmatic focus permitted inside the matrix is an explicit focus after pressing `+` or `-`, and even that must target the existing input node rather than a newly rendered one.

No `MutationObserver` may trigger a matrix rebuild from changes caused by total labels, read-only summary values, CSS classes, or accessibility attributes.

## Error handling

Existing API errors continue through `AnabelkaNotify.error(...)` when available. Failed variant saves must not silently discard the values visible in the matrix.

## Testing

Regression contracts must verify:

- matrix inputs are created directly as text/numeric inputs;
- `admin-product-editor-fixes.js` contains no variant-stock focus repair or input retagging;
- the stock input handler calls only value/total update logic and not `render()`;
- the size observer watches structural row changes only;
- totals are updated in place;
- server payload remains `variant_stock_json` with the same row fields;
- cache-busting versions are incremented.

Manual Android/KSWEB verification must confirm:

1. Tap a stock value and the numeric keyboard opens.
2. Type multiple digits continuously; the cursor and keyboard remain stable.
3. The field does not jump or revert while typing.
4. `+/-` work without rebuilding the row.
5. Per-size and grand totals update immediately.
6. Save, reopen the product, and verify the same values are loaded.
7. Open the public product page and verify selected color + size stock matches the saved matrix.

## Out of scope

- database schema changes;
- changes to cart reservation logic;
- changes to public product stock calculations beyond verifying they still consume the same saved variant rows;
- bulk import/export of stock;
- keyboard navigation beyond standard input behavior and existing button accessibility.
