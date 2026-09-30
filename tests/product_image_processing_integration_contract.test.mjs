import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');

const app = read('app/Core/App.php');
const imageModel = read('app/Models/ProductImage.php');
const processingModel = read('app/Models/ProductImageProcessing.php');
const processingService = read(
    'app/Services/ProductImageProcessingService.php'
);
const processorController = read(
    'app/Controllers/AdminImageProcessorController.php'
);
const routes = read('routes/Web.php');
const access = read('app/Models/AdminAccess.php');
const pythonServer = read('tools/image-processor/server.py');
const migration = read(
    'database/migrations/2026-09-30_product_image_processing.sql'
);

assert.match(app, /Models\/ProductImageProcessing\.php/);
assert.match(app, /Services\/ProductImageProcessingService\.php/);
assert.match(imageModel, /public static function findById\(\$imageId\)/);
assert.match(
    processingModel,
    /CREATE TABLE IF NOT EXISTS product_image_processing/
);
assert.match(
    processingModel,
    /FOREIGN KEY \(image_id\)[\s\S]*?REFERENCES product_gallery_images\(id\)[\s\S]*?ON DELETE CASCADE/
);
assert.match(processingModel, /status = 'processing'/);
assert.match(processingModel, /status = 'ready'/);
assert.match(processingModel, /status = 'error'/);
assert.match(processingModel, /source_sha256/);
assert.match(processingModel, /master_path/);
assert.match(processingModel, /thumb_path/);
assert.match(processingModel, /processed_at = NOW\(\)/);

assert.match(
    processingService,
    /ProductImage::findById\(\$imageId\)/
);
assert.match(
    processingService,
    /ImageProcessorClient::processProductImage\(\s*\$sourcePath\s*\)/
);
assert.match(processingService, /ProductImageProcessing::markProcessing/);
assert.match(processingService, /ProductImageProcessing::markReady/);
assert.match(processingService, /ProductImageProcessing::markError/);
assert.match(processingService, /\^\[a-f0-9\]\{64\}\$/);
assert.match(processingService, /storage\/image-processor/);

assert.match(processorController, /\$_POST\['image_id'\]/);
assert.doesNotMatch(
    processorController,
    /\$_POST\[['"](?:path|source)['"]\]/
);
assert.match(
    processorController,
    /ProductImageProcessingService::process/
);
assert.match(processorController, /product\.image_processed/);

assert.match(
    routes,
    /\/admin\/products\/image-process[\s\S]*?AdminImageProcessorController@process/
);
assert.match(routes, /image-process[\s\S]*?'csrf'\s*=>\s*true/);
assert.match(
    access,
    /'\/admin\/products'\s*=>\s*\['products\.view',\s*'products\.manage'\]/
);

assert.match(pythonServer, /VERSION = "0\.2"/);
assert.match(pythonServer, /"processor_version": VERSION/);
assert.match(
    migration,
    /CREATE TABLE IF NOT EXISTS product_image_processing/
);
assert.match(migration, /ON DELETE CASCADE/);

process.stdout.write(
    'product image processing integration contract passed\n'
);
