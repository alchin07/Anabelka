<?php
// Each invocation is a separate native PHP process. The real preview service,
// model and flock calls run against one shared root. Only DB/worker are fakes.
function concurrencyCheck($condition, $message)
{
    if (!$condition) { throw new RuntimeException($message); }
}

function concurrencyProcessId()
{
    // /proc may be mounted from an outer PID namespace. Its Pid field gives
    // the identifier whose wait channel the Node driver can inspect.
    if (PHP_OS_FAMILY !== 'Linux' || strpos(php_uname(), 'Emscripten') !== false
        || !is_readable('/proc/self/status') || !is_readable('/proc/self/wchan')) { return null; }
    preg_match('/^Pid:\s*(\d+)/m', file_get_contents('/proc/self/status'), $match);
    return isset($match[1]) ? (int) $match[1] : null;
}

function concurrencyGate($name)
{
    $root = Database::$db->root;
    file_put_contents($root . '/' . $name . '-waiting', '1');
    $deadline = microtime(true) + 10;
    do {
        clearstatcache(true, $root . '/' . $name . '-release');
        if (is_file($root . '/' . $name . '-release')) { return; }
        usleep(1000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Timed out at concurrency gate ' . $name);
}

class AdminAccess
{
    public static function currentId() { return 9; }
    public static function can($permission) { return $permission === 'products.manage'; }
}

class ProductImage
{
    public static function ensureTable() {}
    public static function findById($id)
    {
        return $id === 7 ? ['id' => 7, 'product_id' => 3, 'path' => '/Anabelka/uploads/products/source.jpg'] : null;
    }
}

class ConcurrencyStatement
{
    private $sql;
    private $data;
    public function __construct($sql) { $this->sql = $sql; }
    public function execute($data = [])
    {
        $this->data = $data;
        if (strpos($this->sql, 'INSERT INTO product_image_processing') !== false) {
            $db = Database::$db;
            concurrencyCheck($db->inTransaction(), 'Ready save must be transactional');
            if ($db->role === 'first') { concurrencyGate('first-save'); }
            $db->rows[$data['image_id']] = array_merge($data, ['status' => 'ready', 'processed_at' => $data['job_id']]);
            file_put_contents($db->root . '/' . $db->role . '-saved', '1');
        }
        return true;
    }
    public function fetch($mode = null)
    {
        if (strpos($this->sql, 'product_gallery_images') !== false) {
            return ProductImage::findById((int) $this->data['image_id']) ?: false;
        }
        $rows = Database::$db->readRows();
        return $rows[$this->data['image_id']] ?? false;
    }
    public function fetchColumn()
    {
        foreach (Database::$db->readRows() as $row) {
            if ($row['job_id'] === $this->data['job_id'] && $row['status'] === 'ready') { return 1; }
        }
        return false;
    }
}

class ConcurrencyDatabase
{
    public $root;
    public $role;
    public $scenario;
    public $rows = null;
    public function __construct($root, $role, $scenario)
    {
        $this->root = $root;
        $this->role = $role;
        $this->scenario = $scenario;
    }
    public function prepare($sql) { return new ConcurrencyStatement($sql); }
    public function readRows()
    {
        return $this->rows ?? json_decode(file_get_contents($this->root . '/database.json'), true, 512, JSON_THROW_ON_ERROR);
    }
    public function beginTransaction()
    {
        // No fake DB/row lock: removing the service image lock must expose the race.
        $this->rows = $this->readRows();
        file_put_contents($this->root . '/' . $this->role . '-began', '1');
        if ($this->role === 'second' && $this->scenario === 'rollback') { concurrencyGate('second-begin'); }
        return true;
    }
    public function commit()
    {
        if ($this->role === 'first' && $this->scenario === 'rollback') {
            throw new RuntimeException('injected competing confirm commit failure');
        }
        $temporary = $this->root . '/database-' . $this->role . '.tmp';
        file_put_contents($temporary, json_encode($this->rows, JSON_THROW_ON_ERROR));
        concurrencyCheck(rename($temporary, $this->root . '/database.json'), 'Fake DB commit failed');
        $this->rows = null;
        return true;
    }
    public function inTransaction() { return $this->rows !== null; }
    public function rollBack() { $this->rows = null; return true; }
}

class Database
{
    public static $db;
    public static function connect() { return self::$db; }
}

$action = $argv[1] ?? '';
$root = $argv[2] ?? '';
$role = $argv[3] ?? 'setup';
$scenario = $argv[4] ?? 'success';
Database::$db = new ConcurrencyDatabase($root, $role, $scenario);
require __DIR__ . '/../app/Models/ProductImageProcessing.php';
(new ReflectionProperty(ProductImageProcessing::class, 'schemaReady'))->setValue(null, true);
require __DIR__ . '/../app/Services/ProductImageProcessingService.php';
require __DIR__ . '/../app/Services/ImageProcessorClient.php';
require __DIR__ . '/../app/Services/ProductImagePreviewService.php';
session_id('preview-concurrency-session');

$processor = function ($source, $background, $mode) use ($root) {
    $job = bin2hex(random_bytes(16));
    $original = 'storage/image-processor/originals/' . $job . '/source.jpg';
    $master = 'uploads/products/processed/' . $job . '/master.webp';
    $thumb = 'uploads/products/processed/' . $job . '/thumb.webp';
    mkdir(dirname($root . '/' . $original), 0700, true);
    mkdir(dirname($root . '/' . $master), 0700, true);
    copy($root . '/' . $source, $root . '/' . $original);
    file_put_contents($root . '/' . $master, 'preview master ' . $job);
    file_put_contents($root . '/' . $thumb, 'preview thumb ' . $job);
    return [
        'ok' => true, 'source' => $source, 'job_id' => $job,
        'processor_version' => '0.11', 'profile' => 'model-normalize-v8',
        'original' => ['path' => $original, 'sha256' => hash_file('sha256', $root . '/' . $original), 'bytes' => filesize($root . '/' . $original)],
        'input' => ['width' => 200, 'height' => 300],
        'master' => ['path' => $master, 'width' => 1200, 'height' => 1800, 'bytes' => filesize($root . '/' . $master)],
        'thumb' => ['path' => $thumb, 'width' => 320, 'height' => 480, 'bytes' => filesize($root . '/' . $thumb)],
        'normalization' => ['method' => 'standard-canvas-fallback', 'background_profile' => $background,
            'background_profile_requested' => $background, 'mask_mode_requested' => $mode,
            'mask_method' => 'opencv-grabcut', 'subject_mask_applied' => true,
            'processor_version' => '0.11', 'worker_error' => null]
    ];
};
$service = new ProductImagePreviewService($root, $processor, function () { return 100000; });

function concurrencyPreview($service, $root)
{
    $preview = $service->create(7, 'studio-light', 'grabcut');
    $manifest = json_decode(file_get_contents($root . '/storage/image-processor/previews/' . $preview['preview_id'] . '/manifest.json'), true);
    $publication = $manifest['publication_id'];
    return ['token' => $preview['preview_id'], 'job_id' => $manifest['data']['job_id'],
        'paths' => ['original' => 'storage/image-processor/originals/' . $publication . '/original.bin',
            'master' => 'uploads/products/processed/' . $publication . '/master.webp',
            'thumb' => 'uploads/products/processed/' . $publication . '/thumb.webp'],
        'hashes' => array_map(function ($file) { return $file['sha256']; }, $manifest['files'])];
}

if ($action === 'setup') {
    mkdir($root . '/uploads/products', 0700, true);
    file_put_contents($root . '/uploads/products/source.jpg', 'original photograph');
    file_put_contents($root . '/database.json', '[]');
    $previous = concurrencyPreview($service, $root);
    $service->confirm(7, $previous['token']);
    $first = concurrencyPreview($service, $root);
    do {
        $second = concurrencyPreview($service, $root);
        // The distinct stripe proves only the second process can own its token lock.
        if ($first['token'][0] === $second['token'][0]) { $service->cancel(7, $second['token']); }
    } while ($first['token'][0] === $second['token'][0]);
    echo json_encode(['previous' => $previous, 'first' => $first, 'second' => $second,
        'kernel_wait_observable' => concurrencyProcessId() !== null], JSON_THROW_ON_ERROR) . "\n";
} elseif ($action === 'probe-token') {
    $method = new ReflectionMethod(ProductImagePreviewService::class, 'lock');
    $lock = $method->invoke($service, 'token-' . $argv[5], false);
    if ($lock) { (new ReflectionMethod(ProductImagePreviewService::class, 'unlock'))->invoke($service, $lock); }
    echo $lock ? "free\n" : "held\n";
} elseif ($action === 'confirm') {
    file_put_contents($root . '/' . $role . '-started', json_encode(['pid' => concurrencyProcessId()]));
    try {
        $result = $service->confirm(7, $argv[5]);
        echo json_encode(['success' => true, 'result' => $result], JSON_THROW_ON_ERROR) . "\n";
    } catch (Throwable $error) {
        echo json_encode(['success' => false, 'class' => get_class($error), 'message' => $error->getMessage()], JSON_THROW_ON_ERROR) . "\n";
    }
} else {
    throw new RuntimeException('Unknown concurrency fixture action');
}
