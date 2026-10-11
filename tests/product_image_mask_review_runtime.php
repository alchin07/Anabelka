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
$processorError = null;
$processor = function ($source, $background, $mode) use ($root, &$calls, &$responseEdit, &$processorError) {
    $calls++;
    if ($processorError) { throw $processorError; }
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

// Exercise the production private review state machine; only the worker and DB are substituted.
function reviewPng($shade) {
    $chunk = function ($type, $data) { return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data)); };
    $row = "\0" . str_repeat(chr($shade), 100);
    return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 100,100,8,0,0,0,0)) . $chunk('IDAT', gzcompress(str_repeat($row,100))) . $chunk('IEND','');
}
$ordinary = $processor;
$states = [];
$qualityRejected = false;
$failReview = false;
$mutateSource = false;
$reviewCalls = 0;
$corruptInitialHash = false;
$gateRefused = false;
$reviewProcessor = function ($source, $background, $mode, $allowReview) use ($root, $ordinary, &$states, &$qualityRejected, &$mutateSource, &$corruptInitialHash) {
    check($allowReview === true, 'preview explicitly asks for private mask review');
    $response = $qualityRejected ? ['ok'=>true,'state'=>'needs_review','job_id'=>bin2hex(random_bytes(16)),'input'=>['width'=>100,'height'=>100]] : $ordinary($source,$background,$mode);
    $job = $response['job_id'];
    $dir = $root . '/storage/image-processor/reviews/' . $job;
    mkdir($dir,0700,true);
    file_put_contents($dir.'/source.png',reviewPng(100));
    file_put_contents($dir.'/raw-alpha.png',reviewPng(255));
    copy($dir.'/raw-alpha.png',$dir.'/alpha-0.png');
    $response['source_sha256']=hash_file('sha256',$root.'/'.$source);
    $response['review']=['revision'=>0,'status'=>$qualityRejected?'needs_review':'accepted','source_width'=>100,'source_height'=>100,'reasons'=>['mask_quality_rejected'],
       'regions'=>[['reason'=>'local_uncertainty','bbox'=>[20,20,5,5]]],
       'gates'=>array_fill_keys(['component','local','thin','background'],['accepted'=>true,'reasons'=>[]]),
       'raw_alpha_sha256'=>hash_file('sha256',$dir.'/raw-alpha.png'),'source_image_sha256'=>hash_file('sha256',$dir.'/source.png'),'alpha_sha256'=>hash_file('sha256',$dir.'/alpha-0.png')];
    $states[$job]=$response;
    if ($corruptInitialHash) { $response['review']['raw_alpha_sha256']=str_repeat('a',64); }
    if ($mutateSource) { file_put_contents($root.'/'.$source,'changed during worker'); }
    return $response;
};
$reviewWorker = function ($operation,$payload) use ($root,$ordinary,&$states,&$failReview,&$reviewCalls,&$gateRefused) {
    $reviewCalls++;
    if ($failReview) { throw new ImageProcessorException('Worker leaked /private/weights/model.onnx', ['worker_error'=>'/private/weights/model.onnx']); }
    $state=$states[$payload['job_id']];
    if ($operation==='revise') {
       check($payload['revision']===$state['review']['revision'],'worker receives current revision');
       $revision=$payload['revision']+1;
       $dir=$root.'/storage/image-processor/reviews/'.$payload['job_id'];
       file_put_contents($dir.'/alpha-'.$revision.'.png',reviewPng(255-$revision));
       $state['review']['revision']=$revision;
       $state['review']['status']='accepted';
       $state['review']['alpha_sha256']=hash_file('sha256',$dir.'/alpha-'.$revision.'.png');
       $states[$payload['job_id']]=$state;
       return $state;
    }
    $response=$ordinary('uploads/products/source.jpg',$payload['background_profile'],'auto');
    $response['source_sha256']=$state['source_sha256'];
    $response['review']=$state['review'];
    $response['review_job_id']=$payload['job_id'];
    if ($gateRefused) { $response['review']['gates']['thin']['accepted']=false; }
    return $response;
};
$service=new ProductImagePreviewService($root,$reviewProcessor,function () use (&$clock) {return $clock;},$reviewWorker);
$action=[['kind'=>'background','tool'=>'brush','radius'=>2,'hardness'=>.5,'points'=>[[22,22],[23,23]]]];
try {
    $ready=$service->create(7,'studio-light','auto');
    check($ready['state']==='ready' && $ready['review_available'],'accepted preview retains editable raw mask');
    $started=$service->reviewStart(7,$ready['preview_id']);
    check($started['state']==='needs_review' && $started['review']['width']===100,'start returns source geometry and invalidates old result');
    check(strpos(json_encode($started),'/storage/')===false,'no worker filesystem paths exposed');
    rejects(fn()=>$service->confirm(7,$ready['preview_id']),'old confirm blocked while editor open');
    $sourcePath=$service->reviewFile(7,$ready['preview_id'],0,'source');
    $rawHash=hash_file('sha256',$sourcePath);
    $edited=$service->reviewEdit(7,$ready['preview_id'],0,json_encode($action));
    check($edited['state']==='needs_review' && $edited['revision']===1,'revision never publishes even accepted worker mask');
    check(Database::$db->rows===[],'review start/revise leaves DB unchanged');
    check(hash_file('sha256',$sourcePath)===$rawHash,'source PNG immutable');
    rejects(fn()=>$service->reviewEdit(7,$ready['preview_id'],0,$action),'replayed revision rejected');
    rejects(fn()=>$service->reviewFile(7,$ready['preview_id'],0,'alpha'),'stale file revision rejected');
    $beforeCalls=$reviewCalls;
    foreach (['{}', '{"points":[]}', [["kind"=>"background","tool"=>"point","radius"=>2,"hardness"=>true,"points"=>[[2,2]]]], [['kind'=>'background','tool'=>'brush','radius'=>81,'points'=>[[2,2]]]], [['kind'=>'background','tool'=>'point','radius'=>2,'points'=>[[100,2]]]], [['kind'=>'background','tool'=>'point','radius'=>2,'points'=>[[2.5,2]]]], [['kind'=>'background','tool'=>'point','radius'=>2,'points'=>[[2,2]],'path'=>'/etc/passwd']]] as $bad) {
       rejects(fn()=>$service->reviewEdit(7,$ready['preview_id'],1,$bad),'invalid action rejected');
    }
    check($reviewCalls===$beforeCalls,'invalid actions never reach worker');
    $job=basename(dirname($sourcePath));
    $duplicateService=new ProductImagePreviewService($root,function() use (&$states,$job) {return $states[$job];},function() use (&$clock) {return $clock;},$reviewWorker);
    rejects(fn()=>$duplicateService->create(7,'studio-light','auto'),'worker job cannot be reused by another private session');
    check(is_file($sourcePath),'failed duplicate claim never deletes another session source');
    AdminAccess::$id=10;
    rejects(fn()=>$service->reviewEdit(7,$ready['preview_id'],1,$action),'cross-owner revisions denied');
    rejects(fn()=>$service->cancel(7,$ready['preview_id']),'cross-owner cancel denied');
    AdminAccess::$id=9;
    session_id('other-review-session');
    rejects(fn()=>$service->reviewFile(7,$ready['preview_id'],1,'source'),'cross-session source denied');
    session_id('test-preview-session');
    AdminAccess::$allowed=false;
    rejects(fn()=>$service->reviewStart(7,$ready['preview_id']),'view-only owner denied');
    AdminAccess::$allowed=true;
    rejects(fn()=>$service->reviewResult(8,$ready['preview_id'],1),'cross-image result denied');
    file_put_contents($root.'/uploads/products/source.jpg','stale JPEG');
    rejects(fn()=>$service->reviewResult(7,$ready['preview_id'],1),'changed source denied');
    file_put_contents($root.'/uploads/products/source.jpg','original photograph');
    $failReview=true;
    rejects(fn()=>$service->reviewResult(7,$ready['preview_id'],1),'worker failure retains private review without publication');
    $failReview=false;
    check(Database::$db->rows===[] && glob($root.'/uploads/products/processed/*/*')===[],'worker failure has no published effect');
    $gateRefused=true;
    $refused=$service->reviewResult(7,$ready['preview_id'],1);
    check($refused['state']==='needs_review','independent thin gate refusal overrides accepted status');
    check(glob($root.'/uploads/products/processed/*/*')===[],'refused gate cleans unpublished worker files');
    $gateRefused=false;
    $result=$service->reviewResult(7,$ready['preview_id'],1);
    check($result['state']==='ready' && $result['revision']===1,'explicit result builds ordinary private preview');
    rejects(fn()=>$service->confirm(7,$ready['preview_id']),'reviewed confirm requires revision');
    rejects(fn()=>$service->confirm(7,$ready['preview_id'],0),'stale result cannot confirm');
    $accepted=$service->confirm(7,$ready['preview_id'],1);
    check($accepted['status']==='ready','separate confirm publishes approved preview');
    check($service->confirm(7,$ready['preview_id'],1)['job_id']===$accepted['job_id'],'confirmed replay idempotent');
    $acceptedHash=hash_file('sha256',$root.'/'.$accepted['master_path']);
    $qualityRejected=true;
    $beforeReviewDirs=glob($root.'/storage/image-processor/reviews/*',GLOB_ONLYDIR);
    $corruptInitialHash=true;
    rejects(fn()=>$service->create(7,'studio-light','auto'),'invalid initial raw alpha rejects claimed private session');
    $corruptInitialHash=false;
    check(glob($root.'/storage/image-processor/reviews/*',GLOB_ONLYDIR)===$beforeReviewDirs,'invalid claimed artifacts are removed without orphans');
    $rejected=$service->create(7,'studio-light','auto');
    check($rejected['success'] && $rejected['state']==='needs_review' && !isset($rejected['preview_url']),'quality failure offers private review only');
    rejects(fn()=>$service->confirm(7,$rejected['preview_id'],0),'needs_review never confirms');
    check(hash_file('sha256',$root.'/'.$accepted['master_path'])===$acceptedHash,'quality failure preserves current publication');
    $service->reviewStart(7,$rejected['preview_id']);
    $reset=$service->reviewEdit(7,$rejected['preview_id'],0,[]);
    check($reset['revision']===1,'empty cumulative action log restores immutable raw mask');
    $jobDir=dirname($service->reviewFile(7,$rejected['preview_id'],1,'source'));
    $service->cancel(7,$rejected['preview_id']);
    check(!is_dir($jobDir),'cancel removes owned review artifacts');
    check(is_file($root.'/'.$accepted['master_path']),'cancel preserves accepted result');
    $expired=$service->create(7,'studio-light','auto');
    $clock+=1801;
    rejects(fn()=>$service->reviewFile(7,$expired['preview_id'],0,'source'),'expired review denied');
    $service->cleanupExpired();
    check(!is_dir($root.'/storage/image-processor/previews/'.$expired['preview_id']),'expired session temporary storage removed');
    $mutateSource=true;
    rejects(fn()=>$service->create(7,'studio-light','auto'),'source changed during quality worker denied');
    $mutateSource=false;
    file_put_contents($root.'/uploads/products/source.jpg','original photograph');
    $tokens=[];
    for($i=0;$i<4;$i++) { $tokens[]=$service->create(7,'studio-light','auto')['preview_id']; }
    rejects(fn()=>$service->create(7,'studio-light','auto'),'active owner review quota enforced');
    $quotaFile=$root.'/storage/image-processor/previews/'.$tokens[0].'/manifest.json';
    $quotaManifest=json_decode(file_get_contents($quotaFile),true);
    $quotaManifest['worker_attempts']=64;
    file_put_contents($quotaFile,json_encode($quotaManifest));
    $service->reviewStart(7,$tokens[0]);
    $beforeCalls=$reviewCalls;
    rejects(fn()=>$service->reviewEdit(7,$tokens[0],0,$action),'worker task attempts quota enforced');
    check($beforeCalls===$reviewCalls,'exhausted task quota never calls worker');
    foreach($tokens as $token) { $service->cancel(7,$token); }
    $globalTokens=[];
    for($i=0;$i<24;$i++) { AdminAccess::$id=100+$i; $globalTokens[AdminAccess::$id]=$service->create(7,'studio-light','auto')['preview_id']; }
    AdminAccess::$id=999;
    rejects(fn()=>$service->create(7,'studio-light','auto'),'global active review quota enforced across owners');
    foreach($globalTokens as $ownerId=>$token) { AdminAccess::$id=$ownerId; $service->cancel(7,$token); }
    AdminAccess::$id=9;
    echo "product image mask review runtime passed\n";
} finally {
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($iterator as $path) { $path->isDir()?rmdir($path->getPathname()):unlink($path->getPathname()); }
    rmdir($root);
}
