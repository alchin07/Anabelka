import assert from 'node:assert/strict';
import fs from 'node:fs';
const client = fs.readFileSync('app/Services/ImageProcessorClient.php', 'utf8');
assert.match(client, /processProductImage\([\s\S]*?\$maskMode\s*=\s*'auto'/);
assert.match(client, /'mask_mode'\s*=>\s*\$maskMode/);
assert.match(client, /normalizeMaskMode\(\$maskMode\)/);
assert.match(client, /class ImageProcessorException extends RuntimeException/);
assert.match(client, /worker_error/);
process.stdout.write('image processor mask mode contract passed\n');
