<?php
// Real preview filesystem and production normalization/model; only DB and worker are replaced.
function check($condition, $message) {
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
}
function rejects(callable $fn, $message) {
    try { $fn(); } catch (Throwable $e) { return; }
    throw new RuntimeException('FAIL: ' . $message);
}
check(is_file(__DIR__ . '/../app/Services/ProductImagePreviewService.php'), 'preview service exists');

class AdminAccess {
    public static $id = 9;
    public static $allowed = true;
    public static function currentId() { return self::$id; }
    public static function can($permission) { return self::$allowed && $permission === 'products.manage'; }
}
class ProductImage {
    public static $images = [];
    public static function ensureTable() {}
    public static function findById($id) { return self::$images[$id] ?? null; }
}
class FakeStatement {
    private $sql;
    private $data;
    public function __construct($sql) { $this->sql = $sql; }
    public function execute($data = []) {
        $this->data = $data;
        if (strpos($this->sql, 'INSERT INTO product_image_processing') !== false && isset($data['job_id'])) {
            if (Database::$db->failSave) { throw new RuntimeException('DB save failed'); }
            Database::$db->rows[$data['image_id']] = array_merge($data, ['status' => 'ready']);
        }
        return true;
    }
    public function fetch($mode = null) {
        if (strpos($this->sql, 'product_gallery_images') !== false) {
            return ProductImage::$images[$this->data['image_id'] ?? $this->data['id']] ?? false;
        }
        return Database::$db->rows[$this->data['image_id'] ?? 0] ?? false;
    }
    public function fetchColumn() {
        foreach (Database::$db->rows as $row) {
            if (($row['job_id'] ?? '') === ($this->data['job_id'] ?? null)) { return 1; }
        }
        return false;
    }
}
class FakeDatabase {
    public $rows = [];
    public $failSave = false;
    public $failCommit = false;
    public $beforeCommit = null;
    private $snapshot = null;
    public function prepare($sql) { return new FakeStatement($sql); }
    public function beginTransaction() { $this->snapshot = $this->rows; return true; }
    public function commit() {
        if ($this->failCommit) { throw new RuntimeException('commit failed'); }
        if ($this->beforeCommit) { ($this->beforeCommit)(); }
        $this->snapshot = null; return true;
    }
    public function inTransaction() { return $this->snapshot !== null; }
    public function rollBack() { $this->rows = $this->snapshot; $this->snapshot = null; return true; }
}
class Database {
    public static $db;
    public static function connect() { return self::$db; }
}
Database::$db = new FakeDatabase();
require __DIR__ . '/../app/Models/ProductImageProcessing.php';
$schema = new ReflectionProperty(ProductImageProcessing::class, 'schemaReady');
$schema->setValue(null, true);
require __DIR__ . '/../app/Services/ProductImageProcessingService.php';
require __DIR__ . '/../app/Services/ImageProcessorClient.php';
require __DIR__ . '/../app/Services/ProductImagePreviewService.php';

$root = sys_get_temp_dir() . '/anabelka-preview-test-' . bin2hex(random_bytes(8));
mkdir($root . '/uploads/products', 0700, true);
file_put_contents($root . '/uploads/products/source.jpg', 'original photograph');
ProductImage::$images[7] = ['id' => 7, 'product_id' => 3, 'path' => '/Anabelka/uploads/products/source.jpg'];
$clock = 100000;
$calls = 0;
$responseEdit = null;
$processor = function ($source, $background, $mode) use ($root, &$calls, &$responseEdit) {
    $calls++;
    $job = bin2hex(random_bytes(16));
    $original = 'storage/image-processor/originals/' . $job . '/source.jpg';
    $master = 'uploads/products/processed/' . $job . '/master.webp';
    $thumb = 'uploads/products/processed/' . $job . '/thumb.webp';
    mkdir(dirname($root . '/' . $original), 0700, true);
    mkdir(dirname($root . '/' . $master), 0700, true);
    copy($root . '/' . $source, $root . '/' . $original);
    file_put_contents($root . '/' . $master, 'preview master ' . $job);
    file_put_contents($root . '/' . $thumb, 'preview thumb ' . $job);
    $response = [
        'ok' => true, 'source' => $source, 'job_id' => $job,
        'processor_version' => '0.11', 'profile' => 'model-normalize-v8',
        'original' => ['path' => $original, 'sha256' => hash_file('sha256', $root . '/' . $original), 'bytes' => filesize($root . '/' . $original)],
        'input' => ['width' => 200, 'height' => 300],
        'master' => ['path' => $master, 'width' => 1200, 'height' => 1800, 'bytes' => filesize($root . '/' . $master)],
        'thumb' => ['path' => $thumb, 'width' => 320, 'height' => 480, 'bytes' => filesize($root . '/' . $thumb)],
        'normalization' => ['method' => 'standard-canvas-fallback', 'background_profile' => $background,
            'background_profile_requested' => $background, 'mask_mode_requested' => $mode,
            'mask_method' => $mode === 'modnet' ? 'modnet' : 'opencv-grabcut', 'subject_mask_applied' => $background !== 'original-canvas', 'processor_version' => '0.11', 'worker_error' => null]
    ];
    if ($responseEdit) { $response = $responseEdit($response); }
    return $response;
};
session_id('test-preview-session');
$service = new ProductImagePreviewService($root, $processor, function () use (&$clock) { return $clock; });

try {
    $preview = $service->create(7, 'studio-light', 'modnet');
    check($calls === 1, 'preview runs one inference');
    check(Database::$db->rows === [], 'preview does not create or mutate processing DB rows');
    check($preview['processing']['normalization']['mask_method'] === 'modnet', 'preview exposes normalized diagnostics');
    check(strpos($preview['preview_url'], 'image-process-preview-file') !== false, 'preview URL is protected controller route');
    $stagedMaster = $service->previewFile(7, $preview['preview_id']);
    check(is_file($stagedMaster) && strpos($stagedMaster, '/previews/') !== false, 'preview is private staged file');
    check(count(glob($root . '/uploads/products/processed/*/master.webp')) === 0, 'preview generated output is removed from public directory');
    $accepted = $service->confirm(7, $preview['preview_id']);
    check($calls === 1, 'confirm never reruns inference');
    check(($accepted['status'] ?? '') === 'ready', 'first confirmation creates ready row');
    check(is_file($root . '/' . $accepted['master_path']), 'confirmed master exists');
    check($accepted['normalization']['mask_mode_requested'] === 'modnet', 'requested mode survives DB publication');
    $again = $service->confirm(7, $preview['preview_id']);
    check($again['job_id'] === $accepted['job_id'] && $calls === 1, 'same confirmation is idempotent');

    $cancel = $service->create(7, 'studio-light', 'grabcut');
    $service->cancel(7, $cancel['preview_id']);
    $service->cancel(7, $cancel['preview_id']);
    rejects(function () use ($service, $cancel) { $service->confirm(7, $cancel['preview_id']); }, 'cancelled preview cannot publish');
    check(ProductImageProcessing::find(7)['job_id'] === $accepted['job_id'], 'cancel leaves live image unchanged');

    $owner = $service->create(7, 'studio-light', 'auto');
    AdminAccess::$id = 10;
    rejects(function () use ($service, $owner) { $service->previewFile(7, $owner['preview_id']); }, 'wrong owner denied');
    AdminAccess::$id = 9;
    session_id('other-preview-session');
    rejects(function () use ($service, $owner) { $service->confirm(7, $owner['preview_id']); }, 'wrong session denied');
    session_id('test-preview-session');
    AdminAccess::$allowed = false;
    rejects(function () use ($service, $owner) { $service->previewFile(7, $owner['preview_id']); }, 'view-only admin denied preview GET');
    AdminAccess::$allowed = true;
    rejects(function () use ($service, $owner) { $service->confirm(8, $owner['preview_id']); }, 'token bound to image ID');
    rejects(function () use ($service) { $service->confirm(7, '../../etc/passwd'); }, 'arbitrary client path denied');

    ProductImage::$images[7]['path'] = '/Anabelka/uploads/products/other.jpg';
    file_put_contents($root . '/uploads/products/other.jpg', 'original photograph');
    rejects(function () use ($service, $owner) { $service->confirm(7, $owner['preview_id']); }, 'changed DB path denied even for identical contents');
    ProductImage::$images[7]['path'] = '/Anabelka/uploads/products/source.jpg';
    file_put_contents($root . '/uploads/products/source.jpg', 'changed source');
    rejects(function () use ($service, $owner) { $service->confirm(7, $owner['preview_id']); }, 'changed source hash denied');
    file_put_contents($root . '/uploads/products/source.jpg', 'original photograph');
    file_put_contents($service->previewFile(7, $owner['preview_id']), 'tampered staged master');
    rejects(function () use ($service, $owner) { $service->confirm(7, $owner['preview_id']); }, 'changed staged output denied');

    $expired = $service->create(7, 'studio-light', 'auto');
    $expiredDirectory = $root . '/storage/image-processor/previews/' . $expired['preview_id'];
    $expiredManifest = json_decode(file_get_contents($expiredDirectory . '/manifest.json'), true);
    $expiredFinalDirectory = $root . '/uploads/products/processed/' . $expiredManifest['publication_id'];
    mkdir($expiredFinalDirectory, 0700);
    file_put_contents($expiredFinalDirectory . '/master.webp', 'interrupted bytes');
    $clock += 1801;
    rejects(function () use ($service, $expired) { $service->previewFile(7, $expired['preview_id']); }, 'expired preview denied');
    $busyImageLock = fopen($root . '/storage/image-processor/previews/locks/image-7.lock', 'c+b');
    flock($busyImageLock, LOCK_EX);
    $service->cleanupExpired();
    check(is_file($expiredDirectory . '/manifest.json'), 'expiry cleanup defers manifest deletion while image lock is busy');
    flock($busyImageLock, LOCK_UN);
    fclose($busyImageLock);
    $service->cleanupExpired();
    check(is_file($root . '/' . $accepted['master_path']), 'expiry cleanup never deletes accepted final');
    check(is_file($root . '/' . $accepted['original_path']), 'expiry cleanup never deletes accepted original');
    check(!file_exists($expiredFinalDirectory . '/master.webp'), 'expiry cleanup removes proven unpublished partial final');

    $older = $service->create(7, 'studio-light', 'auto');
    $newer = $service->create(7, 'studio-light', 'auto');
    $latest = $service->confirm(7, $newer['preview_id']);
    rejects(function () use ($service, $older) { $service->confirm(7, $older['preview_id']); }, 'stale preview cannot overwrite newer accepted image');
    rejects(function () use ($service, $preview) { $service->confirm(7, $preview['preview_id']); }, 'old replay cannot return or publish older accepted row');

    $failure = $service->create(7, 'studio-light', 'auto');
    $beforeFiles = glob($root . '/uploads/products/processed/*/master.webp');
    Database::$db->failSave = true;
    rejects(function () use ($service, $failure) { $service->confirm(7, $failure['preview_id']); }, 'DB failure surfaces');
    Database::$db->failSave = false;
    check(ProductImageProcessing::find(7)['job_id'] === $latest['job_id'], 'DB rollback preserves previous ready row');
    check(glob($root . '/uploads/products/processed/*/master.webp') === $beforeFiles, 'DB failure removes only new finals');
    check(is_file($root . '/' . $latest['master_path']), 'old accepted files survive rollback');
    $service->confirm(7, $failure['preview_id']);
    $commitFailure = $service->create(7, 'studio-light', 'auto');
    $beforeCommitRow = ProductImageProcessing::find(7);
    $beforeCommitFiles = glob($root . '/uploads/products/processed/*/master.webp');
    Database::$db->failCommit = true;
    rejects(function () use ($service, $commitFailure) { $service->confirm(7, $commitFailure['preview_id']); }, 'transaction commit failure surfaces');
    Database::$db->failCommit = false;
    check(ProductImageProcessing::find(7)['job_id'] === $beforeCommitRow['job_id'], 'commit failure preserves previous ready row');
    check(glob($root . '/uploads/products/processed/*/master.webp') === $beforeCommitFiles, 'commit failure removes only newly written finals');

    $blocked = $service->create(7, 'studio-light', 'auto');
    $beforeRow = ProductImageProcessing::find(7);
    rename($root . '/uploads/products/processed', $root . '/uploads/products/processed-safe');
    file_put_contents($root . '/uploads/products/processed', 'blocked directory');
    rejects(function () use ($service, $blocked) { $service->confirm(7, $blocked['preview_id']); }, 'final directory write failure surfaces');
    unlink($root . '/uploads/products/processed');
    rename($root . '/uploads/products/processed-safe', $root . '/uploads/products/processed');
    check(ProductImageProcessing::find(7)['job_id'] === $beforeRow['job_id'], 'file failure preserves old ready DB row');

    rejects(function () use ($service) { $service->create(7, 'studio-light', 'anything'); }, 'unknown mask mode rejected before inference');
    $responseEdit = function ($data) { $data['normalization']['mask_method'] = 'opencv-grabcut'; return $data; };
    rejects(function () use ($service) { $service->create(7, 'studio-light', 'modnet'); }, 'forced MODNet cannot silently accept GrabCut');
    check(count(glob($root . '/uploads/products/processed/*/master.webp')) === count($beforeFiles) + 1, 'rejected worker diagnostics remove unpublished output');
    $responseEdit = function ($data) { $data['normalization']['background_profile_requested'] = 'anabelka-brand'; return $data; };
    rejects(function () use ($service) { $service->create(7, 'studio-light', 'auto'); }, 'wrong requested background rejected');
    $responseEdit = function ($data) { $data['normalization']['worker_error'] = 'worker unavailable'; return $data; };
    $fallback = $service->create(7, 'studio-light', 'auto');
    check($fallback['processing']['normalization']['worker_error'] === 'worker unavailable', 'AUTO fallback preserves worker error while accepting GrabCut');
    rejects(function () use ($service) { $service->create(7, 'studio-light', 'modnet'); }, 'forced MODNet cannot accept worker error');
    $responseEdit = null;
    // Emscripten flock tracks paths and cannot unlock renamed parent directories.
    // Keep this real filesystem sabotage case runnable on native PHP.
    if (strpos(php_uname(), 'Emscripten') === false) {
        $stagingRow = ProductImageProcessing::find(7);
        $stagingFiles = glob($root . '/uploads/products/processed/*/master.webp');
        $responseEdit = function ($data) use ($root) {
            rename($root . '/storage/image-processor/previews', $root . '/storage/image-processor/previews-safe');
            file_put_contents($root . '/storage/image-processor/previews', 'blocked private directory');
            return $data;
        };
        rejects(function () use ($service) { $service->create(7, 'studio-light', 'auto'); }, 'private staging failure surfaces');
        unlink($root . '/storage/image-processor/previews');
        rename($root . '/storage/image-processor/previews-safe', $root . '/storage/image-processor/previews');
        $responseEdit = null;
        check(ProductImageProcessing::find(7)['job_id'] === $stagingRow['job_id'], 'staging failure preserves old ready row');
        check(glob($root . '/uploads/products/processed/*/master.webp') === $stagingFiles, 'staging failure cleans worker output');
    }
    $marker = $service->create(7, 'studio-light', 'auto');
    $markerDirectory = $root . '/storage/image-processor/previews/' . $marker['preview_id'];
    Database::$db->beforeCommit = function () use ($markerDirectory) {
        rename($markerDirectory . '/manifest.json', $markerDirectory . '/manifest-before-commit.json');
        mkdir($markerDirectory . '/manifest.json', 0700);
    };
    $oldLog = ini_get('error_log');
    ini_set('error_log', $root . '/expected-marker-error.log');
    $markerResult = $service->confirm(7, $marker['preview_id']);
    ini_set('error_log', $oldLog);
    Database::$db->beforeCommit = null;
    check(ProductImageProcessing::find(7)['job_id'] === $markerResult['job_id'], 'postcommit marker failure preserves ready DB row');
    check(is_file($root . '/' . $markerResult['master_path']), 'postcommit marker failure preserves final files');
    rmdir($markerDirectory . '/manifest.json');
    rename($markerDirectory . '/manifest-before-commit.json', $markerDirectory . '/manifest.json');
    $markerReplay = $service->confirm(7, $marker['preview_id']);
    check($markerReplay['job_id'] === $markerResult['job_id'], 'DB authority recovers replay after postcommit marker failure');
    $crash = $service->create(7, 'studio-light', 'auto');
    $crashDirectory = $root . '/storage/image-processor/previews/' . $crash['preview_id'];
    $manifest = json_decode(file_get_contents($crashDirectory . '/manifest.json'), true);
    $publication = $manifest['publication_id'];
    mkdir($root . '/uploads/products/processed/' . $publication, 0700);
    mkdir($root . '/storage/image-processor/originals/' . $publication, 0700);
    file_put_contents($root . '/uploads/products/processed/' . $publication . '/master.webp', 'interrupted bytes');
    copy($crashDirectory . '/original.bin', $root . '/storage/image-processor/originals/' . $publication . '/original.bin');
    $recovered = $service->confirm(7, $crash['preview_id']);
    check($recovered['master_path'] === 'uploads/products/processed/' . $publication . '/master.webp', 'retry recovers partial unaccepted publication after process crash');
    file_put_contents($root . '/' . $recovered['master_path'], 'altered accepted file');
    rejects(function () use ($service, $crash) { $service->confirm(7, $crash['preview_id']); }, 'replay rejects accepted files changed after confirmation');
    check(ProductImageProcessing::find(7)['job_id'] === $recovered['job_id'], 'failed replay never damages ready row');
    $clock += 1801;
    $service->cleanupExpired();
    check(is_file($root . '/' . $markerResult['master_path']), 'expiry after later acceptance preserves older accepted generation after marker failure');
    check(is_file($root . '/' . $markerResult['original_path']), 'marker failure cleanup preserves accepted original');
    check(is_file($root . '/' . $recovered['master_path']), 'expiry preserves latest accepted master');
    $existingDestination = $root . '/existing-copy-target.webp';
    file_put_contents($existingDestination, 'must survive failed staging copy');
    $copyMethod = new ReflectionMethod(ProductImagePreviewService::class, 'copyVerified');
    rejects(function () use ($copyMethod, $service, $root, $existingDestination) {
        $copyMethod->invoke($service, $root . '/uploads/products/source.jpg', $existingDestination, hash_file('sha256', $root . '/uploads/products/source.jpg'));
    }, 'exclusive staging copy rejects existing destination');
    check(file_get_contents($existingDestination) === 'must survive failed staging copy', 'failed staging copy never deletes preexisting file');
    unset(ProductImage::$images[7]);
    rejects(function () use ($service, $blocked) { $service->confirm(7, $blocked['preview_id']); }, 'deleted image cannot publish');
    echo "product image preview runtime passed\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $path) { $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname()); }
    rmdir($root);
}
