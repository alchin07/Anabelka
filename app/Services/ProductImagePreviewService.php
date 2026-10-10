<?php

class ProductImagePreviewService
{
    private const TTL = 1800;
    private $root;
    private $temporary;
    private $processor;
    private $clock;

    public function __construct($projectRoot = null, ?callable $processor = null, ?callable $clock = null)
    {
        $this->root = realpath($projectRoot ?? dirname(__DIR__, 2));
        if ($this->root === false) {
            throw new RuntimeException('Каталог фотографій недоступний.');
        }
        $this->temporary = $this->root . '/storage/image-processor/previews';
        $this->processor = $processor ?? [ImageProcessorClient::class, 'processProductImage'];
        $this->clock = $clock ?? 'time';
    }

    public function create($imageId, $backgroundProfile = 'original-canvas', $maskMode = 'auto')
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
        try {
            $source = $this->source($imageId);
            $baseline = $this->fingerprint(ProductImageProcessing::find($imageId));
            if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
            $response = call_user_func($this->processor, $source['path'], $backgroundProfile, $maskMode);
            if (!is_array($response)) {
                throw new RuntimeException('Некоректна відповідь обробника фотографій.');
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
            $this->mkdir($directory, false);
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
                'created_at' => $this->now(), 'expires_at' => $this->now() + self::TTL,
                'state' => 'ready', 'data' => $data, 'files' => $files,
                // Publication uses fresh destinations, never paths owned by a previous photo.
                'publication_id' => bin2hex(random_bytes(16))
            ];
            $this->writeManifest($token, $manifest);
            $this->removeWorkerFiles($workerFiles);
            $workerFiles = [];
            $processing = array_merge($data, ['status' => 'ready', 'image_id' => $imageId]);
            // No public worker paths are returned for an unaccepted preview.
            foreach (['original_path', 'master_path', 'thumb_path'] as $key) {
                unset($processing[$key]);
            }
            return [
                'success' => true, 'image_id' => $imageId, 'preview_id' => $token,
                'expires_at' => gmdate('c', $manifest['expires_at']),
                'processing' => $processing,
                'preview_url' => '/Anabelka/admin/products/image-process-preview-file?image_id=' . $imageId . '&preview_id=' . $token
            ];
        } catch (Throwable $e) {
            $this->removeWorkerFiles($workerFiles);
            $this->removeTemporary($token);
            throw $e;
        } finally {
            $this->unlock($tokenLock);
            $this->unlock($imageLock);
        }
    }

    public function confirm($imageId, $token)
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
            }
            return ['success' => true, 'image_id' => $imageId];
        } finally { $this->unlock($lock); }
    }

    public function previewFile($imageId, $token)
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
                    if (is_array($manifest) && !$this->cleanupUnpublished($manifest)) { continue; }
                    // Temporary deletion never follows paths supplied in the manifest.
                    $this->removeTemporary($token);
                }
            } finally { $this->unlock($lock); }
        }
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
