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
const personDetector = read('tools/image-processor/mp_persondet.py');
const personInstaller = read(
    'tools/image-processor/install-person-model.sh'
);
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
assert.match(processingModel, /normalization_json/);
assert.match(
    processingModel,
    /\$db\s*=\s*Database::connect\(\);[\s\S]*?\$db->exec\(/
);
assert.match(processingModel, /SHOW COLUMNS[\s\S]*?normalization_json/);

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

assert.match(pythonServer, /VERSION = "0\.5"/);
assert.match(pythonServer, /PROFILE = "model-normalize-v2"/);
assert.match(pythonServer, /MASTER_SIZE = \(1200, 1800\)/);
assert.match(pythonServer, /THUMB_SIZE = \(320, 480\)/);
assert.match(pythonServer, /def standard_canvas\(/);
assert.match(pythonServer, /def detect_person_bbox\(/);
assert.match(pythonServer, /def detect_face_subject_bbox\(/);
assert.match(pythonServer, /haarcascade_frontalface_default\.xml/);
assert.match(pythonServer, /def find_face_cascade_path\(/);
assert.match(pythonServer, /face_cascade_ready/);
assert.match(pythonServer, /opencv-haar-face-subject/);
assert.match(pythonServer, /from mp_persondet import MPPersonDet/);
assert.match(pythonServer, /person_detection_mediapipe_2023mar\.onnx/);
assert.match(pythonServer, /def detect_mediapipe_person_bbox\(/);
assert.match(pythonServer, /def mediapipe_aspect_fill_crop_box\(/);
assert.match(pythonServer, /retained_area_ratio < 0\.80/);
assert.match(pythonServer, /crop_strategy/);
assert.match(pythonServer, /aspect-fill/);

assert.match(pythonServer, /mediapipe-persondet/);
assert.match(pythonServer, /person_model_ready/);
assert.match(pythonServer, /PERSON_MODEL_SHA256/);
assert.match(personDetector, /class MPPersonDet/);
assert.match(personInstaller, /EXPECTED_SHA256/);
assert.match(personInstaller, /EXPECTED_SIZE="11990159"/);
assert.match(personInstaller, /opencv\/opencv_zoo/);


assert.match(pythonServer, /cv2\.HOGDescriptor_getDefaultPeopleDetector/);
assert.match(pythonServer, /def subject_crop_box\(/);
assert.match(pythonServer, /def normalized_master\(/);
assert.match(pythonServer, /"normalization": normalization/);
assert.match(pythonServer, /"crop_applied": True/);
assert.match(pythonServer, /"crop_applied": False/);

assert.match(pythonServer, /"processor_version": VERSION/);
assert.match(pythonServer, /"profile": PROFILE/);
assert.match(processingService, /uploads\/products\/processed\//);
assert.match(processingService, /processingProfile/);
assert.match(processingService, /normalizedDiagnostics/);
assert.match(processingService, /crop_applied/);
assert.match(processingService, /subject_detected/);
assert.match(processingService, /mediapipe-persondet/);
assert.match(processingService, /person_score/);
assert.match(processingService, /cropStrategy/);
assert.match(processingService, /aspect-fill/);
assert.match(processingService, /subject-bbox/);



assert.match(
    migration,
    /CREATE TABLE IF NOT EXISTS product_image_processing/
);
assert.match(migration, /ON DELETE CASCADE/);
assert.match(migration, /normalization_json TEXT NULL/);


process.stdout.write(
    'product image processing integration contract passed\n'
);
