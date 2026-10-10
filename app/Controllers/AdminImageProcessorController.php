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
                'worker_error' => $normalization['worker_error'] ?? null,
                'reason_code' => $normalization['reason_code'] ?? null,
                'detection_reason' => $normalization['detection_reason'] ?? null,
                'fallback_reason' => $normalization['fallback_reason'] ?? null,
                'mask_failure_reason' => $normalization['mask_failure_reason'] ?? null,
                'processing_time_ms' => $normalization['processing_time_ms'] ?? null,
                'background_fallback' => $normalization['background_fallback'] ?? false
            ], AdminAccess::currentId());
        } catch (Throwable $e) {
            // Publication already succeeded; an audit outage cannot undo an accepted photo.
            error_log('Image processing audit: ' . $e->getMessage());
        }
    }


    private function operation(callable $operation)
    {
        try {
            $this->json($this->publicPayload($operation()));
        } catch (Throwable $e) {
            $diagnostics = $e instanceof ImageProcessorException ? $e->diagnostics : [];
            // Keep the worker's exact reason private; exception text alone may omit it.
            error_log('Product image processing: ' . get_class($e) . ': ' . $e->getMessage()
                . ($diagnostics ? ' diagnostics=' . json_encode($diagnostics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) : ''));
            $safeDiagnostics = $this->publicDiagnostics($diagnostics);
            $privateDiagnostics = [];
            foreach (['mask_mode_requested', 'mask_method', 'processor_version', 'worker_error', 'reason_code', 'detection_reason', 'fallback_reason'] as $key) {
                if (isset($diagnostics[$key]) && is_string($diagnostics[$key])) {
                    $privateDiagnostics[$key] = $diagnostics[$key];
                }
            }
            $maskSelection = is_array($diagnostics['mask_selection'] ?? null) ? $diagnostics['mask_selection'] : [];
            if (is_string($maskSelection['failure_reason'] ?? null)) {
                $privateDiagnostics['mask_failure_reason'] = $maskSelection['failure_reason'];
            }
            if (isset($safeDiagnostics['processing_time_ms'])) {
                $privateDiagnostics['processing_time_ms'] = $safeDiagnostics['processing_time_ms'];
            }
            try {
                AdminAccess::audit('product.image_processing_failed', [
                    'image_id' => is_scalar($_POST['image_id'] ?? null) ? (int) $_POST['image_id'] : 0,
                    'background_profile_requested' => is_string($_POST['background_profile'] ?? null) ? substr($_POST['background_profile'], 0, 40) : 'original-canvas',
                    'mask_mode_requested' => is_string($_POST['mask_mode'] ?? null) ? substr($_POST['mask_mode'], 0, 40) : 'auto',
                    'processing_reason' => $e->getMessage(),
                    'worker_error' => $privateDiagnostics['worker_error'] ?? $e->getMessage()
                ] + $privateDiagnostics, AdminAccess::currentId());
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


    private function publicDiagnostics(array $diagnostics)
    {
        $public = [];
        $maskSelection = is_array($diagnostics['mask_selection'] ?? null) ? $diagnostics['mask_selection'] : [];
        $diagnostics['mask_failure_reason'] = $diagnostics['mask_failure_reason'] ?? ($maskSelection['failure_reason'] ?? null);
        foreach (['mask_mode_requested' => ['auto', 'grabcut', 'modnet'], 'mask_method' => ['none', 'opencv-grabcut', 'modnet']] as $key => $allowed) {
            if (in_array($diagnostics[$key] ?? null, $allowed, true)) {
                $public[$key] = $diagnostics[$key];
            }
        }
        if (isset($diagnostics['processor_version']) && is_string($diagnostics['processor_version'])
            && preg_match('/^[a-z0-9][a-z0-9._:+-]{0,39}$/iD', $diagnostics['processor_version']) === 1) {
            $public['processor_version'] = $diagnostics['processor_version'];
        }
        foreach (['worker_error', 'reason_code', 'detection_reason', 'fallback_reason', 'mask_failure_reason'] as $key) {
            $value = $diagnostics[$key] ?? null;
            if (is_string($value) && ($value === '' || preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/iD', $value) === 1)) {
                $public[$key] = $value;
            }
        }
        if (is_bool($diagnostics['background_fallback'] ?? null)) {
            $public['background_fallback'] = $diagnostics['background_fallback'];
        }
        $timings = is_array($diagnostics['timings_ms'] ?? null) ? $diagnostics['timings_ms'] : [];
        $processingTime = $diagnostics['processing_time_ms'] ?? ($timings['total'] ?? null);
        if ((is_int($processingTime) || is_float($processingTime)) && is_finite($processingTime) && $processingTime >= 0) {
            $public['processing_time_ms'] = $processingTime;
        }
        return $public;
    }


    private function publicPayload(array $payload)
    {
        if (!is_array($payload['processing'] ?? null)) { return $payload; }
        $processing = $payload['processing'];
        if (is_array($processing['normalization'] ?? null)) {
            $normalization = $processing['normalization'];
            $public = $this->publicDiagnostics($normalization);
            foreach (['mask_mode_requested', 'mask_method', 'processor_version', 'worker_error', 'reason_code', 'detection_reason', 'fallback_reason', 'mask_failure_reason', 'mask_selection', 'processing_time_ms', 'timings_ms'] as $key) {
                unset($normalization[$key]);
            }
            // Geometry and all previously normalized numeric diagnostics stay intact.
            $processing['normalization'] = array_merge($normalization, $public);
        }
        if (isset($processing['processor_version'])
            && !isset($this->publicDiagnostics(['processor_version' => $processing['processor_version']])['processor_version'])) {
            unset($processing['processor_version']);
        }
        $payload['processing'] = $processing;
        return $payload;
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
