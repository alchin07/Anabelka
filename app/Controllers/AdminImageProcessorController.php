<?php

class AdminImageProcessorController extends Controller
{
    public function health()
    {
        try {
            $health = ImageProcessorClient::health();

            $this->json([
                'ok' => true,
                'processor' => $health
            ]);
        } catch (Throwable $e) {
            error_log(
                'Image processor health: '
                . $e->getMessage()
            );

            $this->json(
                [
                    'ok' => false,
                    'error' =>
                        'Локальний сервіс обробки зображень недоступний.'
                ],
                503
            );
        }
    }


    public function process()
    {
        $this->operation(function () {
            $result = ProductImageProcessingService::process(
                $_POST['image_id'] ?? 0,
                $_POST['background_profile'] ?? 'original-canvas',
                $_POST['mask_mode'] ?? 'auto'
            );
            $this->auditAccepted($result);
            return ['success' => true, 'image_id' => (int) $result['image_id'], 'processing' => $result];
        });
    }


    public function preview()
    {
        $this->operation(function () {
            return ProductImageProcessingService::preview(
                $_POST['image_id'] ?? 0,
                $_POST['background_profile'] ?? 'original-canvas',
                $_POST['mask_mode'] ?? 'auto'
            );
        });
    }


    public function confirm()
    {
        $this->operation(function () {
            $result = (new ProductImagePreviewService())->confirm(
                $_POST['image_id'] ?? 0,
                $_POST['preview_id'] ?? ''
            );
            $this->auditAccepted($result);
            return ['success' => true, 'image_id' => (int) $result['image_id'], 'processing' => $result];
        });
    }


    public function cancel()
    {
        $this->operation(function () {
            return (new ProductImagePreviewService())->cancel(
                $_POST['image_id'] ?? 0,
                $_POST['preview_id'] ?? ''
            );
        });
    }


    public function previewFile()
    {
        try {
            $path = (new ProductImagePreviewService())->previewFile(
                $_GET['image_id'] ?? 0,
                $_GET['preview_id'] ?? ''
            );
            $stream = @fopen($path, 'rb');
            if (!$stream) { throw new InvalidArgumentException('Проба фотографії недоступна.'); }
            header('Content-Type: image/webp');
            header('Cache-Control: private, no-store');
            header('X-Content-Type-Options: nosniff');
            header('Content-Length: ' . fstat($stream)['size']);
            fpassthru($stream);
            fclose($stream);
            exit;
        } catch (Throwable $e) {
            $this->json(['success' => false, 'message' => 'Проба фотографії недоступна.'], 404);
        }
    }


    private function auditAccepted(array $result)
    {
        $normalization = $result['normalization'] ?? [];
        try {
            AdminAccess::audit('product.image_processed', [
                'product_id' => (int) ($result['product_id'] ?? 0),
                'image_id' => (int) ($result['image_id'] ?? 0),
                'job_id' => (string) ($result['job_id'] ?? ''),
                'background_profile' => $normalization['background_profile'] ?? 'original-canvas',
                'background_profile_requested' => $normalization['background_profile_requested'] ?? 'original-canvas',
                'mask_mode_requested' => $normalization['mask_mode_requested'] ?? 'auto',
                'mask_method' => $normalization['mask_method'] ?? 'none',
                'processor_version' => $result['processor_version'] ?? '',
                'worker_error' => $normalization['worker_error'] ?? null
            ], AdminAccess::currentId());
        } catch (Throwable $e) {
            // Publication already succeeded; an audit outage cannot undo an accepted photo.
            error_log('Image processing audit: ' . $e->getMessage());
        }
    }


    private function operation(callable $operation)
    {
        try {
            $this->json($operation());
        } catch (Throwable $e) {
            error_log('Product image processing: ' . get_class($e) . ': ' . $e->getMessage());
            $diagnostics = $e instanceof ImageProcessorException ? $e->diagnostics : [];
            $safeDiagnostics = [];
            foreach (['mask_mode_requested', 'mask_method', 'processor_version', 'worker_error'] as $key) {
                if (isset($diagnostics[$key]) && is_string($diagnostics[$key])) {
                    $safeDiagnostics[$key] = substr($diagnostics[$key], 0, 500);
                }
            }
            try {
                AdminAccess::audit('product.image_processing_failed', [
                    'image_id' => is_scalar($_POST['image_id'] ?? null) ? (int) $_POST['image_id'] : 0,
                    'background_profile_requested' => is_string($_POST['background_profile'] ?? null) ? substr($_POST['background_profile'], 0, 40) : 'original-canvas',
                    'mask_mode_requested' => is_string($_POST['mask_mode'] ?? null) ? substr($_POST['mask_mode'], 0, 40) : 'auto',
                    'worker_error' => $safeDiagnostics['worker_error'] ?? substr($e->getMessage(), 0, 500)
                ] + $safeDiagnostics, AdminAccess::currentId());
            } catch (Throwable $auditError) {
                error_log('Image processing error audit: ' . $auditError->getMessage());
            }
            $this->json([
                'success' => false,
                'message' => $e instanceof InvalidArgumentException ? $e->getMessage() : 'Не вдалося обробити фотографію товару.',
                'diagnostics' => $safeDiagnostics
            ], $e instanceof InvalidArgumentException ? 400 : 500);
        }
    }


    private function json(array $payload, $status = 200)
    {
        http_response_code((int) $status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }
}
