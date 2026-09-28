import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');

const app = read('app/Core/App.php');
const routes = read('routes/AdminSecurity.php');
const client = read('app/Services/ImageProcessorClient.php');
const controller = read(
    'app/Controllers/AdminImageProcessorController.php'
);
const server = read('tools/image-processor/server.py');
const gitignore = read('.gitignore');

assert.match(
    app,
    /Services\/ImageProcessorClient\.php/
);
assert.match(
    app,
    /Controllers\/AdminImageProcessorController\.php/
);
assert.match(
    routes,
    /\/admin\/image-processor\/health[\s\S]*?AdminImageProcessorController@health/
);

assert.match(
    client,
    /http:\/\/127\.0\.0\.1:8765/
);
assert.match(
    client,
    /public static function health\(\)/
);
assert.match(
    client,
    /public static function processProductImage\(\$path\)/
);
assert.match(
    client,
    /uploads\/products\//
);
assert.match(
    client,
    /strpos\(\$path, '\.\.\/'\)/
);
assert.match(
    client,
    /ANABELKA_IMAGE_PROCESSOR_URL/
);
assert.equal(
    client.includes('127\\\\.0\\\\.0\\\\.1|localhost'),
    true
);

assert.match(
    controller,
    /ImageProcessorClient::health\(\)/
);
assert.match(
    controller,
    /503/
);

assert.match(server, /HOST = "127\.0\.0\.1"/);
assert.doesNotMatch(server, /0\.0\.0\.0/);
assert.match(server, /self\.path != "\/health"/);
assert.match(server, /self\.path != "\/process"/);
assert.match(server, /SOURCE_ROOT/);
assert.match(server, /uploads/);
assert.match(server, /products/);
assert.match(server, /\.\." in relative\.parts/);
assert.match(server, /cv2\.imread/);
assert.match(server, /ImageOps\.exif_transpose/);
assert.match(server, /UnidentifiedImageError/);
assert.match(server, /shutil\.copy2/);
assert.match(server, /sha256_file/);
assert.match(server, /master\.webp/);
assert.match(server, /thumb\.webp/);
assert.match(server, /immutable_by_service/);
assert.match(server, /MAX_SOURCE_BYTES/);
assert.match(server, /ThreadingHTTPServer/);

assert.match(
    gitignore,
    /storage\/image-processor\//
);

process.stdout.write(
    'image processor foundation contract passed\n'
);
