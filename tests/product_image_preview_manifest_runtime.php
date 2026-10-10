<?php
namespace PreviewManifestRuntime;

use RuntimeException;
use Throwable;
use ReflectionMethod;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

// Load the unchanged production source in a namespace solely to inject filesystem
// failures. Every file is real; only manifest streams reject LOCK_EX. Operation
// locks and staged image copies continue using the native filesystem functions.
class ManifestIo
{
    public static $handles = [];
    public static $writes = [];
    public static $events = [];
    public static $failure = '';
    public static $collision = false;
    public static $previousPath;
    public static $previousBytes;
    public static $warnings = [];

    public static function temporary($path) { return preg_match('#/manifest-[a-f0-9]{16}\.tmp$#', $path) === 1; }
    public static function handle($stream) { return isset(self::$handles[(int) $stream]); }
    public static function observe($event)
    {
        self::$events[] = $event;
        if (self::$previousPath !== null) {
            check(\file_get_contents(self::$previousPath) === self::$previousBytes, 'previous manifest remains intact before rename');
        }
    }
}

class ManifestStream
{
    public $context;
    private $stream;

    public function stream_open($path, $mode, $options, &$openedPath)
    {
        ManifestIo::observe('open:' . $mode);
        $this->stream = @\fopen(substr($path, strlen('manifest-io://')), $mode);
        return $this->stream !== false;
    }
    public function stream_lock($operation) { ManifestIo::$events[] = 'unsupported-lock'; return false; }
    public function stream_write($bytes) { return \fwrite($this->stream, $bytes); }
    public function stream_read($length) { return \fread($this->stream, $length); }
    public function stream_flush() { return \fflush($this->stream); }
    public function stream_close() { \fclose($this->stream); }
    public function stream_stat() { return \fstat($this->stream); }
    public function stream_eof() { return \feof($this->stream); }
    public function stream_tell() { return \ftell($this->stream); }
    public function stream_seek($offset, $whence) { return \fseek($this->stream, $offset, $whence) === 0; }
}
\stream_wrapper_register('manifest-io', ManifestStream::class);

function fopen($path, $mode)
{
    if (!ManifestIo::temporary($path)) { return \fopen($path, $mode); }
    $stream = \fopen('manifest-io://' . $path, $mode);
    if ($stream !== false) { ManifestIo::$handles[(int) $stream] = $path; }
    return $stream;
}
function file_put_contents($path, $bytes, $flags = 0)
{
    return \file_put_contents(ManifestIo::temporary($path) ? 'manifest-io://' . $path : $path, $bytes, $flags);
}
function fwrite($stream, $bytes)
{
    if (!ManifestIo::handle($stream)) { return \fwrite($stream, $bytes); }
    ManifestIo::observe('write');
    if (ManifestIo::$failure === 'write-exception') { throw new RuntimeException('injected write exception'); }
    $count = ManifestIo::$writes ? array_shift(ManifestIo::$writes) : strlen($bytes);
    if ($count === false || $count === 0) { return $count; }
    return \fwrite($stream, substr($bytes, 0, $count));
}
function fflush($stream)
{
    if (ManifestIo::handle($stream)) {
        ManifestIo::observe('flush');
        if (ManifestIo::$failure === 'flush-exception') { throw new RuntimeException('injected flush exception'); }
        if (ManifestIo::$failure === 'flush') { return false; }
    }
    return \fflush($stream);
}
function fclose($stream)
{
    $manifest = ManifestIo::handle($stream);
    if ($manifest) { ManifestIo::observe('close'); unset(ManifestIo::$handles[(int) $stream]); }
    $closed = \fclose($stream);
    // PHP ignores userland stream_close return values, so inject close failures
    // here after closing the real resource rather than claiming wrapper support.
    if ($manifest && in_array(ManifestIo::$failure, ['close-exception', 'cleanup-close-exception'], true)) { throw new RuntimeException('injected close exception'); }
    return $manifest && ManifestIo::$failure === 'close' ? false : $closed;
}
function chmod($path, $permissions)
{
    if (ManifestIo::temporary($path)) {
        ManifestIo::observe('chmod:' . decoct($permissions));
        if (ManifestIo::$failure === 'chmod') { return false; }
    }
    return \chmod($path, $permissions);
}
function rename($source, $destination)
{
    if (ManifestIo::temporary($source)) {
        ManifestIo::observe('rename');
        if (ManifestIo::$failure === 'rename-exception') { throw new RuntimeException('injected rename exception'); }
        if (ManifestIo::$failure === 'rename') { return false; }
    }
    return \rename($source, $destination);
}
function random_bytes($length) { return ManifestIo::$collision && $length === 8 ? str_repeat("\xab", 8) : \random_bytes($length); }
function check($condition, $message) { if (!$condition) { throw new RuntimeException('FAIL: ' . $message); } }
function rejects(callable $operation)
{
    try { $operation(); } catch (Throwable $error) {
        check(strpos($error->getMessage(), 'FAIL: ') !== 0, $error->getMessage());
        return;
    }
    throw new RuntimeException('FAIL: filesystem failure must surface');
}

class AdminAccess
{
    public static function currentId() { return 9; }
    public static function can($permission) { return $permission === 'products.manage'; }
}
class ProductImage
{
    public static function findById($id) { return $id === 7 ? ['id' => 7, 'product_id' => 3, 'path' => '/Anabelka/uploads/products/source.jpg'] : null; }
}
class ProductImageProcessing
{
    public static $row = ['status' => 'ready', 'job_id' => 'previous accepted job', 'master_path' => 'uploads/products/accepted.webp'];
    public static function ensureSchema() {}
    public static function find($id) { return self::$row; }
    public static function isJobAccepted($job) { return $job === self::$row['job_id']; }
}
require __DIR__ . '/../app/Services/ImageProcessorClient.php';
require __DIR__ . '/../app/Services/ProductImageProcessingService.php';
class_alias(\ImageProcessorClient::class, __NAMESPACE__ . '\\ImageProcessorClient');
class_alias(\ProductImageProcessingService::class, __NAMESPACE__ . '\\ProductImageProcessingService');
$source = \file_get_contents(__DIR__ . '/../app/Services/ProductImagePreviewService.php');
eval('namespace ' . __NAMESPACE__ . '; use \\RuntimeException; use \\InvalidArgumentException; use \\Throwable; use \\PDO; ' . substr($source, 5));
\set_error_handler(function ($severity, $message) {
    if (error_reporting() & $severity) { ManifestIo::$warnings[] = $message; }
    return true;
});

$scenario = $argv[1] ?? 'create';
$root = sys_get_temp_dir() . '/anabelka-manifest-test-' . bin2hex(\random_bytes(8));
\mkdir($root . '/uploads/products', 0700, true);
\file_put_contents($root . '/uploads/products/source.jpg', 'original photograph');
\file_put_contents($root . '/uploads/products/accepted.webp', 'previous accepted photograph');
$token = str_repeat('a', 64);
$directory = $root . '/storage/image-processor/previews/' . $token;
\mkdir($directory, 0700, true);
$manifestPath = $directory . '/manifest.json';
$previous = '{"preview_id":"' . $token . '","state":"ready","expires_at":999999}';
\file_put_contents($manifestPath, $previous);
\chmod($manifestPath, 0600);
ManifestIo::$previousPath = $manifestPath;
ManifestIo::$previousBytes = $previous;
$calls = 0;
$processor = function ($sourcePath, $background, $mode) use ($root, &$calls) {
    $calls++;
    $job = bin2hex(\random_bytes(16));
    $paths = ['original' => 'storage/image-processor/originals/' . $job . '/source.jpg',
        'master' => 'uploads/products/processed/' . $job . '/master.webp',
        'thumb' => 'uploads/products/processed/' . $job . '/thumb.webp'];
    \mkdir(dirname($root . '/' . $paths['original']), 0700, true);
    \mkdir(dirname($root . '/' . $paths['master']), 0700, true);
    \copy($root . '/' . $sourcePath, $root . '/' . $paths['original']);
    \file_put_contents($root . '/' . $paths['master'], 'new processed master');
    \file_put_contents($root . '/' . $paths['thumb'], 'new processed thumbnail');
    return ['ok' => true, 'source' => $sourcePath, 'job_id' => $job, 'processor_version' => '0.11',
        'profile' => 'model-normalize-v8', 'input' => ['width' => 200, 'height' => 300],
        'original' => ['path' => $paths['original'], 'sha256' => hash_file('sha256', $root . '/' . $paths['original']), 'bytes' => filesize($root . '/' . $paths['original'])],
        'master' => ['path' => $paths['master'], 'width' => 1200, 'height' => 1800, 'bytes' => filesize($root . '/' . $paths['master'])],
        'thumb' => ['path' => $paths['thumb'], 'width' => 320, 'height' => 480, 'bytes' => filesize($root . '/' . $paths['thumb'])],
        'normalization' => ['method' => 'standard-canvas-fallback', 'background_profile' => $background,
            'background_profile_requested' => $background, 'mask_mode_requested' => $mode,
            'mask_method' => 'opencv-grabcut', 'subject_mask_applied' => true, 'processor_version' => '0.11', 'worker_error' => null]];
};
session_id('manifest-runtime-session');
$service = new ProductImagePreviewService($root, $processor, function () { return 100000; });
$write = new ReflectionMethod(ProductImagePreviewService::class, 'writeManifest');
$manifest = ['preview_id' => $token, 'state' => 'cancelled', 'label' => 'Проба / фотографії'];

try {
    if ($scenario === 'create' || $scenario === 'create-failure') {
        // The test seed manifest is deliberately incomplete; it is unrelated to
        // create, which chooses a fresh token and keeps the previous ready row.
        \unlink($manifestPath); \rmdir($directory); ManifestIo::$previousPath = null;
        $previousRow = ProductImageProcessing::$row;
        if ($scenario === 'create-failure') { ManifestIo::$writes = [5, false]; rejects(function () use ($service) { $service->create(7, 'studio-light', 'grabcut'); }); }
        else {
            try { $result = $service->create(7, 'studio-light', 'grabcut'); }
            catch (Throwable $error) {
                throw new RuntimeException('FAIL: preview should succeed on streams without locks; ' . implode('; ', ManifestIo::$warnings), 0, $error);
            }
            check($result['success'] === true && $calls === 1, 'full preview creation returns success');
            $directory = $root . '/storage/image-processor/previews/' . $result['preview_id'];
            $stored = json_decode(\file_get_contents($directory . '/manifest.json'), true);
            check($stored['state'] === 'ready' && $stored['preview_id'] === $result['preview_id'], 'complete ready manifest is persisted');
            check(\file_get_contents($service->previewFile(7, $result['preview_id'])) === 'new processed master', 'created preview can be read');
            foreach (['manifest.json', 'original.bin', 'master.webp', 'thumb.webp'] as $name) {
                check((fileperms($directory . '/' . $name) & 0777) === 0600, 'staged files stay private');
            }
            check((fileperms($directory) & 0777) === 0700, 'preview directory stays private');
        }
        check(ProductImageProcessing::$row === $previousRow, 'preview leaves the previous ready row unchanged');
        check(\file_get_contents($root . '/uploads/products/accepted.webp') === 'previous accepted photograph', 'previous accepted file survives');
        check(glob($root . '/uploads/products/processed/*/*.webp') === [], 'worker public output is removed');
        check(glob($root . '/storage/image-processor/originals/*/*') === [], 'worker original output is removed');
        if ($scenario === 'create-failure') { check(glob($root . '/storage/image-processor/previews/*/manifest*') === [], 'failed preview leaves no manifest or temporary file'); }
        foreach (glob($root . '/storage/image-processor/previews/locks/*.lock') as $path) {
            $handle = \fopen($path, 'c+b');
            check(\flock($handle, LOCK_EX | LOCK_NB), 'operation locks are released');
            \flock($handle, LOCK_UN); \fclose($handle);
        }
    } else {
        if ($scenario === 'partial') { ManifestIo::$writes = [1, 2, 3, 7]; }
        elseif ($scenario === 'zero') { ManifestIo::$writes = [0]; }
        elseif ($scenario === 'false') { ManifestIo::$writes = [false]; }
        elseif ($scenario === 'partial-zero') { ManifestIo::$writes = [5, 0]; }
        elseif ($scenario === 'cleanup-close-exception') { ManifestIo::$writes = [5, false]; ManifestIo::$failure = $scenario; }
        elseif ($scenario === 'collision') {
            ManifestIo::$collision = true;
            \file_put_contents($directory . '/manifest-' . str_repeat('ab', 8) . '.tmp', 'preexisting file');
        } elseif ($scenario === 'json') { $manifest['label'] = "\xff"; }
        else { ManifestIo::$failure = $scenario; }

        if ($scenario === 'partial') {
            $write->invoke($service, $token, $manifest);
            check(json_decode(\file_get_contents($manifestPath), true) === $manifest, 'all JSON bytes are persisted');
            check((fileperms($manifestPath) & 0777) === 0600, 'replacement manifest permissions are 0600');
            check(count(array_filter(ManifestIo::$events, function ($event) { return $event === 'write'; })) >= 5, 'short writes are retried');
            check(array_slice(ManifestIo::$events, -3) === ['flush', 'close', 'rename'], 'flush and close finish before atomic rename');
        } else {
            rejects(function () use ($write, $service, $token, $manifest) { $write->invoke($service, $token, $manifest); });
            check(\file_get_contents($manifestPath) === $previous, 'failed replacement keeps the previous manifest');
            if ($scenario === 'collision') {
                $collision = $directory . '/manifest-' . str_repeat('ab', 8) . '.tmp';
                check(\file_get_contents($collision) === 'preexisting file', 'exclusive open failure preserves unrelated temporary file');
                \unlink($collision);
            }
        }
    }
    check(ManifestIo::$handles === [], 'manifest handles are closed');
    check(glob($directory . '/manifest-*.tmp') === [], 'owned temporary files are removed');
    check(!in_array('unsupported-lock', ManifestIo::$events, true), 'manifest writing never requests stream locks');
    if ($scenario !== 'json') { check(in_array('open:x+b', ManifestIo::$events, true), 'temporary manifest uses exclusive binary creation'); }
    check(ManifestIo::$warnings === [], 'successful regression checks produce no warnings');
    echo $scenario . " passed\n";
} finally {
    \restore_error_handler();
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $path) { $path->isDir() ? \rmdir($path->getPathname()) : \unlink($path->getPathname()); }
    \rmdir($root);
}
