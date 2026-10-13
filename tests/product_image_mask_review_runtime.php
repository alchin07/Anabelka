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
$discardUnavailable = false;
$discardBusy = false;
$discardCalls = 0;
$actionStatus = 'accepted';
$workerReviewEdit = null;
$workerResultEdit = null;
$loseReviseResponse = false;
$initialRegionCount = 1;
$initialGateRefused = false;
$legacyWorkerSnapshot = false;
$workerRejectJournal = null;
$workerRejectionProofEdit = null;
$initialRegionsOverride = null;
$reviewProcessor = function ($source, $background, $mode, $allowReview) use ($root, $ordinary, &$states, &$qualityRejected, &$mutateSource, &$corruptInitialHash, &$initialRegionCount, &$initialGateRefused, &$legacyWorkerSnapshot, &$initialRegionsOverride) {
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
       'regions'=>[['reason'=>'local_uncertainty','bbox'=>[20,20,5,5]]], 'initial_region_count'=>$initialRegionCount, 'actions'=>[], 'action_results'=>[],
       'gates'=>array_fill_keys(['component','local','thin','background'],['accepted'=>true,'reasons'=>[]]),
       'raw_alpha_sha256'=>hash_file('sha256',$dir.'/raw-alpha.png'),'source_image_sha256'=>hash_file('sha256',$dir.'/source.png'),'alpha_sha256'=>hash_file('sha256',$dir.'/alpha-0.png')];
    if ($initialGateRefused) { $response['review']['gates']['thin']['accepted']=false; }
    if ($initialRegionsOverride!==null) { $response['review']['regions']=$initialRegionsOverride; }
    $states[$job]=$response;
    if ($corruptInitialHash) { $response['review']['raw_alpha_sha256']=str_repeat('a',64); }
    if ($mutateSource) { file_put_contents($root.'/'.$source,'changed during worker'); }
    if ($legacyWorkerSnapshot) { unset($response['review']['actions'],$response['review']['action_results']); }
    return $response;
};
$reviewWorker = function ($operation,$payload) use ($root,$ordinary,&$states,&$failReview,&$reviewCalls,&$gateRefused,&$discardUnavailable,&$discardBusy,&$discardCalls,&$actionStatus,&$workerReviewEdit,&$workerResultEdit,&$loseReviseResponse,&$legacyWorkerSnapshot,&$workerRejectJournal,&$workerRejectionProofEdit) {
    if ($operation==='discard') {
       $discardCalls++;
       if ($discardUnavailable) { throw new RuntimeException('worker unavailable'); }
       if ($discardBusy) { return ['ok'=>true,'removed'=>false]; }
       $dir=$root.'/storage/image-processor/reviews/'.$payload['job_id'];
       if (!is_dir($dir)) { return ['ok'=>true,'removed'=>true]; }
       $claim=json_decode(file_get_contents($dir.'/php-owner.json'),true);
       if (($claim['preview_id'] ?? null)!==$payload['preview_id']) { throw new RuntimeException('different review owner'); }
       foreach (glob($dir.'/*') ?: [] as $file) { unlink($file); }
       rmdir($dir);
       return ['ok'=>true,'removed'=>true];
    }
    $reviewCalls++;
    if ($failReview) { throw new ImageProcessorException('Worker leaked /private/weights/model.onnx', ['worker_error'=>'/private/weights/model.onnx']); }
    $state=$states[$payload['job_id']];
    if ($operation==='revise') {
       if ($workerRejectJournal!==null && $payload['actions']==$workerRejectJournal) {
          $proof=['review_edit_rejected'=>true,'job_id'=>$payload['job_id'],'source_sha256'=>$payload['source_sha256'],'revision'=>$state['review']['revision']];
          if ($workerRejectionProofEdit) { $proof=$workerRejectionProofEdit($proof); }
          throw new ImageProcessorException('annotation_too_large',$proof);
       }
       if ($payload['revision']+1===$state['review']['revision'] && $payload['actions']===$state['review']['actions']) { return $state; }
       check($payload['revision']===$state['review']['revision'],'worker receives current revision');
       $revision=$payload['revision']+1;
       $dir=$root.'/storage/image-processor/reviews/'.$payload['job_id'];
       file_put_contents($dir.'/alpha-'.$revision.'.png',reviewPng(255-$revision));
       $state['review']['revision']=$revision;
       $state['review']['status']='accepted';
       $state['review']['actions']=$payload['actions'];
       $state['review']['action_results']=array_map(function($index) use ($actionStatus) {
          return ['index'=>$index,'status'=>$actionStatus,'reasons'=>$actionStatus==='unresolved'?['annotation_unsure']:[],
             'conflicts'=>[['reason'=>'immutable_material_loss','bbox'=>[20,20,5,5],'polygon'=>[[20,20],[24,20],[24,24]],'pixels'=>25,'private_path'=>'/private/model.onnx']],
             'private_path'=>'/private/model.onnx'];
       },array_keys($payload['actions']));
       $state['review']['regions']=[];
       $state['review']['alpha_sha256']=hash_file('sha256',$dir.'/alpha-'.$revision.'.png');
       $states[$payload['job_id']]=$state;
       if ($loseReviseResponse) { throw new ImageProcessorException('Network response lost after private revision commit'); }
       if ($workerReviewEdit) { return $workerReviewEdit($state); }
       if ($legacyWorkerSnapshot) { unset($state['review']['actions'],$state['review']['action_results']); }
       return $state;
    }
    $response=$ordinary('uploads/products/source.jpg',$payload['background_profile'],'auto');
    $response['source_sha256']=$state['source_sha256'];
    $response['review']=$state['review'];
    $response['review_job_id']=$payload['job_id'];
    if ($gateRefused) { $response['review']['gates']['thin']['accepted']=false; }
    if ($workerResultEdit) { return $workerResultEdit($response); }
    if ($legacyWorkerSnapshot) { unset($response['review']['actions'],$response['review']['action_results']); }
    return $response;
};
$service=new ProductImagePreviewService($root,$reviewProcessor,function () use (&$clock) {return $clock;},$reviewWorker);
$lockRoot=(new ReflectionProperty(ProductImagePreviewService::class,'lockDirectory'))->getValue($service);
$action=[['kind'=>'background','tool'=>'brush','radius'=>2,'hardness'=>.5,'points'=>[[22,22],[23,23]]]];
try {
    $initialGateRefused=true;
    rejects(fn()=>$service->create(7,'studio-light','auto'),'aggregate accepted initial mask cannot bypass an independent source gate');
    $initialGateRefused=false;
    $ready=$service->create(7,'studio-light','auto');
    check($ready['state']==='ready' && $ready['review_available'],'accepted preview retains editable raw mask');
    $started=$service->reviewStart(7,$ready['preview_id']);
    check($started['state']==='needs_review' && $started['review']['width']===100,'start returns source geometry and invalidates old result');
    check(($started['review']['actions'] ?? null)===[] && ($started['review']['action_results'] ?? null)===[],'initial snapshot exposes the canonical empty action log and accounting');
    check(($started['review']['initial_region_count'] ?? null)===1,'initial snapshot retains immutable defect count');
    check(($started['review']['remaining_region_count'] ?? null)===1,'initial snapshot retains the full remaining defect count');
    check(strpos(json_encode($started),'/storage/')===false,'no worker filesystem paths exposed');
    rejects(fn()=>$service->confirm(7,$ready['preview_id']),'old confirm blocked while editor open');
    $sourcePath=$service->reviewFile(7,$ready['preview_id'],0,'source');
    $rawHash=hash_file('sha256',$sourcePath);
    $rawAlphaPath=$service->reviewFile(7,$ready['preview_id'],0,'raw-alpha');
    $rawAlphaHash=hash_file('sha256',$rawAlphaPath);
    check(str_contains($started['review']['raw_alpha_url'] ?? '', '&asset=raw-alpha'),'review snapshot supplies an authenticated immutable raw-alpha URL');
    $edited=$service->reviewEdit(7,$ready['preview_id'],0,json_encode($action));
    check($edited['state']==='needs_review' && $edited['revision']===1,'revision never publishes even accepted worker mask');
    check(($edited['review']['actions'][0]['kind'] ?? null)==='background' && ($edited['review']['action_results'][0]['status'] ?? null)==='accepted','revised snapshot exposes canonical action accounting');
    check(($edited['review']['initial_region_count'] ?? null)===1 && $edited['review']['regions']===[],'resolved defects disappear without changing the initial defect count');
    check(($edited['review']['remaining_region_count'] ?? null)===0,'resolved snapshot has no remaining defect regions');
    check(!str_contains(json_encode($edited),'private') && !str_contains(json_encode($edited),'pixels'),'action metadata exposes only safe conflict geometry');
    check(Database::$db->rows===[],'review start/revise leaves DB unchanged');
    check(hash_file('sha256',$sourcePath)===$rawHash,'source PNG immutable');
    check($service->reviewFile(7,$ready['preview_id'],1,'raw-alpha')===$rawAlphaPath && hash_file('sha256',$rawAlphaPath)===$rawAlphaHash,'current revision can retrieve the immutable raw mask for replay');
    rejects(fn()=>$service->reviewFile(7,$ready['preview_id'],0,'raw-alpha'),'stale revision cannot retrieve immutable raw mask');
    $manifestFile=$root.'/storage/image-processor/previews/'.$ready['preview_id'].'/manifest.json';
    $manifestBeforeRetry=json_decode(file_get_contents($manifestFile),true);
    $beforeCalls=$reviewCalls;
    check($service->reviewEdit(7,$ready['preview_id'],1,$action)===$edited,'unchanged current log returns the same cached revision');
    check($service->reviewEdit(7,$ready['preview_id'],0,$action)===$edited,'identical immediate-parent retry returns the committed revision');
    check($reviewCalls===$beforeCalls && json_decode(file_get_contents($manifestFile),true)['worker_attempts']===$manifestBeforeRetry['worker_attempts'],'duplicate edits neither call worker nor consume task quota');
    $quotaManifest=$manifestBeforeRetry;
    $quotaManifest['worker_attempts']=64;
    file_put_contents($manifestFile,json_encode($quotaManifest));
    check($service->reviewEdit(7,$ready['preview_id'],1,$action)===$edited,'cached duplicate remains available after worker task quota is exhausted');
    file_put_contents($manifestFile,json_encode($manifestBeforeRetry));
    $changedAction=$action;
    $changedAction[0]['points']=[[30,30]];
    $preUpgradeManifest=$manifestBeforeRetry;
    unset($preUpgradeManifest['review']['actions'],$preUpgradeManifest['review']['action_results'],$preUpgradeManifest['review']['accounting_required']);
    file_put_contents($manifestFile,json_encode($preUpgradeManifest));
    $workerStateBeforeLegacy=$states[basename(dirname($sourcePath))];
    $beforeCalls=$reviewCalls;
    rejects(fn()=>$service->reviewStart(7,$ready['preview_id']),'pre-update revised session without a canonical journal cannot be reopened as empty');
    rejects(fn()=>$service->reviewEdit(7,$ready['preview_id'],1,$changedAction),'pre-update revised session cannot silently replace its unknown cumulative journal');
    check(json_decode(file_get_contents($manifestFile),true)===$preUpgradeManifest && $states[basename(dirname($sourcePath))]===$workerStateBeforeLegacy && $reviewCalls===$beforeCalls,'legacy refusal preserves both private snapshots and avoids worker mutations');
    check(is_file($sourcePath) && is_file($rawAlphaPath),'legacy refusal preserves owned private files for cancellation');
    file_put_contents($manifestFile,json_encode($manifestBeforeRetry));
    rejects(fn()=>$service->reviewEdit(7,$ready['preview_id'],0,$changedAction),'changed immediate-parent log is stale');
    rejects(fn()=>$service->reviewFile(7,$ready['preview_id'],0,'alpha'),'stale file revision rejected');
    $beforeCalls=$reviewCalls;
    foreach (['{}', '{"points":[]}', [["kind"=>"background","tool"=>"point","radius"=>2,"hardness"=>true,"points"=>[[2,2]]]], [['kind'=>'background','tool'=>'brush','radius'=>81,'points'=>[[2,2]]]], [['kind'=>'background','tool'=>'point','radius'=>2,'points'=>[[100,2]]]], [['kind'=>'background','tool'=>'point','radius'=>2,'points'=>[[2.5,2]]]], [['kind'=>'background','tool'=>'point','radius'=>2,'points'=>[[2,2]],'path'=>'/etc/passwd']]] as $bad) {
       rejects(fn()=>$service->reviewEdit(7,$ready['preview_id'],1,$bad),'invalid action rejected');
    }
    check($reviewCalls===$beforeCalls,'invalid actions never reach worker');
    $job=basename(dirname($sourcePath));
    $duplicateService=new ProductImagePreviewService($root,function() use (&$states,$job) {return $states[$job];},function() use (&$clock) {return $clock;},$reviewWorker);
    $beforeDiscardCalls=$discardCalls;
    rejects(fn()=>$duplicateService->create(7,'studio-light','auto'),'worker job cannot be reused by another private session');
    check(is_file($sourcePath),'failed duplicate claim never deletes another session source');
    check($discardCalls===$beforeDiscardCalls,'failed duplicate ownership claim never asks worker to delete another session');
    AdminAccess::$id=10;
    rejects(fn()=>$service->reviewFile(7,$ready['preview_id'],1,'raw-alpha'),'cross-owner immutable raw mask denied');
    rejects(fn()=>$service->reviewEdit(7,$ready['preview_id'],1,$action),'cross-owner revisions denied');
    rejects(fn()=>$service->cancel(7,$ready['preview_id']),'cross-owner cancel denied');
    AdminAccess::$id=9;
    session_id('other-review-session');
    rejects(fn()=>$service->reviewFile(7,$ready['preview_id'],1,'raw-alpha'),'cross-session immutable raw mask denied');
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
    $refusedManifest=json_decode(file_get_contents($manifestFile),true);
    $refusedManifest['worker_attempts']=64;
    file_put_contents($manifestFile,json_encode($refusedManifest));
    $beforeCalls=$reviewCalls;
    check($service->reviewResult(7,$ready['preview_id'],1)===$refused,'unchanged refused result returns its cached snapshot at exhausted quota');
    check($reviewCalls===$beforeCalls,'unchanged refused result neither calls worker nor consumes quota');
    $refusedManifest['worker_attempts']=3;
    file_put_contents($manifestFile,json_encode($refusedManifest));
    $service->reviewEdit(7,$ready['preview_id'],1,$changedAction);
    $result=$service->reviewResult(7,$ready['preview_id'],2);
    check($result['state']==='ready' && $result['revision']===2,'changed action log allows explicit result to build an ordinary private preview');
    $readyManifest=json_decode(file_get_contents($manifestFile),true);
    $invalidReadyManifest=$readyManifest;
    $invalidReadyManifest['review']['gates']['background']['accepted']=false;
    file_put_contents($manifestFile,json_encode($invalidReadyManifest));
    rejects(fn()=>$service->confirm(7,$ready['preview_id'],2),'confirmation independently requires every source gate to remain accepted');
    $invalidReadyManifest=$readyManifest;
    $invalidReadyManifest['review']['action_results'][0]['status']='rejected';
    file_put_contents($manifestFile,json_encode($invalidReadyManifest));
    rejects(fn()=>$service->confirm(7,$ready['preview_id'],2),'confirmation independently requires every action to remain accepted');
    file_put_contents($manifestFile,json_encode($readyManifest));
    rejects(fn()=>$service->confirm(7,$ready['preview_id']),'reviewed confirm requires revision');
    rejects(fn()=>$service->confirm(7,$ready['preview_id'],0),'stale result cannot confirm');
    $accepted=$service->confirm(7,$ready['preview_id'],2);
    check($accepted['status']==='ready','separate confirm publishes approved preview');
    check($service->confirm(7,$ready['preview_id'],2)['job_id']===$accepted['job_id'],'confirmed replay idempotent');
    $acceptedHash=hash_file('sha256',$root.'/'.$accepted['master_path']);
    $qualityRejected=true;
    foreach (['rejected','unresolved'] as $actionStatus) {
       $blocked=$service->create(7,'studio-light','auto');
       $service->reviewStart(7,$blocked['preview_id']);
       $blockedEdit=$service->reviewEdit(7,$blocked['preview_id'],0,$action);
       check($blockedEdit['review']['action_results'][0]['status']===$actionStatus,'nonaccepted action status survives canonical revision capture');
       $blockedResult=$service->reviewResult(7,$blocked['preview_id'],1);
       check($blockedResult['state']==='needs_review' && $blockedResult['review']['action_results'][0]['status']===$actionStatus,'nonaccepted action blocks rendering despite aggregate accepted status and all independent gates');
       rejects(fn()=>$service->confirm(7,$blocked['preview_id'],1),'nonaccepted action never confirms');
       check(hash_file('sha256',$root.'/'.$accepted['master_path'])===$acceptedHash,'nonaccepted action preserves the published result');
       $service->cancel(7,$blocked['preview_id']);
    }
    $actionStatus='accepted';
    $malformedReviewChanges=[
       function($r) { $r['action_results'][0]['index']=1; return $r; },
       function($r) { $r['action_results'][0]['status']='approved'; return $r; },
       function($r) { $r['action_results'][0]['reasons']=['/private/model.onnx']; return $r; },
       function($r) { $r['action_results']=[]; return $r; },
       function($r) { $r['action_results']=['private'=>$r['action_results'][0]]; return $r; },
       function($r) { unset($r['action_results']); return $r; },
       function($r) { unset($r['actions'],$r['action_results']); return $r; },
       function($r) { $r['actions'][0]['points']=[[30,30]]; return $r; },
       function($r) { $r['actions'][0]['private_path']='/private/model.onnx'; return $r; },
       function($r) { $r['initial_region_count']=2; return $r; },
       function($r) { $r['initial_region_count']=null; return $r; },
       function($r) { $r['remaining_region_count']=null; return $r; },
       function($r) { $r['remaining_region_count']=-1; return $r; },
       function($r) { $r['remaining_region_count']=4097; return $r; }
    ];
    foreach ($malformedReviewChanges as $change) {
       $invalid=$service->create(7,'studio-light','auto');
       $service->reviewStart(7,$invalid['preview_id']);
       $workerReviewEdit=function($response) use ($change) { $response['review']=$change($response['review']); return $response; };
       rejects(fn()=>$service->reviewEdit(7,$invalid['preview_id'],0,$action),'malformed revised accounting rejects without advancing PHP snapshot');
       $workerReviewEdit=null;
       $invalidManifest=json_decode(file_get_contents($root.'/storage/image-processor/previews/'.$invalid['preview_id'].'/manifest.json'),true);
       check($invalidManifest['review']['revision']===0 && $invalidManifest['state']==='needs_review','invalid worker accounting cannot replace a trusted review snapshot');
       check(isset($invalidManifest['pending_review']),'invalid worker response retains the exact pending operation for safe recovery');
       check($service->reviewStart(7,$invalid['preview_id'])['revision']===1,'reopening safely recovers the pending operation after a malformed response');
       $service->cancel(7,$invalid['preview_id']);
    }
    foreach ($malformedReviewChanges as $change) {
       $invalid=$service->create(7,'studio-light','auto');
       $service->reviewStart(7,$invalid['preview_id']);
       $service->reviewEdit(7,$invalid['preview_id'],0,$action);
       $workerResultEdit=function($response) use ($change) { $response['review']=$change($response['review']); return $response; };
       rejects(fn()=>$service->reviewResult(7,$invalid['preview_id'],1),'malformed result accounting cannot create a ready preview');
       $workerResultEdit=null;
       $validResult=$service->reviewResult(7,$invalid['preview_id'],1);
       check($validResult['state']==='ready','malformed result response remains retryable when complete worker snapshot is restored');
       $service->cancel(7,$invalid['preview_id']);
    }
    $lost=$service->create(7,'studio-light','auto');
    $service->reviewStart(7,$lost['preview_id']);
    $loseReviseResponse=true;
    rejects(fn()=>$service->reviewEdit(7,$lost['preview_id'],0,$action),'network loss after worker commit leaves PHP retryable');
    $lostManifestFile=$root.'/storage/image-processor/previews/'.$lost['preview_id'].'/manifest.json';
    $lostManifest=json_decode(file_get_contents($lostManifestFile),true);
    check(($lostManifest['pending_review']['revision'] ?? null)===0 && ($lostManifest['pending_review']['actions'][0]['points'] ?? null)===$action[0]['points'],'unknown worker outcome durably retains the exact submitted journal');
    $lostManifest['worker_attempts']=64;
    file_put_contents($lostManifestFile,json_encode($lostManifest));
    $loseReviseResponse=false;
    $reopened=$service->reviewStart(7,$lost['preview_id']);
    check($reopened['revision']===1 && $reopened['review']['actions'][0]['points']===$action[0]['points'],'reopening after lost response recovers the exact pending journal before returning a snapshot');
    check(!isset(json_decode(file_get_contents($lostManifestFile),true)['pending_review']),'verified recovered snapshot clears pending journal');
    $recoveredManifest=json_decode(file_get_contents($lostManifestFile),true);
    check($recoveredManifest['worker_attempts']===64,'unknown-outcome recovery reuses its previously charged task at exhausted quota');
    $recoveredManifest['worker_attempts']=1;
    file_put_contents($lostManifestFile,json_encode($recoveredManifest));
    $recovered=$service->reviewEdit(7,$lost['preview_id'],0,$action);
    check($recovered['revision']===1 && $recovered['review']['actions'][0]['points']===$action[0]['points'],'identical lost-response retry captures the committed worker revision');
    $undone=$service->reviewEdit(7,$lost['preview_id'],1,[]);
    check($undone['revision']===2 && $undone['review']['actions']===[] && $undone['review']['action_results']===[],'empty cumulative log is a legitimate undo revision');
    rejects(fn()=>$service->reviewEdit(7,$lost['preview_id'],0,[]),'older ancestor cannot replay the current canonical log');
    check($service->reviewEdit(7,$lost['preview_id'],1,[])===$undone,'empty-log immediate-parent retry is idempotent');
    $service->cancel(7,$lost['preview_id']);
    $initialRegionCount=128;
    $initialRegionsOverride=array_map(fn($index)=>['reason'=>'local_uncertainty','bbox'=>[($index%16)*5,intdiv($index,16)*5,1,1]],range(0,127));
    $manyRegions=$service->create(7,'studio-light','auto');
    check(count($manyRegions['review']['regions'])===64 && $manyRegions['review']['initial_region_count']===128 && ($manyRegions['review']['remaining_region_count'] ?? null)===128,'bounded region display preserves the full unchanged remaining count');
    $service->reviewStart(7,$manyRegions['preview_id']);
    $manyEdited=$service->reviewEdit(7,$manyRegions['preview_id'],0,$action);
    check($manyEdited['review']['initial_region_count']===128,'full initial count remains valid when bounded defect display omits regions');
    $service->cancel(7,$manyRegions['preview_id']);
    $initialRegionCount=1;
    $initialRegionsOverride=null;
    $unit=$service->create(7,'studio-light','auto');
    $service->reviewStart(7,$unit['preview_id']);
    $unitAction=[['kind'=>'background','tool'=>'point','radius'=>2,'points'=>[[30,30]]]];
    $unitEdited=$service->reviewEdit(7,$unit['preview_id'],0,$unitAction);
    check($unitEdited['review']['actions'][0]['hardness']===1.0,'omitted hardness uses the same float canonical value as the Python worker');
    $unitAction[0]['hardness']=1;
    check($service->reviewEdit(7,$unit['preview_id'],1,$unitAction)===$unitEdited,'integer unit hardness retries the identical canonical float journal');
    $unitAction[0]['hardness']=1.0;
    check($service->reviewEdit(7,$unit['preview_id'],0,$unitAction)===$unitEdited,'float unit hardness retries the identical canonical parent journal');
    $service->cancel(7,$unit['preview_id']);
    $legacyWorkerSnapshot=true;
    $legacy=$service->create(7,'studio-light','auto');
    $service->reviewStart(7,$legacy['preview_id']);
    $service->reviewEdit(7,$legacy['preview_id'],0,$unitAction);
    check($service->reviewResult(7,$legacy['preview_id'],1)['state']==='ready','legacy workers without either accounting field retain normalized-journal compatibility');
    $service->cancel(7,$legacy['preview_id']);
    $legacyWorkerSnapshot=false;
    $legacyWorkerSnapshot=true;
    $upgraded=$service->create(7,'studio-light','auto');
    $service->reviewStart(7,$upgraded['preview_id']);
    $service->reviewEdit(7,$upgraded['preview_id'],0,$unitAction);
    $legacyWorkerSnapshot=false;
    check($service->reviewResult(7,$upgraded['preview_id'],1)['state']==='ready','result can supply complete accounting for a legacy private session');
    $service->reviewStart(7,$upgraded['preview_id']);
    $workerReviewEdit=function($response) { unset($response['review']['actions'],$response['review']['action_results']); return $response; };
    rejects(fn()=>$service->reviewEdit(7,$upgraded['preview_id'],1,$changedAction),'complete accounting learned from a result cannot later be removed');
    $workerReviewEdit=null;
    $service->cancel(7,$upgraded['preview_id']);
    $overbroadAction=[['kind'=>'background','tool'=>'brush','radius'=>20,'hardness'=>1,'points'=>[[50,50]]]];
    $precommit=$service->create(7,'studio-light','auto');
    $service->reviewStart(7,$precommit['preview_id']);
    $workerRejectJournal=$overbroadAction;
    rejects(fn()=>$service->reviewEdit(7,$precommit['preview_id'],0,$overbroadAction),'definitive unchanged-revision worker validation rejects overbroad annotation');
    $precommitManifest=json_decode(file_get_contents($root.'/storage/image-processor/previews/'.$precommit['preview_id'].'/manifest.json'),true);
    check(!isset($precommitManifest['pending_review']) && $precommitManifest['review']['revision']===0,'verified precommit rejection clears pending without advancing canonical journal');
    check($service->reviewStart(7,$precommit['preview_id'])['revision']===0,'reopening after definitive rejection does not replay an invalid journal');
    check($service->reviewEdit(7,$precommit['preview_id'],0,[])['revision']===0,'corrected empty journal is immediately available after definitive rejection');
    $workerRejectJournal=null;
    check($service->reviewEdit(7,$precommit['preview_id'],0,$action)['revision']===1,'valid corrected journal can be submitted after definitive rejection');
    $service->cancel(7,$precommit['preview_id']);
    foreach ([
       function($proof) { $proof['review_edit_rejected']=false; return $proof; },
       function($proof) { $proof['job_id']=str_repeat('f',32); return $proof; },
       function($proof) { $proof['source_sha256']=str_repeat('f',64); return $proof; },
       function($proof) { $proof['revision']=1; return $proof; }
    ] as $workerRejectionProofEdit) {
       $unproven=$service->create(7,'studio-light','auto');
       $service->reviewStart(7,$unproven['preview_id']);
       $workerRejectJournal=$action;
       rejects(fn()=>$service->reviewEdit(7,$unproven['preview_id'],0,$action),'mismatched rejection proof retains an uncertain outcome');
       $unprovenManifest=json_decode(file_get_contents($root.'/storage/image-processor/previews/'.$unproven['preview_id'].'/manifest.json'),true);
       check(isset($unprovenManifest['pending_review']),'unproven rejection cannot discard pending journal');
       $workerRejectJournal=null;
       check($service->reviewStart(7,$unproven['preview_id'])['revision']===1,'uncertain rejection still recovers the original pending journal');
       $service->cancel(7,$unproven['preview_id']);
    }
    $workerRejectionProofEdit=null;
    $oldUnstarted=$service->create(7,'studio-light','auto');
    $oldUnstartedFile=$root.'/storage/image-processor/previews/'.$oldUnstarted['preview_id'].'/manifest.json';
    $oldUnstartedManifest=json_decode(file_get_contents($oldUnstartedFile),true);
    unset($oldUnstartedManifest['review']['actions'],$oldUnstartedManifest['review']['action_results'],$oldUnstartedManifest['review']['accounting_required']);
    file_put_contents($oldUnstartedFile,json_encode($oldUnstartedManifest));
    $initialized=$service->reviewStart(7,$oldUnstarted['preview_id']);
    check($initialized['revision']===0 && $initialized['review']['actions']===[] && $initialized['review']['action_results']===[],'safe unstarted legacy revision zero initializes an empty canonical journal');
    $initializedManifest=json_decode(file_get_contents($oldUnstartedFile),true);
    check(($initializedManifest['review']['actions'] ?? null)===[] && ($initializedManifest['review']['action_results'] ?? null)===[],'legacy empty journal is persisted before marking the editor started');
    check($service->reviewEdit(7,$oldUnstarted['preview_id'],0,$unitAction)['revision']===1,'initialized unstarted legacy session accepts its first valid cumulative journal');
    $service->cancel(7,$oldUnstarted['preview_id']);
    $oldUnknown=$service->create(7,'studio-light','auto');
    $service->reviewStart(7,$oldUnknown['preview_id']);
    $oldUnknownFile=$root.'/storage/image-processor/previews/'.$oldUnknown['preview_id'].'/manifest.json';
    $oldUnknownManifest=json_decode(file_get_contents($oldUnknownFile),true);
    $oldUnknownDir=dirname($service->reviewFile(7,$oldUnknown['preview_id'],0,'source'));
    $reviewWorker('revise',['job_id'=>$oldUnknownManifest['review']['job_id'],'source_sha256'=>$oldUnknownManifest['source_sha256'],'revision'=>0,'actions'=>$unitAction]);
    unset($oldUnknownManifest['review']['actions'],$oldUnknownManifest['review']['action_results'],$oldUnknownManifest['review']['accounting_required']);
    file_put_contents($oldUnknownFile,json_encode($oldUnknownManifest));
    $beforeCalls=$reviewCalls;
    rejects(fn()=>$service->reviewStart(7,$oldUnknown['preview_id']),'pre-update started revision zero cannot conceal an unknown committed worker journal');
    rejects(fn()=>$service->reviewEdit(7,$oldUnknown['preview_id'],0,[]),'legacy unknown outcome cannot be inferred as an empty journal');
    check($reviewCalls===$beforeCalls && is_file($oldUnknownDir.'/alpha-1.png'),'legacy unknown-outcome refusal preserves the committed private revision');
    $service->cancel(7,$oldUnknown['preview_id']);
    check(!is_dir($oldUnknownDir),'refused legacy session can still be explicitly cancelled');
    $bounded=$service->create(7,'studio-light','auto');
    $service->reviewStart(7,$bounded['preview_id']);
    for ($revision=0;$revision<32;$revision++) {
       $boundedAction=[['kind'=>'background','tool'=>'point','radius'=>2,'points'=>[[$revision+1,30]]]];
       $lastBounded=$service->reviewEdit(7,$bounded['preview_id'],$revision,$boundedAction);
    }
    $beforeCalls=$reviewCalls;
    check($service->reviewEdit(7,$bounded['preview_id'],32,$boundedAction)===$lastBounded,'unchanged current log remains cached at maximum revision');
    check($service->reviewEdit(7,$bounded['preview_id'],31,$boundedAction)===$lastBounded,'identical parent retry remains cached at maximum revision');
    rejects(fn()=>$service->reviewEdit(7,$bounded['preview_id'],32,[]),'changed log cannot exceed revision cap');
    check($reviewCalls===$beforeCalls,'maximum-revision duplicate requests never call worker');
    $service->cancel(7,$bounded['preview_id']);
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
    $unchanged=$service->reviewEdit(7,$rejected['preview_id'],0,[]);
    check($unchanged['revision']===0,'initial empty cumulative log is already the immutable raw mask');
    $service->reviewEdit(7,$rejected['preview_id'],0,$action);
    $reset=$service->reviewEdit(7,$rejected['preview_id'],1,[]);
    check($reset['revision']===2,'empty cumulative action log restores immutable raw mask');
    $jobDir=dirname($service->reviewFile(7,$rejected['preview_id'],2,'source'));
    $service->cancel(7,$rejected['preview_id']);
    check(!is_dir($jobDir),'cancel removes owned review artifacts');
    check(is_file($root.'/'.$accepted['master_path']),'cancel preserves accepted result');
    $offline=$service->create(7,'studio-light','auto');
    $offlineDir=dirname($service->reviewFile(7,$offline['preview_id'],0,'source'));
    $offlineClaim=file_get_contents($offlineDir.'/php-owner.json');
    $discardUnavailable=true;
    $service->cancel(7,$offline['preview_id']);
    check(is_file($offlineDir.'/source.png') && file_get_contents($offlineDir.'/php-owner.json')===$offlineClaim,'unavailable worker keeps owned review artifacts and ownership proof');
    $discardUnavailable=false;
    $service->cancel(7,$offline['preview_id']);
    check(!is_dir($offlineDir),'cancel retries retained review deletion after worker recovers');
    $busy=$service->create(7,'studio-light','auto');
    $busyDir=dirname($service->reviewFile(7,$busy['preview_id'],0,'source'));
    $busyClaim=file_get_contents($busyDir.'/php-owner.json');
    $discardBusy=true;
    $service->cancel(7,$busy['preview_id']);
    check(is_file($busyDir.'/raw-alpha.png') && file_get_contents($busyDir.'/php-owner.json')===$busyClaim,'busy worker keeps in-progress review artifacts and ownership proof');
    $discardBusy=false;
    $service->cancel(7,$busy['preview_id']);
    check(!is_dir($busyDir),'cancel retries review deletion after active worker releases session');
    $mismatched=$service->create(7,'studio-light','auto');
    $mismatchedDir=dirname($service->reviewFile(7,$mismatched['preview_id'],0,'source'));
    $matchingClaim=file_get_contents($mismatchedDir.'/php-owner.json');
    file_put_contents($mismatchedDir.'/php-owner.json',json_encode(['preview_id'=>str_repeat('f',64),'expires_at'=>$clock+1800]));
    $beforeDiscardCalls=$discardCalls;
    $service->cancel(7,$mismatched['preview_id']);
    check(is_file($mismatchedDir.'/source.png') && $discardCalls===$beforeDiscardCalls,'mismatched ownership blocks deletion before contacting worker');
    file_put_contents($mismatchedDir.'/php-owner.json',$matchingClaim);
    $service->cancel(7,$mismatched['preview_id']);
    check(!is_dir($mismatchedDir),'owned deletion can retry after ownership proof is restored');
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
    if (is_dir($lockRoot) && !is_link($lockRoot)) {
        foreach (glob($lockRoot . '/*') ?: [] as $file) { if (is_file($file) && !is_link($file)) { unlink($file); } }
        rmdir($lockRoot);
    }
}
