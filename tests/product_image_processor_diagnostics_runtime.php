<?php
// Exercise the production controller response boundary without a database.
require __DIR__ . '/../app/Services/ImageProcessorClient.php';
class Controller {}
class AdminAccess {
    public static $events = [];
    public static function currentId() { return 9; }
    public static function audit($event, $details, $owner) {
        self::$events[] = ['event' => $event, 'details' => $details, 'owner' => $owner];
    }
}
require __DIR__ . '/../app/Controllers/AdminImageProcessorController.php';

$reason = getenv('IMAGE_PROCESSOR_TEST_REASON') ?: 'mask_quality_rejected';
$workerError = getenv('IMAGE_PROCESSOR_TEST_WORKER_ERROR') ?: 'worker crashed at /srv/private/weights/model.onnx';
$diagnostics = [
    'mask_mode_requested' => 'modnet', 'mask_method' => 'modnet',
    'processor_version' => '0.11', 'worker_error' => $workerError,
    'reason_code' => $reason, 'detection_reason' => 'subject-not-detected',
    'fallback_reason' => 'background_fallback', 'background_fallback' => true,
    'timings_ms' => ['total' => 153.25, 'private_path' => '/srv/private/timings'],
    'mask_selection' => ['failure_reason' => $reason, 'private_path' => '/srv/private/weights/model.onnx']
];
if (getenv('IMAGE_PROCESSOR_TEST_ACTION') === 'unsafe') {
    $diagnostics['mask_mode_requested'] = '/srv/private/mode';
    $diagnostics['mask_method'] = '/srv/private/method';
    $diagnostics['processor_version'] = '/srv/private/version';
    $diagnostics['reason_code'] = '/srv/private/reason';
    $diagnostics['detection_reason'] = '/srv/private/detection';
    $diagnostics['fallback_reason'] = '/srv/private/fallback';
    $diagnostics['mask_selection']['failure_reason'] = '/srv/private/mask-failure';
    $diagnostics['timings_ms']['total'] = -1;
}
if (getenv('IMAGE_PROCESSOR_TEST_ACTION') === 'invalid-timing') {
    $invalidTimings = ['negative' => -1, 'nan' => NAN, 'infinity' => INF, 'string' => '153.25'];
    $diagnostics['timings_ms']['total'] = $invalidTimings[getenv('IMAGE_PROCESSOR_TEST_REASON')];
}
$_POST = ['image_id' => 7, 'background_profile' => 'studio-light', 'mask_mode' => 'modnet'];
ini_set('error_log', getenv('IMAGE_PROCESSOR_TEST_LOG'));
register_shutdown_function(function () {
    file_put_contents(getenv('IMAGE_PROCESSOR_TEST_METADATA'), json_encode([
        'status' => http_response_code(), 'events' => AdminAccess::$events
    ]));
});
$controller = new AdminImageProcessorController();
$operation = new ReflectionMethod(AdminImageProcessorController::class, 'operation');
$operation->invoke($controller, function () use ($diagnostics, $reason, $controller) {
    if (getenv('IMAGE_PROCESSOR_TEST_ACTION') === 'success') {
        $processing = ['image_id' => 7, 'product_id' => 3, 'job_id' => str_repeat('a', 32),
            'original_path' => 'storage/image-processor/originals/' . str_repeat('a', 32) . '/source.jpg',
            'master_path' => 'uploads/products/processed/' . str_repeat('a', 32) . '/master.webp',
            'processor_version' => '0.11:model-normalize-v8', 'normalization' => array_merge($diagnostics, [
                'processing_time_ms' => 153.25,
                'mask_failure_reason' => $reason,
                'method' => 'person-detected-no-crop', 'person_bbox' => [2, 3, 100, 200],
                'crop_box' => [0, 0, 150, 300], 'mask_foreground_ratio' => 0.45,
                'subject_mask_applied' => true
            ])];
        (new ReflectionMethod(AdminImageProcessorController::class, 'auditAccepted'))->invoke($controller, $processing);
        return ['success' => true, 'processing' => $processing];
    }
    throw new ImageProcessorException('Mask processing failed: ' . $reason, $diagnostics);
});
