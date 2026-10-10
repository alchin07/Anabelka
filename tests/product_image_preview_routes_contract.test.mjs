import assert from 'node:assert/strict';
import fs from 'node:fs';

const routes = fs.readFileSync('routes/Web.php', 'utf8');
for (const [suffix, method] of [['preview', 'preview'], ['confirm', 'confirm'], ['cancel', 'cancel']]) {
  assert.match(routes, new RegExp(`\\$router->post\\(\\s*'/admin/products/image-process-${suffix}',\\s*'AdminImageProcessorController@${method}',\\s*\\['csrf'\\s*=>\\s*true,\\s*'csrf_family'\\s*=>\\s*'admin'\\]`));
}
assert.match(routes, /\$router->get\(\s*'\/admin\/products\/image-process-preview-file',\s*'AdminImageProcessorController@previewFile'/);
const access = fs.readFileSync('app/Models/AdminAccess.php', 'utf8');
assert.match(access, /\$path === '\/admin\/products\/image-process-preview-file'[\s\S]*?return 'products.manage'/);
assert.match(fs.readFileSync('.htaccess', 'utf8'), /RewriteRule \^storage\/image-processor\/.*\[F,L\]/);
const controller = fs.readFileSync('app/Controllers/AdminImageProcessorController.php', 'utf8');
for (const method of ['preview', 'confirm', 'cancel', 'previewFile']) {
  assert.match(controller, new RegExp(`public function ${method}\\(\\)`));
}
assert.match(controller, /mask_mode_requested/);
assert.match(controller, /worker_error/);
assert.match(controller, /Cache-Control: private, no-store/);
assert.match(fs.readFileSync('app/Core/App.php', 'utf8'), /Services\/ProductImagePreviewService\.php/);
process.stdout.write('product image preview route contract passed\n');
