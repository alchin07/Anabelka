<?php
// Exercise the real PHP feature switch with a fake preview service.
// No database, AI model, or customer photographs are required.
class ProductImagePreviewService
{
    public static array $created = [];
    public static array $confirmed = [];

    public function create($imageId, $backgroundProfile = 'original-canvas', $maskMode = 'auto', $allowReview = true)
    {
        self::$created[] = [
            'image_id' => $imageId,
            'background_profile' => $backgroundProfile,
            'mask_mode' => $maskMode,
            'allow_review' => $allowReview
        ];
        return ['success' => true, 'state' => 'ready', 'image_id' => $imageId, 'preview_id' => 'fixture-preview'];
    }

    public function confirm($imageId, $token)
    {
        self::$confirmed[] = [$imageId, $token];
        return ['image_id' => $imageId, 'status' => 'ready'];
    }
}

require __DIR__ . '/../app/Services/ProductImageProcessingService.php';

function checkManualMaskDisabled($condition, $message)
{
    if (!$condition) { throw new RuntimeException($message); }
}

checkManualMaskDisabled(
    ProductImageProcessingService::manualMaskReviewEnabled() === false,
    'Manual mask editor must be disabled by default.'
);

$preview = ProductImageProcessingService::preview(17, 'studio-light', 'modnet');
checkManualMaskDisabled($preview['state'] === 'ready', 'Automatic preview is still available.');
checkManualMaskDisabled(
    ProductImagePreviewService::$created[0] === [
        'image_id' => 17,
        'background_profile' => 'studio-light',
        'mask_mode' => 'modnet',
        'allow_review' => false
    ],
    'Preview must use MODNet without requesting manual review.'
);

$preview = ProductImageProcessingService::preview(18, 'anabelka-brand', 'grabcut');
checkManualMaskDisabled($preview['state'] === 'ready', 'GrabCut preview remains available.');
checkManualMaskDisabled(
    ProductImagePreviewService::$created[1]['mask_mode'] === 'grabcut'
    && ProductImagePreviewService::$created[1]['allow_review'] === false,
    'GrabCut must also bypass manual review.'
);

$result = ProductImageProcessingService::process(19, 'studio-light', 'auto');
checkManualMaskDisabled(
    ProductImagePreviewService::$created[2]['allow_review'] === false
    && $result['status'] === 'ready'
    && ProductImagePreviewService::$confirmed === [[19, 'fixture-preview']],
    'Legacy direct processing must continue through the existing confirmation path.'
);
// The disabled server guard must refuse direct/manual endpoints as well.
class Controller {}
require __DIR__ . '/../app/Controllers/AdminImageProcessorController.php';
$guard = new ReflectionMethod(AdminImageProcessorController::class, 'requireManualMaskReview');
$denied = false;
try {
    $guard->invoke(new AdminImageProcessorController());
} catch (InvalidArgumentException $error) {
    $denied = true;
}
checkManualMaskDisabled($denied, 'Mask review endpoints must be disabled server-side.');
echo "product manual mask disabled runtime passed\n";
