<?php

class ProductImagePreviewService
{
    private const TTL = 1800;
    private $root;
    private $temporary;
    private $processor;
    private $clock;
    private $reviewWorker;

    public function __construct($projectRoot = null, ?callable $processor = null, ?callable $clock = null, ?callable $reviewWorker = null)
    {
        $this->root = realpath($projectRoot ?? dirname(__DIR__, 2));
        if ($this->root === false) {
            throw new RuntimeException('Каталог фотографій недоступний.');
        }
        $this->temporary = $this->root . '/storage/image-processor/previews';
        $this->processor = $processor ?? [ImageProcessorClient::class, 'processProductImage'];
        $this->clock = $clock ?? 'time';
        $this->reviewWorker = $reviewWorker ?? function ($operation, array $payload) {
            $methods = ['revise' => 'reviseMask', 'result' => 'renderMask', 'asset' => 'maskAsset', 'discard' => 'discardMask'];
            return call_user_func([ImageProcessorClient::class, $methods[$operation]], $payload);
        };
    }

    public function create($imageId, $backgroundProfile = 'original-canvas', $maskMode = 'auto', $allowReview = true)
    {
        $owner = $this->owner();
        $imageId = $this->imageId($imageId);
        if (!is_string($backgroundProfile) || !in_array($backgroundProfile, ['original-canvas', 'studio-light', 'anabelka-brand'], true)) {
            throw new InvalidArgumentException('Невідомий профіль фону фотографії.');
        }
        $maskMode = ImageProcessorClient::normalizeMaskMode($maskMode);
        // DDL must precede transactions; preview never writes processing rows.
        ProductImageProcessing::ensureSchema();
        $this->cleanupExpired();
        $token = bin2hex(random_bytes(32));
        $tokenLock = $this->lock('token-' . $token);
        $imageLock = $this->lock('image-' . $imageId);
        $directory = $this->directory($token);
        $workerFiles = [];
        $review = null;
        try {
            $quotaLock = $this->lock('quota');
            try {
                $this->checkQuota($owner);
                $this->mkdir($directory, false);
            } finally { $this->unlock($quotaLock); }
            $source = $this->source($imageId);
            $baseline = $this->fingerprint(ProductImageProcessing::find($imageId));
            if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
            $response = call_user_func($this->processor, $source['path'], $backgroundProfile, $maskMode, $allowReview === true);
            if (!is_array($response)) {
                throw new RuntimeException('Некоректна відповідь обробника фотографій.');
            }
            if (($response['state'] ?? '') !== 'needs_review' || isset($response['master'], $response['thumb'], $response['original'])) {
                $workerFiles = $this->generatedFiles($response);
            }
            if (isset($response['review'])) {
                $job = $response['review_job_id'] ?? ($response['job_id'] ?? '');
                $workerSourceHash = $response['source_sha256'] ?? ($response['original']['sha256'] ?? '');
                if (!is_string($job) || preg_match('/^[a-f0-9]{32}$/D', $job) !== 1 || ProductImageProcessing::isJobAccepted($job)
                    || !is_string($workerSourceHash) || !hash_equals($source['sha256'], $workerSourceHash)) {
                    throw new RuntimeException('Некоректні початкові дані перевірки маски.');
                }
                // Claim before decoding the artifacts, so validation failures can remove exactly this request's private tree.
                $review = ['job_id' => $job, 'preview_id' => $token, 'expires_at' => $this->now() + self::TTL];
                $this->claimReview($review);
                $review = $this->captureReview($response, $source) + $review;
            }
            if (($response['state'] ?? '') === 'needs_review') {
                if ($workerFiles || !$allowReview || !$review || !array_intersect($review['reasons'], [
                    'source_component_loss', 'source_local_material_loss', 'source_thin_material_loss', 'source_background_retained',
                    'source_evidence_unavailable', 'invalid_alpha_area', 'mask_quality_rejected', 'immutable_material_loss'
                ])) { throw new RuntimeException('Неповні дані перевірки маски.'); }
                $manifest = $this->newManifest($token, $imageId, $owner, $source, $baseline, $backgroundProfile, $maskMode);
                $manifest['state'] = 'needs_review';
                $manifest['review'] = $review;
                $manifest['expires_at'] = $review['expires_at'];
                $this->verifyLiveSource($manifest);
                $this->writeManifest($token, $manifest);
                return $this->reviewPayload($manifest);
            }
            if ($review && ($review['status'] !== 'accepted' || !$this->allReviewGatesAccepted($review['gates'])
                || !$this->allReviewActionsAccepted($review['action_results']))) {
                throw new RuntimeException('Маска не пройшла незалежну перевірку.');
            }
            $workerFiles = $this->generatedFiles($response);
            $data = ProductImageProcessingService::normalizeResult($source['path'], $response, $backgroundProfile, $maskMode);
            if (ProductImageProcessing::isJobAccepted($data['job_id'])) {
                throw new RuntimeException('Обробник повернув вже опублікований результат.');
            }
            $currentSource = $this->source($imageId);
            if ($source['path'] !== $currentSource['path'] || !hash_equals($source['sha256'], $currentSource['sha256']) || !hash_equals($source['sha256'], $data['source_sha256'])) {
                throw new InvalidArgumentException('Оригінал фотографії змінився. Створіть нову пробу.');
            }
            if (!hash_equals($source['sha256'], hash_file('sha256', $workerFiles['original']))) {
                throw new RuntimeException('Обробник змінив оригінал фотографії.');
            }
            $files = [];
            foreach ($workerFiles as $key => $file) {
                $bytes = $key === 'original' ? $data['source_bytes'] : $data[$key . '_bytes'];
                if (filesize($file) !== $bytes) {
                    throw new RuntimeException('Розмір результату обробки не відповідає діагностиці.');
                }
                $name = $key === 'original' ? 'original.bin' : $key . '.webp';
                $sha256 = hash_file('sha256', $file);
                $this->copyVerified($file, $directory . '/' . $name, $sha256);
                $files[$key] = ['name' => $name, 'sha256' => $sha256];
            }
            $manifest = [
                'preview_id' => $token, 'image_id' => $imageId,
                'owner_id' => $owner['id'], 'session_hash' => $owner['session_hash'],
                'source_path' => $source['path'], 'source_sha256' => $source['sha256'],
                'product_id' => $source['product_id'], 'baseline' => $baseline,
                'created_at' => $this->now(), 'expires_at' => $review['expires_at'] ?? ($this->now() + self::TTL),
                'state' => 'ready', 'data' => $data, 'files' => $files,
                'background_profile' => $backgroundProfile, 'mask_mode' => $maskMode,
                'review' => $review, 'result_revision' => $review ? 0 : null,
                // Publication uses fresh destinations, never paths owned by a previous photo.
                'publication_id' => bin2hex(random_bytes(16))
            ];
            $this->writeManifest($token, $manifest);
            $this->removeWorkerFiles($workerFiles);
            $workerFiles = [];
            $processing = array_merge($data, ['status' => 'ready', 'image_id' => $imageId]);
            // No public worker paths are returned for an unaccepted preview.
            foreach (['original_path', 'master_path', 'thumb_path', 'source_path'] as $key) {
                unset($processing[$key]);
            }
            return [
                'success' => true, 'state' => 'ready', 'image_id' => $imageId, 'preview_id' => $token,
                'review_available' => $review !== null, 'revision' => $review ? 0 : null,
                'expires_at' => gmdate('c', $manifest['expires_at']),
                'processing' => $processing,
                'preview_url' => '/Anabelka/admin/products/image-process-preview-file?image_id=' . $imageId . '&preview_id=' . $token
            ];
        } catch (Throwable $e) {
            $this->removeWorkerFiles($workerFiles);
            $this->removeReviewFiles($review);
            $this->removeTemporary($token);
            throw $e;
        } finally {
            $this->unlock($tokenLock);
            $this->unlock($imageLock);
        }
    }

    public function confirm($imageId, $token, $revision = null)
    {
        $owner = $this->owner();
        $imageId = $this->imageId($imageId);
        $token = $this->token($token);
        ProductImageProcessing::ensureSchema();
        $tokenLock = $this->lock('token-' . $token);
        $imageLock = $this->lock('image-' . $imageId);
        $db = Database::connect();
        $newFiles = [];
        $newDirectories = [];
        $committed = false;
        try {
            $manifest = $this->readManifest($token);
            $this->verifyOwner($manifest, $owner, $imageId);
            $this->verifyExpiry($manifest);
            if (($manifest['state'] ?? '') === 'cancelled') {
                throw new InvalidArgumentException('Пробу фотографії скасовано.');
            }
            if (!in_array($manifest['state'] ?? '', ['ready', 'confirmed'], true)) {
                throw new InvalidArgumentException('Маска потребує перевірки. Спочатку створіть нову пробу.');
            }
            if (is_array($manifest['review'] ?? null) && !empty($manifest['review']['started'])
                && ($this->revision($revision) !== (int) $manifest['review']['revision']
                    || (int) ($manifest['result_revision'] ?? -1) !== (int) $manifest['review']['revision'])) {
                throw new InvalidArgumentException('Проба маски застаріла. Створіть нову пробу.');
            }
            if (is_array($manifest['review'] ?? null) && (($manifest['review']['status'] ?? '') !== 'accepted'
                || !$this->allReviewGatesAccepted($manifest['review']['gates'] ?? [])
                || !$this->allReviewActionsAccepted($manifest['review']['action_results'] ?? []))) {
                throw new InvalidArgumentException('Маска не пройшла незалежну перевірку.');
            }
            $db->beginTransaction();
            // Lock the live gallery row against deletion or path replacement as well.
            $statement = $db->prepare('SELECT id, product_id, path FROM product_gallery_images WHERE id = :image_id LIMIT 1 FOR UPDATE');
            $statement->execute(['image_id' => $imageId]);
            $lockedImage = $statement->fetch(PDO::FETCH_ASSOC);
            $source = $this->source($imageId, $lockedImage ?: null);
            if ($source['path'] !== $manifest['source_path'] || !hash_equals($source['sha256'], $manifest['source_sha256'])) {
                throw new InvalidArgumentException('Оригінал фотографії змінився. Створіть нову пробу.');
            }
            $stored = ProductImageProcessing::find($imageId);
            $finalData = $this->publicationData($manifest);
            // DB is the authority after a crash or failure to write the replay marker.
            if ($stored && $stored['status'] === 'ready' && $stored['job_id'] === $finalData['job_id'] && $stored['master_path'] === $finalData['master_path'] && $stored['thumb_path'] === $finalData['thumb_path'] && $stored['original_path'] === $finalData['original_path']) {
                foreach (['original', 'master', 'thumb'] as $key) {
                    $file = $this->safeFile($finalData[$key . '_path']);
                    if (!hash_equals($manifest['files'][$key]['sha256'], hash_file('sha256', $file))) {
                        throw new InvalidArgumentException('Опублікований файл фотографії змінився.');
                    }
                }
                $db->commit();
                $committed = true;
                return array_merge($stored, ['product_id' => $manifest['product_id']]);
            }
            if (($manifest['state'] ?? '') === 'confirmed' || $this->fingerprint($stored) !== $manifest['baseline']) {
                throw new InvalidArgumentException('Після цієї проби фотографію вже змінено. Створіть нову пробу.');
            }
            $this->verifyExpiry($manifest);
            $files = $this->verifiedFiles($token, $manifest);
            foreach (['original', 'master'] as $key) {
                $destinationDirectory = dirname($this->root . '/' . $finalData[$key . '_path']);
                if (!is_dir($destinationDirectory)) {
                    $this->mkdir($destinationDirectory, false);
                    $newDirectories[] = $destinationDirectory;
                } elseif (is_link($destinationDirectory) || realpath($destinationDirectory) !== $destinationDirectory) {
                    throw new RuntimeException('Небезпечний каталог результату фотографії.');
                }
                if ($key === 'master') { chmod($destinationDirectory, 0755); }
            }
            foreach (['original', 'master', 'thumb'] as $key) {
                $destination = $this->root . '/' . $finalData[$key . '_path'];
                if (file_exists($destination) || is_link($destination)) {
                    // Source and DB baseline already prove this token was never accepted.
                    // Recover its exact regular destination after a crash during copying.
                    $this->safeFile($finalData[$key . '_path']);
                    if (!hash_equals($manifest['files'][$key]['sha256'], hash_file('sha256', $destination))) {
                        if (!@unlink($destination)) { throw new RuntimeException('Не вдалося відновити запис проби фотографії.'); }
                        $this->copyVerified($files[$key], $destination, $manifest['files'][$key]['sha256']);
                        $newFiles[] = $destination;
                    }
                } else {
                    $this->copyVerified($files[$key], $destination, $manifest['files'][$key]['sha256']);
                    $newFiles[] = $destination;
                }
                if ($key !== 'original') { chmod($destination, 0644); }
            }
            // Verify source once more after filesystem publication, before the DB switch.
            if (!hash_equals($source['sha256'], $this->source($imageId, $lockedImage)['sha256'])) {
                throw new InvalidArgumentException('Оригінал фотографії змінився.');
            }
            ProductImageProcessing::markReady($imageId, $finalData);
            $result = ProductImageProcessing::find($imageId);
            if (!$result || ($result['status'] ?? '') !== 'ready' || $result['job_id'] !== $finalData['job_id'] || $result['master_path'] !== $finalData['master_path']) {
                throw new RuntimeException('База не підтвердила результат обробки фотографії.');
            }
            $db->commit();
            $committed = true;
            $manifest['state'] = 'confirmed';
            try {
                $this->writeManifest($token, $manifest);
                $this->removeStagedFiles($token);
                $this->removeReviewFiles($manifest['review'] ?? null);
            } catch (Throwable $e) {
                // The accepted DB row and finals survive a marker/temporary cleanup error.
                error_log('Image preview post-commit cleanup: ' . $e->getMessage());
            }
            return array_merge($result, ['product_id' => $manifest['product_id']]);
        } catch (Throwable $e) {
            if (!$committed) {
                if ($db->inTransaction()) { $db->rollBack(); }
                foreach ($newFiles as $file) { if (is_file($file) && !is_link($file)) { @unlink($file); } }
                foreach (array_reverse($newDirectories) as $directory) { @rmdir($directory); }
            }
            throw $e;
        } finally {
            $this->unlock($imageLock);
            $this->unlock($tokenLock);
        }
    }

    public function cancel($imageId, $token)
    {
        $owner = $this->owner();
        $imageId = $this->imageId($imageId);
        $token = $this->token($token);
        $lock = $this->lock('token-' . $token);
        try {
            $manifest = $this->readManifest($token);
            $this->verifyOwner($manifest, $owner, $imageId);
            if (($manifest['state'] ?? '') !== 'confirmed') {
                $manifest['state'] = 'cancelled';
                $this->writeManifest($token, $manifest);
                $this->removeStagedFiles($token);
                $this->removeReviewFiles($manifest['review'] ?? null);
            }
            return ['success' => true, 'image_id' => $imageId];
        } finally { $this->unlock($lock); }
    }

    public function previewFile($imageId, $token, $revision = null)
    {
        $owner = $this->owner();
        $imageId = $this->imageId($imageId);
        $token = $this->token($token);
        $lock = $this->lock('token-' . $token);
        try {
            $manifest = $this->readManifest($token);
            $this->verifyOwner($manifest, $owner, $imageId);
            $this->verifyExpiry($manifest);
            if (($manifest['state'] ?? '') !== 'ready') {
                throw new InvalidArgumentException('Проба фотографії недоступна.');
            }
            $source = $this->source($imageId);
            if ($source['path'] !== $manifest['source_path'] || !hash_equals($source['sha256'], $manifest['source_sha256'])) {
                throw new InvalidArgumentException('Оригінал фотографії змінився.');
            }
            if (!empty($manifest['review']['started']) && ($this->revision($revision) !== (int) $manifest['review']['revision']
                || (int) ($manifest['result_revision'] ?? -1) !== (int) $manifest['review']['revision'])) {
                throw new InvalidArgumentException('Версія проби маски застаріла.');
            }
            return $this->verifiedFiles($token, $manifest)['master'];
        } finally { $this->unlock($lock); }
    }

    public function cleanupExpired()
    {
        if (!is_dir($this->temporary) || is_link($this->temporary)) { return; }
        foreach (glob($this->temporary . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $token = basename($directory);
            if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1 || is_link($directory)) { continue; }
            $lock = $this->lock('token-' . $token, false);
            if (!$lock) { continue; }
            try {
                $path = $directory . '/manifest.json';
                $manifest = is_file($path) ? json_decode(file_get_contents($path), true) : null;
                if ((is_array($manifest) && (int) ($manifest['expires_at'] ?? 0) <= $this->now()) || (!is_array($manifest) && filemtime($directory) + self::TTL <= $this->now())) {
                    if (is_array($manifest) && isset($manifest['data']['job_id']) && !$this->cleanupUnpublished($manifest)) { continue; }
                    if (is_array($manifest)) { $this->removeReviewFiles($manifest['review'] ?? null); }
                    // Temporary deletion never follows paths supplied in the manifest.
                    $this->removeTemporary($token);
                }
            } finally { $this->unlock($lock); }
        }
    }

    public function reviewStart($imageId, $token)
    {
        return $this->withReview($imageId, $token, function (&$manifest) {
            $this->requireReviewJournal($manifest['review']);
            if (!array_key_exists('actions', $manifest['review'])) {
                // Only an unstarted revision-zero legacy session reaches this path.
                $manifest['review']['actions'] = [];
                $manifest['review']['action_results'] = [];
                $manifest['review']['accounting_required'] = false;
            }
            $this->recoverPendingReview($manifest);
            $manifest['review']['started'] = true;
            $manifest['state'] = 'needs_review';
            $manifest['result_revision'] = null;
            $this->writeManifest($manifest['preview_id'], $manifest);
            return $this->reviewPayload($manifest);
        });
    }

    public function reviewEdit($imageId, $token, $revision, $actions)
    {
        $revision = $this->revision($revision);
        return $this->withReview($imageId, $token, function (&$manifest) use ($revision, $actions) {
            $review = $manifest['review'];
            $this->requireReviewJournal($review);
            if (empty($review['started'])) {
                throw new InvalidArgumentException('Версія маски застаріла. Оновіть перевірку.');
            }
            $actions = $this->actions($actions, $review['width'], $review['height']);
            $this->recoverPendingReview($manifest);
            $review = $manifest['review'];
            $currentRevision = (int) $review['revision'];
            $canonical = $this->actions($review['actions'] ?? [], $review['width'], $review['height']);
            if ($actions === $canonical && ($revision === $currentRevision || $revision === ($review['parent_revision'] ?? null))) {
                return $manifest['state'] === 'ready' ? $this->readyPayload($manifest) : $this->reviewPayload($manifest);
            }
            if ($manifest['state'] !== 'needs_review' || $revision !== $currentRevision) {
                throw new InvalidArgumentException('Версія маски застаріла. Оновіть перевірку.');
            }
            if ($revision >= 32) { throw new InvalidArgumentException('Ліміт перевірок маски вичерпано. Створіть нову пробу.'); }
            $manifest['pending_review'] = ['revision' => $revision, 'actions' => $actions];
            $this->chargeReviewTask($manifest);
            $this->recoverPendingReview($manifest);
            return $this->reviewPayload($manifest);
        });
    }

    private function requireReviewJournal(array $review)
    {
        if ((!empty($review['started']) || (int) $review['revision'] > 0) && !array_key_exists('actions', $review)) {
            throw new InvalidArgumentException('Це проба старої версії редактора маски. Скасуйте її та створіть нову. Поточна фотографія не змінилася.');
        }
    }

    private function recoverPendingReview(array &$manifest)
    {
        if (!isset($manifest['pending_review'])) { return; }
        $pending = $manifest['pending_review'];
        $review = $manifest['review'];
        $revision = (int) $review['revision'];
        if (!is_array($pending) || ($pending['revision'] ?? null) !== $revision) {
            throw new RuntimeException('Некоректна незавершена перевірка маски.');
        }
        $actions = $this->actions($pending['actions'] ?? null, $review['width'], $review['height']);
        // The task was charged when this exact journal was durably queued. A retry
        // resolves its unknown outcome without consuming another edit or task.
        try {
            $response = call_user_func($this->reviewWorker, 'revise', $this->workerReviewPayload($manifest) + ['actions' => $actions]);
        } catch (ImageProcessorException $e) {
            $proof = $e->diagnostics;
            if (($proof['review_edit_rejected'] ?? null) === true && ($proof['job_id'] ?? null) === $review['job_id']
                && ($proof['source_sha256'] ?? null) === $manifest['source_sha256'] && ($proof['revision'] ?? null) === $revision) {
                // Only an explicit unchanged-revision rejection proves this journal
                // did not commit. Transport and unproven worker failures stay pending.
                unset($manifest['pending_review']);
                $this->writeManifest($manifest['preview_id'], $manifest);
            }
            throw $e;
        }
        $this->verifyReviewResponse($response, $manifest, $revision + 1);
        $this->verifyImmutableReview($review);
        $updated = $this->captureReview($response, ['sha256' => $manifest['source_sha256']], $review);
        if ($updated['job_id'] !== $review['job_id'] || $updated['width'] !== $review['width'] || $updated['height'] !== $review['height']
            || $updated['raw_alpha_sha256'] !== $review['raw_alpha_sha256'] || $updated['source_image_sha256'] !== $review['source_image_sha256']) {
            throw new RuntimeException('Обробник змінив початкові дані маски.');
        }
        if (isset($response['review']['actions']) && $updated['actions'] !== $actions) {
            throw new RuntimeException('Обробник повернув інші позначки маски.');
        }
        $updated['actions'] = $actions;
        $updated['parent_revision'] = $revision;
        $updated['started'] = true;
        $updated['preview_id'] = $manifest['preview_id'];
        $updated['expires_at'] = $manifest['expires_at'];
        $this->verifyReviewClaim($updated);
        $manifest['review'] = $updated;
        $manifest['state'] = 'needs_review';
        $manifest['result_revision'] = null;
        unset($manifest['pending_review'], $manifest['result_refused_revision']);
        $this->verifyLiveSource($manifest);
        $this->writeManifest($manifest['preview_id'], $manifest);
    }

    public function reviewResult($imageId, $token, $revision)
    {
        $revision = $this->revision($revision);
        return $this->withReview($imageId, $token, function (&$manifest) use ($revision) {
            if (empty($manifest['review']['started']) || $revision !== (int) $manifest['review']['revision']) {
                throw new InvalidArgumentException('Версія маски застаріла. Оновіть перевірку.');
            }
            if ($manifest['state'] === 'ready' && (int) $manifest['result_revision'] === $revision) { return $this->readyPayload($manifest); }
            if ($manifest['state'] !== 'needs_review') { throw new InvalidArgumentException('Перевірка маски недоступна.'); }
            if (($manifest['result_refused_revision'] ?? null) === $revision) { return $this->reviewPayload($manifest); }
            $workerFiles = [];
            $temporaryFiles = [];
            try {
                $this->chargeReviewTask($manifest);
                $response = call_user_func($this->reviewWorker, 'result', $this->workerReviewPayload($manifest) + ['background_profile' => $manifest['background_profile']]);
                if (is_array($response) && isset($response['master'], $response['thumb'], $response['original'])) {
                    $workerFiles = $this->generatedFiles($response);
                }
                $this->verifyReviewResponse($response, $manifest, $revision, false);
                $this->verifyImmutableReview($manifest['review']);
                $updated = $this->captureReview($response, ['sha256' => $manifest['source_sha256']], $manifest['review']);
                if ($updated['width'] !== $manifest['review']['width'] || $updated['height'] !== $manifest['review']['height']
                    || $updated['actions'] !== $this->actions($manifest['review']['actions'] ?? [], $updated['width'], $updated['height'])) {
                    throw new RuntimeException('Результат належить іншим позначкам маски.');
                }
                foreach (['status', 'reasons', 'gates', 'regions', 'actions', 'action_results', 'initial_region_count', 'remaining_region_count', 'accounting_required'] as $key) {
                    $manifest['review'][$key] = $updated[$key];
                }
                if (($response['state'] ?? '') === 'needs_review' || $updated['status'] !== 'accepted'
                    || !$this->allReviewGatesAccepted($updated['gates']) || !$this->allReviewActionsAccepted($updated['action_results'])) {
                    $manifest['review']['status'] = 'needs_review';
                    $manifest['result_refused_revision'] = $revision;
                    $this->verifyLiveSource($manifest);
                    $this->writeManifest($manifest['preview_id'], $manifest);
                    return $this->reviewPayload($manifest);
                }
                $workerFiles = $this->generatedFiles($response);
                $data = ProductImageProcessingService::normalizeResult($manifest['source_path'], $response, $manifest['background_profile'], $manifest['mask_mode']);
                if (!hash_equals($manifest['source_sha256'], $data['source_sha256'])
                    || !hash_equals($manifest['source_sha256'], hash_file('sha256', $workerFiles['original']))) {
                    throw new RuntimeException('Обробник змінив оригінал фотографії.');
                }
                $files = [];
                foreach ($workerFiles as $key => $file) {
                    $bytes = $key === 'original' ? $data['source_bytes'] : $data[$key . '_bytes'];
                    if (filesize($file) !== $bytes) { throw new RuntimeException('Некоректний розмір проби маски.'); }
                    $name = $key === 'original' ? 'original.bin' : $key . '.webp';
                    $sha256 = hash_file('sha256', $file);
                    $temporary = $this->directory($manifest['preview_id']) . '/' . $name . '-' . bin2hex(random_bytes(8)) . '.tmp';
                    $this->copyVerified($file, $temporary, $sha256);
                    $temporaryFiles[$key] = $temporary;
                    $files[$key] = ['name' => $name, 'sha256' => $sha256];
                }
                $this->verifyLiveSource($manifest);
                $this->verifyReviewFiles($manifest['review']);
                foreach ($temporaryFiles as $key => $file) {
                    if (!@rename($file, $this->directory($manifest['preview_id']) . '/' . $files[$key]['name'])) {
                        throw new RuntimeException('Не вдалося зберегти пробу маски.');
                    }
                }
                $data['normalization']['mask_review_revision'] = $revision;
                $data['normalization']['mask_review'] = ['status' => 'accepted', 'reasons' => $this->safeReasons($response['review']['reasons'] ?? []),
                    'gates' => $this->safeGates($response['review']['gates'] ?? [])];
                $manifest['data'] = $data;
                $manifest['files'] = $files;
                $manifest['publication_id'] = bin2hex(random_bytes(16));
                $manifest['state'] = 'ready';
                $manifest['review']['status'] = 'accepted';
                $manifest['result_revision'] = $revision;
                $this->writeManifest($manifest['preview_id'], $manifest);
                return $this->readyPayload($manifest);
            } finally {
                foreach ($temporaryFiles as $file) { if (is_file($file) && !is_link($file)) { @unlink($file); } }
                $this->removeWorkerFiles($workerFiles);
            }
        });
    }

    public function reviewFile($imageId, $token, $revision, $asset)
    {
        $revision = $this->revision($revision);
        if (!is_string($asset) || !in_array($asset, ['source', 'alpha', 'raw-alpha'], true)) {
            throw new InvalidArgumentException('Некоректний файл маски.');
        }
        return $this->withReview($imageId, $token, function (&$manifest) use ($revision, $asset) {
            if ($revision !== (int) $manifest['review']['revision']) { throw new InvalidArgumentException('Версія маски застаріла.'); }
            $name = $asset === 'source' ? 'source.png' : ($asset === 'raw-alpha' ? 'raw-alpha.png' : 'alpha-' . $revision . '.png');
            return $this->safeFile($this->reviewPrefix($manifest['review']) . $name);
        });
    }

    private function withReview($imageId, $token, callable $operation)
    {
        $owner = $this->owner();
        $imageId = $this->imageId($imageId);
        $token = $this->token($token);
        $tokenLock = $this->lock('token-' . $token);
        $imageLock = $this->lock('image-' . $imageId);
        try {
            $manifest = $this->readManifest($token);
            $this->verifyOwner($manifest, $owner, $imageId);
            $this->verifyExpiry($manifest);
            if (!in_array($manifest['state'] ?? '', ['ready', 'needs_review'], true) || !is_array($manifest['review'] ?? null)) {
                throw new InvalidArgumentException('Перевірка маски недоступна.');
            }
            $this->verifyLiveSource($manifest);
            $this->verifyReviewClaim($manifest['review']);
            $this->verifyReviewFiles($manifest['review']);
            if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
            return $operation($manifest);
        } finally { $this->unlock($imageLock); $this->unlock($tokenLock); }
    }

    private function newManifest($token, $imageId, array $owner, array $source, $baseline, $background, $mode)
    {
        return ['preview_id' => $token, 'image_id' => $imageId, 'owner_id' => $owner['id'], 'session_hash' => $owner['session_hash'],
            'source_path' => $source['path'], 'source_sha256' => $source['sha256'], 'product_id' => $source['product_id'],
            'baseline' => $baseline, 'created_at' => $this->now(), 'expires_at' => $this->now() + self::TTL,
            'background_profile' => $background, 'mask_mode' => $mode, 'publication_id' => bin2hex(random_bytes(16))];
    }

    private function verifyLiveSource(array $manifest)
    {
        $source = $this->source($manifest['image_id']);
        if ($source['path'] !== $manifest['source_path'] || !hash_equals($source['sha256'], $manifest['source_sha256'])
            || $this->fingerprint(ProductImageProcessing::find($manifest['image_id'])) !== $manifest['baseline']) {
            throw new InvalidArgumentException('Оригінал або поточна фотографія змінилася. Створіть нову пробу.');
        }
    }

    private function captureReview(array $response, array $source, array $previous = [])
    {
        $review = $response['review'] ?? null;
        $job = $response['review_job_id'] ?? ($response['job_id'] ?? null);
        $sha = $response['source_sha256'] ?? ($response['original']['sha256'] ?? null);
        if (!is_array($review) || !is_string($job) || preg_match('/^[a-f0-9]{32}$/D', $job) !== 1
            || ProductImageProcessing::isJobAccepted($job) || !is_string($sha) || !hash_equals($source['sha256'], $sha)) {
            throw new RuntimeException('Некоректні дані перевірки маски.');
        }
        $width = $review['source_width'] ?? ($response['input']['width'] ?? null);
        $height = $review['source_height'] ?? ($response['input']['height'] ?? null);
        if (!is_int($width) || !is_int($height) || min($width, $height) < 1 || max($width, $height) > 8192 || $width * $height > 20000000) {
            throw new RuntimeException('Некоректні розміри маски.');
        }
        $result = ['job_id' => $job, 'revision' => $this->revision($review['revision'] ?? null), 'width' => $width, 'height' => $height,
            'status' => in_array($review['status'] ?? '', ['accepted', 'needs_review'], true) ? $review['status'] : 'needs_review',
            'reasons' => $this->safeReasons($review['reasons'] ?? []), 'regions' => $this->safeRegions($review['regions'] ?? [], $width, $height),
            'gates' => $this->safeGates($review['gates'] ?? []), 'started' => false];
        $result += $this->reviewAccounting($review, $width, $height, $previous);
        foreach (['raw_alpha_sha256', 'source_image_sha256', 'alpha_sha256'] as $key) {
            $hash = $review[$key] ?? null;
            if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) { throw new RuntimeException('Некоректна контрольна сума маски.'); }
            $result[$key] = $hash;
        }
        $this->verifyReviewFiles($result);
        $bytes = 0;
        foreach (glob($this->root . '/' . $this->reviewPrefix($result) . '*') ?: [] as $file) {
            if (!is_file($file) || is_link($file)) { throw new RuntimeException('Небезпечні дані перевірки маски.'); }
            $bytes += filesize($file);
        }
        if ($bytes > 268435456) { throw new RuntimeException('Дані перевірки маски перевищують ліміт.'); }
        return $result;
    }

    private function reviewAccounting(array $review, $width, $height, array $previous = [])
    {
        $hasActions = array_key_exists('actions', $review);
        $hasResults = array_key_exists('action_results', $review);
        $actions = $this->actions($previous['actions'] ?? [], $width, $height);
        $results = $previous['action_results'] ?? [];
        if ($hasActions || $hasResults || !empty($previous['accounting_required'])) {
            if (!$hasActions || !$hasResults) { throw new RuntimeException('Неповні дані позначок маски.'); }
            $actions = $this->actions($review['actions'], $width, $height);
            $rawResults = $review['action_results'];
            if (!is_array($rawResults) || !array_is_list($rawResults) || count($rawResults) !== count($actions)) {
                throw new RuntimeException('Некоректні результати позначок маски.');
            }
            $results = [];
            foreach ($rawResults as $index => $item) {
                $reasons = is_array($item) ? ($item['reasons'] ?? null) : null;
                if (!is_array($item) || ($item['index'] ?? null) !== $index
                    || !in_array($item['status'] ?? '', ['accepted', 'rejected', 'unresolved'], true)
                    || !is_array($reasons) || !array_is_list($reasons) || count($reasons) > 32 || $this->safeReasons($reasons) !== $reasons) {
                    throw new RuntimeException('Некоректний результат позначки маски.');
                }
                $safe = ['index' => $index, 'status' => $item['status'], 'reasons' => $reasons];
                if (array_key_exists('conflicts', $item)) {
                    if (!is_array($item['conflicts']) || !array_is_list($item['conflicts']) || count($item['conflicts']) > 64) {
                        throw new RuntimeException('Некоректні конфлікти позначки маски.');
                    }
                    $safe['conflicts'] = $this->safeRegions($item['conflicts'], $width, $height);
                }
                $results[] = $safe;
            }
        }
        $regions = $review['regions'] ?? [];
        $regionCount = is_array($regions) && array_is_list($regions) ? count($regions) : count($this->safeRegions($regions, $width, $height));
        $remaining = array_key_exists('remaining_region_count', $review) ? $review['remaining_region_count'] : $regionCount;
        if (!is_int($remaining) || $remaining < 0 || $remaining > 4096) {
            throw new RuntimeException('Некоректна кількість областей маски.');
        }
        $initial = array_key_exists('initial_region_count', $review) ? $review['initial_region_count'] : ($previous['initial_region_count'] ?? $regionCount);
        if (!is_int($initial) || $initial < 0 || $initial > 4096 || (isset($previous['initial_region_count']) && $initial !== $previous['initial_region_count'])) {
            throw new RuntimeException('Обробник змінив початкові області маски.');
        }
        return ['actions' => $actions, 'action_results' => $results, 'initial_region_count' => $initial, 'remaining_region_count' => $remaining,
            'accounting_required' => $hasActions || $hasResults || !empty($previous['accounting_required'])];
    }

    private function verifyReviewFiles(array $review)
    {
        $this->verifyImmutableReview($review);
        $file = $this->safeFile($this->reviewPrefix($review) . 'alpha-' . $this->revision($review['revision']) . '.png');
        $this->verifyPng($file, $review['width'], $review['height'], $review['alpha_sha256']);
    }

    private function verifyImmutableReview(array $review)
    {
        $prefix = $this->reviewPrefix($review);
        foreach (['source.png' => 'source_image_sha256', 'raw-alpha.png' => 'raw_alpha_sha256'] as $name => $hashKey) {
            $this->verifyPng($this->safeFile($prefix . $name), $review['width'], $review['height'], $review[$hashKey]);
        }
    }

    private function verifyPng($file, $width, $height, $sha)
    {
        $info = @getimagesize($file);
        if (!$info || $info[0] !== $width || $info[1] !== $height || ($info[2] ?? 0) !== IMAGETYPE_PNG
            || !is_string($sha) || !hash_equals($sha, hash_file('sha256', $file))) {
            throw new InvalidArgumentException('Файл перевірки маски змінився.');
        }
    }

    private function reviewPrefix(array $review)
    {
        $job = $review['job_id'] ?? '';
        if (!is_string($job) || preg_match('/^[a-f0-9]{32}$/D', $job) !== 1) { throw new RuntimeException('Некоректна перевірка маски.'); }
        return 'storage/image-processor/reviews/' . $job . '/';
    }

    private function workerReviewPayload(array $manifest)
    {
        return ['job_id' => $manifest['review']['job_id'], 'source_sha256' => $manifest['source_sha256'], 'revision' => (int) $manifest['review']['revision']];
    }

    private function verifyReviewResponse($response, array $manifest, $revision, $requireReviewJob = true)
    {
        if (!is_array($response) || empty($response['ok']) || ($response['source_sha256'] ?? '') !== $manifest['source_sha256']
            || !is_array($response['review'] ?? null) || ($response['review']['revision'] ?? null) !== $revision
            || ($requireReviewJob && ($response['job_id'] ?? '') !== $manifest['review']['job_id'])) {
            throw new RuntimeException('Обробник повернув іншу версію маски.');
        }
        if (!$requireReviewJob) {
            if (($response['review_job_id'] ?? ($response['job_id'] ?? '')) !== $manifest['review']['job_id']) {
                throw new RuntimeException('Результат належить іншій перевірці маски.');
            }
            foreach (['raw_alpha_sha256', 'source_image_sha256', 'alpha_sha256'] as $key) {
                if (($response['review'][$key] ?? '') !== $manifest['review'][$key]) {
                    throw new RuntimeException('Результат належить іншому файлу маски.');
                }
            }
        }
    }

    private function chargeReviewTask(array &$manifest)
    {
        $attempts = (int) ($manifest['worker_attempts'] ?? 0);
        if ($attempts >= 64) { throw new InvalidArgumentException('Ліміт запитів перевірки маски вичерпано. Створіть нову пробу.'); }
        $manifest['worker_attempts'] = $attempts + 1;
        $this->writeManifest($manifest['preview_id'], $manifest);
    }

    private function revision($value)
    {
        if ((!is_int($value) && !is_string($value)) || preg_match('/^(0|[1-9][0-9]{0,2})$/D', (string) $value) !== 1 || (int) $value > 32) {
            throw new InvalidArgumentException('Некоректна версія маски.');
        }
        return (int) $value;
    }

    private function actions($value, $width, $height)
    {
        if (is_string($value)) {
            if (strlen($value) > 131072) { throw new InvalidArgumentException('Позначки маски перевищують ліміт.'); }
            if (preg_match('/^\s*\[/', $value) !== 1) { throw new InvalidArgumentException('Некоректні позначки маски.'); }
            $value = json_decode($value, true, 16);
        }
        if (!is_array($value) || (count($value) > 0 && array_keys($value) !== range(0, count($value) - 1)) || count($value) > 64) {
            throw new InvalidArgumentException('Некоректні позначки маски.');
        }
        $pointCount = 0;
        $result = [];
        foreach ($value as $action) {
            if (!is_array($action) || count(array_diff(array_keys($action), ['kind', 'tool', 'radius', 'hardness', 'points'])) !== 0
                || !in_array($action['kind'] ?? '', ['foreground', 'background', 'unsure'], true)
                || !in_array($action['tool'] ?? '', ['brush', 'point'], true)) { throw new InvalidArgumentException('Некоректна дія маски.'); }
            $radius = $action['radius'] ?? null;
            $hardness = $action['hardness'] ?? 1;
            if (!is_int($radius) || $radius < 1 || $radius > ($action['tool'] === 'point' ? 12 : 80)
                || (!is_int($hardness) && !is_float($hardness)) || !is_finite($hardness) || $hardness < .25 || $hardness > 1
                || ($action['kind'] === 'unsure' && $action['tool'] !== 'point')) { throw new InvalidArgumentException('Некоректний пензель маски.'); }
            $points = $action['points'] ?? null;
            if (!is_array($points) || count($points) < 1 || count($points) > 512 || array_keys($points) !== range(0, count($points) - 1)
                || ($action['tool'] === 'point' && count($points) !== 1)) { throw new InvalidArgumentException('Некоректні координати маски.'); }
            foreach ($points as $point) {
                if (!is_array($point) || array_keys($point) !== [0, 1] || !is_int($point[0]) || !is_int($point[1])
                    || $point[0] < 0 || $point[1] < 0 || $point[0] >= $width || $point[1] >= $height) { throw new InvalidArgumentException('Позначка виходить за межі фотографії.'); }
            }
            $pointCount += count($points);
            if ($pointCount > 2048) { throw new InvalidArgumentException('Забагато координат маски.'); }
            $result[] = ['kind' => $action['kind'], 'tool' => $action['tool'], 'radius' => $radius, 'hardness' => (float) $hardness, 'points' => $points];
        }
        return $result;
    }

    private function allReviewGatesAccepted($gates)
    {
        $safe = $this->safeGates($gates);
        foreach (['component', 'local', 'thin', 'background'] as $name) {
            if (($safe[$name]['accepted'] ?? false) !== true) { return false; }
        }
        return true;
    }

    private function allReviewActionsAccepted(array $results)
    {
        foreach ($results as $item) {
            if (($item['status'] ?? '') !== 'accepted') { return false; }
        }
        return true;
    }

    private function safeGates($gates)
    {
        $result = [];
        foreach (['component', 'local', 'thin', 'background'] as $name) {
            $gate = is_array($gates) && is_array($gates[$name] ?? null) ? $gates[$name] : [];
            if (!is_bool($gate['accepted'] ?? null)) { continue; }
            $result[$name] = ['accepted' => $gate['accepted'], 'reasons' => $this->safeReasons($gate['reasons'] ?? [])];
        }
        return $result;
    }

    private function safeReasons($reasons)
    {
        $result = [];
        foreach (is_array($reasons) ? array_slice($reasons, 0, 32) : [] as $reason) {
            if (is_string($reason) && preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/iD', $reason) === 1) { $result[] = $reason; }
        }
        return $result;
    }

    private function safeRegions($regions, $width, $height)
    {
        $result = [];
        foreach (is_array($regions) ? array_slice($regions, 0, 64) : [] as $region) {
            if (!is_array($region)) { continue; }
            $bbox = $region['bbox'] ?? null;
            if (!is_array($bbox) || array_keys($bbox) !== [0, 1, 2, 3] || count(array_filter($bbox, 'is_int')) !== 4
                || min($bbox) < 0 || $bbox[2] < 1 || $bbox[3] < 1 || $bbox[0] + $bbox[2] > $width || $bbox[1] + $bbox[3] > $height) { continue; }
            $safe = ['bbox' => $bbox, 'reason' => $this->safeReasons([$region['reason'] ?? ''])[0] ?? 'mask_quality_rejected'];
            $polygon = $region['polygon'] ?? null;
            if (is_array($polygon) && count($polygon) <= 128) {
                $safe['polygon'] = [];
                foreach ($polygon as $point) {
                    if (is_array($point) && array_keys($point) === [0,1] && is_int($point[0]) && is_int($point[1]) && $point[0] >= 0 && $point[0] < $width && $point[1] >= 0 && $point[1] < $height) { $safe['polygon'][] = $point; }
                }
            }
            $result[] = $safe;
        }
        return $result;
    }

    private function reviewPayload(array $manifest)
    {
        $review = $manifest['review'];
        $query = 'image_id=' . $manifest['image_id'] . '&preview_id=' . $manifest['preview_id'] . '&revision=' . $review['revision'];
        return ['success' => true, 'state' => 'needs_review', 'image_id' => $manifest['image_id'], 'preview_id' => $manifest['preview_id'],
            'expires_at' => gmdate('c', $manifest['expires_at']), 'review_available' => true, 'revision' => $review['revision'],
            'review' => ['width' => $review['width'], 'height' => $review['height'], 'revision' => $review['revision'], 'status' => $review['status'],
                'reasons' => $review['reasons'], 'regions' => $review['regions'], 'gates' => $review['gates'] ?? [],
                'actions' => $this->actions($review['actions'] ?? [], $review['width'], $review['height']),
                'action_results' => $review['action_results'] ?? [], 'initial_region_count' => $review['initial_region_count'] ?? count($review['regions']),
                'remaining_region_count' => $review['remaining_region_count'] ?? count($review['regions']),
                'source_url' => '/Anabelka/admin/products/image-process-mask-file?' . $query . '&asset=source',
                'raw_alpha_url' => '/Anabelka/admin/products/image-process-mask-file?' . $query . '&asset=raw-alpha',
                'alpha_url' => '/Anabelka/admin/products/image-process-mask-file?' . $query . '&asset=alpha']];
    }

    private function readyPayload(array $manifest)
    {
        $processing = array_merge($manifest['data'], ['status' => 'ready', 'image_id' => $manifest['image_id']]);
        foreach (['original_path', 'master_path', 'thumb_path', 'source_path'] as $key) { unset($processing[$key]); }
        return ['success' => true, 'state' => 'ready', 'image_id' => $manifest['image_id'], 'preview_id' => $manifest['preview_id'],
            'expires_at' => gmdate('c', $manifest['expires_at']), 'processing' => $processing, 'revision' => $manifest['review']['revision'],
            'review_available' => true, 'review' => $this->reviewPayload($manifest)['review'],
            'preview_url' => '/Anabelka/admin/products/image-process-preview-file?image_id=' . $manifest['image_id'] . '&preview_id=' . $manifest['preview_id'] . '&revision=' . $manifest['review']['revision']];
    }

    private function checkQuota(array $owner)
    {
        $active = 0;
        $owned = 0;
        foreach (glob($this->temporary . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
            if (is_link($directory) || preg_match('/^[a-f0-9]{64}$/D', basename($directory)) !== 1) { continue; }
            $path = $directory . '/manifest.json';
            $manifest = is_file($path) && !is_link($path) ? json_decode(file_get_contents($path), true) : null;
            if (is_array($manifest) && (in_array($manifest['state'] ?? '', ['cancelled', 'confirmed'], true) || empty($manifest['review']))) { continue; }
            $active++;
            if (!is_array($manifest) || (int) ($manifest['owner_id'] ?? 0) === $owner['id']) { $owned++; }
        }
        if ($active >= 24 || $owned >= 4) { throw new InvalidArgumentException('Забагато відкритих проб фотографій. Закрийте попередні проби.'); }
    }

    private function claimReview(array $review)
    {
        $directory = $this->root . '/' . rtrim($this->reviewPrefix($review), '/');
        if (!is_dir($directory) || is_link($directory) || realpath($directory) !== $directory) {
            throw new RuntimeException('Небезпечний каталог перевірки маски.');
        }
        $path = $directory . '/php-owner.json';
        $handle = @fopen($path, 'x+b');
        if (!$handle) { throw new RuntimeException('Перевірка маски вже належить іншій пробі.'); }
        try {
            $json = json_encode(['preview_id' => $review['preview_id'], 'expires_at' => (int) $review['expires_at']], JSON_THROW_ON_ERROR);
            if (!@chmod($path, 0600) || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
                throw new RuntimeException('Не вдалося закріпити перевірку маски.');
            }
        } finally { fclose($handle); }
    }

    private function verifyReviewClaim(array $review)
    {
        $file = $this->safeFile($this->reviewPrefix($review) . 'php-owner.json');
        $owner = json_decode(file_get_contents($file), true);
        if (!is_array($owner) || ($owner['preview_id'] ?? '') !== ($review['preview_id'] ?? null)) {
            throw new InvalidArgumentException('Перевірка маски належить іншій пробі.');
        }
    }

    private function removeReviewFiles($review)
    {
        if (!is_array($review) || empty($review['preview_id'])) { return; }
        try { $this->verifyReviewClaim($review); } catch (Throwable $e) { return; }
        // A timed-out HTTP request may still hold the worker's review lock.
        // The worker alone deletes under that lock; failed/busy deletion retains the claim for TTL cleanup.
        try {
            call_user_func($this->reviewWorker, 'discard', [
                'job_id' => $review['job_id'], 'preview_id' => $review['preview_id']
            ]);
        } catch (Throwable $e) { return; }
    }

    private function owner()
    {
        $id = AdminAccess::currentId();
        $session = session_id();
        if ($id <= 0 || $session === '' || !AdminAccess::can('products.manage')) {
            throw new InvalidArgumentException('Недостатньо прав для обробки фотографії.');
        }
        return ['id' => $id, 'session_hash' => hash('sha256', $session)];
    }

    private function cleanupUnpublished(array $manifest)
    {
        if (($manifest['state'] ?? '') === 'confirmed') { return true; }
        $imageId = $this->imageId($manifest['image_id'] ?? 0);
        $lock = $this->lock('image-' . $imageId, false);
        if (!$lock) { return false; }
        $db = Database::connect();
        try {
            // A failed replay-marker write can leave state=ready after acceptance.
            // Only an unchanged live baseline proves these are interrupted precommit files.
            $db->beginTransaction();
            $statement = $db->prepare('SELECT id, product_id, path FROM product_gallery_images WHERE id = :image_id LIMIT 1 FOR UPDATE');
            $statement->execute(['image_id' => $imageId]);
            if (!$statement->fetch(PDO::FETCH_ASSOC)
                || $this->fingerprint(ProductImageProcessing::find($imageId)) !== ($manifest['baseline'] ?? '')
                || ProductImageProcessing::isJobAccepted($manifest['data']['job_id'] ?? '')
            ) { $db->rollBack(); return true; }
            $data = $this->publicationData($manifest);
            $files = [];
            foreach (['original', 'master', 'thumb'] as $key) {
                $absolute = $this->root . '/' . $data[$key . '_path'];
                if (!file_exists($absolute) && !is_link($absolute)) { continue; }
                $file = $this->safeFile($data[$key . '_path']);
                $files[] = $file;
            }
            foreach ($files as $file) {
                if (!@unlink($file)) { throw new RuntimeException('Не вдалося видалити незавершену пробу фотографії.'); }
            }
            foreach (array_unique(array_map('dirname', $files)) as $directory) { @rmdir($directory); }
            $db->commit();
            return true;
        } catch (Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            // Uncertain publication ownership always preserves the final files.
            error_log('Image preview orphan cleanup: ' . $e->getMessage());
            return false;
        } finally { $this->unlock($lock); }
    }

    private function imageId($value)
    {
        if ((!is_int($value) && !is_string($value)) || preg_match('/^[1-9][0-9]*$/', (string) $value) !== 1 || (int) $value <= 0) {
            throw new InvalidArgumentException('Некоректна фотографія товару.');
        }
        return (int) $value;
    }

    private function token($value)
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw new InvalidArgumentException('Некоректна проба фотографії.');
        }
        return $value;
    }

    private function source($imageId, $image = false)
    {
        if ($image === false) { $image = ProductImage::findById($imageId); }
        if (!$image) { throw new InvalidArgumentException('Фотографію товару не знайдено.'); }
        $path = trim((string) ($image['path'] ?? ''));
        if (strpos($path, '/Anabelka/') === 0) { $path = substr($path, strlen('/Anabelka/')); }
        $path = ltrim($path, '/');
        if (preg_match('#^uploads/products/[A-Za-z0-9._-]+$#', $path) !== 1 || strpos($path, '..') !== false) {
            throw new InvalidArgumentException('Некоректний шлях до фотографії товару.');
        }
        $file = $this->safeFile($path);
        return ['path' => $path, 'sha256' => hash_file('sha256', $file), 'product_id' => (int) $image['product_id']];
    }

    private function safeFile($relative)
    {
        if (!is_string($relative) || strpos($relative, "\0") !== false || strpos($relative, '..') !== false || strpos($relative, '\\') !== false || strpos($relative, '/') === 0) {
            throw new RuntimeException('Небезпечний шлях до файла обробки.');
        }
        $absolute = $this->root . '/' . $relative;
        $real = realpath($absolute);
        if (!$real || $real !== $absolute || !is_file($absolute) || is_link($absolute)) {
            throw new RuntimeException('Файл обробки недоступний.');
        }
        return $absolute;
    }

    private function publicationData(array $manifest)
    {
        $data = $manifest['data'];
        $id = $manifest['publication_id'];
        if (preg_match('/^[a-f0-9]{32}$/', $id) !== 1) { throw new RuntimeException('Некоректні дані публікації.'); }
        $data['original_path'] = 'storage/image-processor/originals/' . $id . '/original.bin';
        $data['master_path'] = 'uploads/products/processed/' . $id . '/master.webp';
        $data['thumb_path'] = 'uploads/products/processed/' . $id . '/thumb.webp';
        return $data;
    }

    private function generatedFiles(array $response)
    {
        $jobId = $response['job_id'] ?? null;
        if (!is_string($jobId) || preg_match('/^[a-f0-9]{32}$/', $jobId) !== 1 || ProductImageProcessing::isJobAccepted($jobId)) {
            throw new RuntimeException('Некоректний або вже опублікований результат обробника.');
        }
        $paths = [];
        foreach (['original', 'master', 'thumb'] as $key) {
            $path = $response[$key]['path'] ?? null;
            $pattern = $key === 'original'
                ? '#^storage/image-processor/originals/' . $jobId . '/[A-Za-z0-9._-]+$#'
                : '#^uploads/products/processed/' . $jobId . '/' . $key . '\\.webp$#';
            if (!is_string($path) || preg_match($pattern, $path) !== 1) {
                throw new RuntimeException('Небезпечний шлях результату обробника.');
            }
            $paths[$key] = $this->safeFile($path);
        }
        return $paths;
    }

    private function verifiedFiles($token, array $manifest)
    {
        $files = [];
        foreach (['original' => 'original.bin', 'master' => 'master.webp', 'thumb' => 'thumb.webp'] as $key => $name) {
            $file = $this->safeFile('storage/image-processor/previews/' . $token . '/' . $name);
            $sha256 = $manifest['files'][$key]['sha256'] ?? '';
            if (!is_string($sha256) || !hash_equals($sha256, hash_file('sha256', $file))) {
                throw new InvalidArgumentException('Файл проби фотографії змінився.');
            }
            $files[$key] = $file;
        }
        return $files;
    }

    private function fingerprint($row)
    {
        if (!$row) { return hash('sha256', 'empty'); }
        $values = [];
        foreach (['status', 'job_id', 'source_path', 'master_path', 'thumb_path', 'processed_at'] as $key) { $values[$key] = $row[$key] ?? null; }
        return hash('sha256', json_encode($values));
    }

    private function verifyOwner(array $manifest, array $owner, $imageId)
    {
        if ((int) ($manifest['image_id'] ?? 0) !== $imageId || (int) ($manifest['owner_id'] ?? 0) !== $owner['id'] || !hash_equals((string) ($manifest['session_hash'] ?? ''), $owner['session_hash'])) {
            throw new InvalidArgumentException('Проба фотографії недоступна для цього адміністратора.');
        }
    }

    private function verifyExpiry(array $manifest)
    {
        if ((int) ($manifest['expires_at'] ?? 0) <= $this->now()) {
            throw new InvalidArgumentException('Термін проби фотографії минув. Створіть нову пробу.');
        }
    }

    private function readManifest($token)
    {
        $file = $this->safeFile('storage/image-processor/previews/' . $token . '/manifest.json');
        $manifest = json_decode(file_get_contents($file), true);
        if (!is_array($manifest) || ($manifest['preview_id'] ?? '') !== $token) {
            throw new InvalidArgumentException('Пробу фотографії не знайдено.');
        }
        return $manifest;
    }

    private function writeManifest($token, array $manifest)
    {
        $directory = $this->directory($token);
        $json = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temporary = $directory . '/manifest-' . bin2hex(random_bytes(8)) . '.tmp';
        // Token operations already hold their lock. The unique, exclusively
        // created temporary file needs no stream lock (unsupported by KSWEB).
        $handle = @fopen($temporary, 'x+b');
        if (!$handle) { throw new RuntimeException('Не вдалося зберегти пробу фотографії.'); }
        try {
            if (!@chmod($temporary, 0600)) { throw new RuntimeException('Не вдалося зберегти пробу фотографії.'); }
            $length = strlen($json);
            for ($offset = 0; $offset < $length; $offset += $written) {
                $written = @fwrite($handle, substr($json, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Не вдалося зберегти пробу фотографії.');
                }
            }
            if (!@fflush($handle)) { throw new RuntimeException('Не вдалося зберегти пробу фотографії.'); }
            $closed = @fclose($handle);
            $handle = null;
            if (!$closed || !@rename($temporary, $directory . '/manifest.json')) {
                throw new RuntimeException('Не вдалося зберегти пробу фотографії.');
            }
        } catch (Throwable $e) {
            try {
                if (is_resource($handle)) { @fclose($handle); }
            } finally { @unlink($temporary); }
            throw $e;
        }
    }

    private function copyVerified($source, $destination, $sha256)
    {
        $input = @fopen($source, 'rb');
        $output = @fopen($destination, 'x+b');
        if (!$input || !$output) {
            if ($input) { fclose($input); }
            if ($output) { fclose($output); }
            throw new RuntimeException('Не вдалося записати результат обробки фотографії.');
        }
        try {
            if (stream_copy_to_stream($input, $output) === false || !fflush($output)) {
                throw new RuntimeException('Не вдалося записати результат обробки фотографії.');
            }
        } catch (Throwable $e) {
            fclose($input); fclose($output); @unlink($destination); throw $e;
        }
        fclose($input); fclose($output);
        chmod($destination, 0600);
        if (!hash_equals($sha256, hash_file('sha256', $destination))) {
            @unlink($destination);
            throw new RuntimeException('Контрольна сума результату обробки не збігається.');
        }
    }

    private function mkdir($directory, $recursive = true)
    {
        $parent = dirname($directory);
        if (!is_dir($parent)) { $this->mkdir($parent); }
        if (is_link($parent) || realpath($parent) !== $parent) {
            throw new RuntimeException('Небезпечний каталог результату фотографії.');
        }
        if ($recursive && is_dir($directory) && !is_link($directory)) { return; }
        if (file_exists($directory) || is_link($directory)) {
            throw new RuntimeException('Каталог результату фотографії недоступний.');
        }
        if (!@mkdir($directory, 0700)) {
            throw new RuntimeException('Не вдалося створити каталог результату фотографії.');
        }
    }

    private function lock($name, $blocking = true)
    {
        // Stable stripes avoid accumulating one permanent inode for each expired token.
        if (strpos($name, 'token-') === 0) { $name = substr($name, 0, 8); }
        if (strpos($name, 'image-') === 0) { $name = 'image-' . ((int) substr($name, 6) % 256); }
        $directory = $this->temporary . '/locks';
        $this->mkdir($directory);
        $path = $directory . '/' . $name . '.lock';
        if (is_link($path)) { throw new RuntimeException('Некоректний файл блокування.'); }
        $handle = @fopen($path, 'c+b');
        if (!$handle) { throw new RuntimeException('Не вдалося заблокувати пробу фотографії.'); }
        if (!flock($handle, LOCK_EX | ($blocking ? 0 : LOCK_NB))) {
            fclose($handle);
            return null;
        }
        return $handle;
    }

    private function unlock($handle)
    {
        if ($handle) { flock($handle, LOCK_UN); fclose($handle); }
    }

    private function removeWorkerFiles(array $files)
    {
        foreach ($files as $file) { if (is_file($file) && !is_link($file)) { @unlink($file); } }
        foreach (array_unique(array_map('dirname', $files)) as $directory) { @rmdir($directory); }
    }

    private function removeStagedFiles($token)
    {
        foreach (['original.bin', 'master.webp', 'thumb.webp'] as $name) {
            $file = $this->directory($token) . '/' . $name;
            if (is_file($file) || is_link($file)) { @unlink($file); }
        }
    }

    private function removeTemporary($token)
    {
        if (!is_dir($this->temporary) || is_link($this->temporary)) { return; }
        $directory = $this->directory($this->token($token));
        if (!is_dir($directory) || is_link($directory)) { return; }
        foreach (glob($directory . '/*') ?: [] as $file) {
            if (is_file($file) || is_link($file)) { @unlink($file); }
        }
        @rmdir($directory);
    }

    private function directory($token) { return $this->temporary . '/' . $token; }
    private function now() { return (int) call_user_func($this->clock); }
}
