import assert from 'node:assert/strict';
import fs from 'node:fs';
import {spawnSync} from 'node:child_process';

const service = fs.readFileSync('app/Services/ProductImageProcessingService.php', 'utf8');
const controller = fs.readFileSync('app/Controllers/AdminImageProcessorController.php', 'utf8');
const view = fs.readFileSync('views/admin/products/index.php', 'utf8');
const script = fs.readFileSync('js/admin-products.js', 'utf8');
const worker = fs.readFileSync('tools/image-processor/server.py', 'utf8');

assert.match(service, /private const MANUAL_MASK_REVIEW_ENABLED = false;/);
assert.match(service, /public static function manualMaskReviewEnabled\(\): bool/);
assert.match(service, /->create\(\s*\$imageId, \$backgroundProfile, \$maskMode, self::manualMaskReviewEnabled\(\)/);
assert.match(service, /->create\(\$imageId, \$backgroundProfile, \$maskMode, false\)/);

for (const method of ['maskStart', 'maskEdit', 'maskResult', 'maskFile']) {
    const start = controller.indexOf('public function ' + method + '()');
    assert.ok(start >= 0, 'Missing method: ' + method);
    const body = controller.slice(start, start + 400);
    assert.match(body, /\$this->requireManualMaskReview\(\)/);
}
assert.match(controller, /if \(!ProductImageProcessingService::manualMaskReviewEnabled\(\)\)/);
assert.equal(
    (view.match(/if \(ProductImageProcessingService::manualMaskReviewEnabled\(\)\): \?>/g) || []).length,
    2,
    'Both optional mask editor assets must be behind the feature switch.'
);
assert.match(view, /admin-mask-editor.css/);
assert.match(view, /admin-mask-editor.js/);
assert.match(script, /preview\.reviewAvailable = data\.review_available === true && Boolean\(maskEditor\(\)\)/);
assert.match(script, /if \(data\.state === 'needs_review'\)[\s\S]*?if \(!maskEditor\(\)\) \{/);
assert.match(worker, /allow_review: bool = False/);
assert.match(script, /\['auto', 'grabcut', 'modnet'\]/);

const php = spawnSync(process.env.PHP_BIN || 'php', ['tests/product_image_manual_mask_disabled_runtime.php'], {
    encoding: 'utf8', timeout: 30000
});
assert.equal(php.error, undefined, String(php.error));
assert.equal(php.status, 0, php.stderr || php.stdout);
assert.equal(php.stderr, '');
assert.equal(php.stdout, 'product manual mask disabled runtime passed\n');
process.stdout.write('product manual mask disabled contract passed\n');
