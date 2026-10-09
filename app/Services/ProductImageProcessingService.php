<?php

class ProductImageProcessingService
{
    public static function process(
        $imageId,
        $backgroundProfile = 'original-canvas',
        $maskMode = 'auto'
    ) {
        // Legacy clients accept immediately, through the same safe publication path.
        $service = new ProductImagePreviewService();
        $preview = $service->create($imageId, $backgroundProfile, $maskMode);
        return $service->confirm($imageId, $preview['preview_id']);
    }


    public static function preview($imageId, $backgroundProfile = 'original-canvas', $maskMode = 'auto')
    {
        return (new ProductImagePreviewService())->create($imageId, $backgroundProfile, $maskMode);
    }


    public static function normalizeResult(
        $sourcePath,
        array $response,
        $requestedBackground = null,
        $requestedMaskMode = null
    ) {
        $jobId = strtolower(trim((string) (
            $response['job_id'] ?? ''
        )));
        $processorVersion = trim((string) (
            $response['processor_version'] ?? ''
        ));
        $processingProfile = strtolower(trim((string) (
            $response['profile'] ?? ''
        )));

        if (
            preg_match('/^[a-f0-9]{32}$/', $jobId) !== 1
            || $processorVersion === ''
        ) {
            throw new RuntimeException(
                'Обробник повернув неповні службові дані.'
            );
        }

        if (
            $processingProfile !== ''
            && preg_match(
                '/^[a-z0-9][a-z0-9._-]{0,31}$/',
                $processingProfile
            ) !== 1
        ) {
            throw new RuntimeException(
                'Обробник повернув некоректний профіль обробки.'
            );
        }

        $storedProcessorVersion = $processorVersion
            . (
                $processingProfile !== ''
                    ? ':' . $processingProfile
                    : ''
            );

        if (strlen($storedProcessorVersion) > 40) {
            throw new RuntimeException(
                'Версія обробника перевищує допустиму довжину.'
            );
        }

        $expectedSource = self::normalizeSourcePath($sourcePath);
        $returnedSource = self::normalizeSourcePath(
            $response['source'] ?? ''
        );

        if ($expectedSource !== $returnedSource) {
            throw new RuntimeException(
                'Обробник повернув результат для іншого файла.'
            );
        }

        $original = is_array($response['original'] ?? null)
            ? $response['original']
            : [];
        $input = is_array($response['input'] ?? null)
            ? $response['input']
            : [];
        $master = is_array($response['master'] ?? null)
            ? $response['master']
            : [];
        $thumb = is_array($response['thumb'] ?? null)
            ? $response['thumb']
            : [];
        $normalization = is_array(
            $response['normalization'] ?? null
        ) ? $response['normalization'] : [];
        $normalizationMethod = strtolower(trim((string) (
            $normalization['method'] ?? ''
        )));
        $allowedBackgroundProfiles = [
            'original-canvas',
            'studio-light',
            'anabelka-brand'
        ];
        $backgroundProfile = strtolower(trim((string) (
            $normalization['background_profile'] ?? 'original-canvas'
        )));
        $backgroundProfileRequested = strtolower(trim((string) (
            $normalization['background_profile_requested']
            ?? $backgroundProfile
        )));

        if (
            !in_array(
                $backgroundProfile,
                $allowedBackgroundProfiles,
                true
            )
            || !in_array(
                $backgroundProfileRequested,
                $allowedBackgroundProfiles,
                true
            )
        ) {
            throw new RuntimeException(
                'Обробник повернув невідомий профіль фону.'
            );
        }

        $maskModeRequested = strtolower(trim((string) ($normalization['mask_mode_requested'] ?? '')));
        $maskMethod = strtolower(trim((string) ($normalization['mask_method'] ?? '')));
        $workerError = $normalization['worker_error'] ?? null;
        if ($workerError !== null && !is_string($workerError)) {
            throw new RuntimeException('Некоректна діагностика помилки обробника.');
        }
        $workerError = $workerError === null ? null : substr(trim($workerError), 0, 500);
        if ($requestedMaskMode !== null) {
            if (
                $backgroundProfileRequested !== $requestedBackground
                || $maskModeRequested !== $requestedMaskMode
                || $normalizationMethod === ''
                || !in_array($maskMethod, ['none', 'opencv-grabcut', 'modnet'], true)
                || ($normalization['processor_version'] ?? '') !== $processorVersion
                || ($requestedMaskMode === 'modnet' && $requestedBackground !== 'original-canvas' && !empty($workerError))
                || ($backgroundProfile !== $backgroundProfileRequested && empty($normalization['background_fallback']))
            ) {
                throw new RuntimeException('Обробник повернув некоректну діагностику запитаного методу.');
            }
            if ($requestedBackground !== 'original-canvas' && $requestedMaskMode !== 'auto') {
                $expectedMethod = $requestedMaskMode === 'grabcut' ? 'opencv-grabcut' : 'modnet';
                if ($maskMethod !== $expectedMethod || empty($normalization['subject_mask_applied'])) {
                    throw new RuntimeException('Обробник не застосував запитаний метод маски.');
                }
            }
        }

        $allowedNormalizationMethods = [
            'mediapipe-persondet',
            'mediapipe-persondet-no-crop',
            'opencv-haar-face-subject',
            'opencv-haar-face-subject-no-crop',
            'opencv-hog-person',
            'opencv-hog-person-no-crop',
            'person-detected-no-crop',
            'standard-canvas-fallback'
        ];

        if (
            $normalizationMethod !== ''
            && !in_array(
                $normalizationMethod,
                $allowedNormalizationMethods,
                true
            )
        ) {
            throw new RuntimeException(
                'Обробник повернув невідомий метод нормалізації.'
            );
        }

        $subjectDetected = (
            $normalization['subject_detected'] ?? false
        ) === true;
        $cropApplied = (
            $normalization['crop_applied'] ?? false
        ) === true;
        $zoomOutApplied = (
            $normalization['zoom_out_applied'] ?? false
        ) === true;

        if (
            ($cropApplied || $zoomOutApplied)
            && !$subjectDetected
        ) {
            throw new RuntimeException(
                'Обробник повернув суперечливу діагностику нормалізації.'
            );
        }

        if ($cropApplied && $zoomOutApplied) {
            throw new RuntimeException(
                'Обробник одночасно повернув crop і zoom-out.'
            );
        }

        $normalizedDiagnostics = [];

        if ($normalizationMethod !== '') {
            $normalizedDiagnostics = [
                'method' => $normalizationMethod,
                'subject_detected' => $subjectDetected,
                'crop_applied' => $cropApplied,
                'zoom_out_applied' => $zoomOutApplied,
                'background_profile' => $backgroundProfile,
                'background_profile_requested' =>
                    $backgroundProfileRequested,
                'background_fallback' => (
                    $normalization['background_fallback'] ?? false
                ) === true,
                'subject_mask_applied' => (
                    $normalization['subject_mask_applied'] ?? false
                ) === true,
                'mask_mode_requested' => $maskModeRequested,
                'processor_version' => $processorVersion,
                'worker_error' => $workerError,
                'shadow_applied' => (
                    $normalization['shadow_applied'] ?? false
                ) === true
            ];

            foreach (['person_bbox', 'crop_box'] as $boxKey) {
                $box = $normalization[$boxKey] ?? null;

                if (!is_array($box) || count($box) !== 4) {
                    continue;
                }

                $values = array_map('intval', array_values($box));

                if (min($values) < 0) {
                    continue;
                }

                $normalizedDiagnostics[$boxKey] = $values;
            }

            $maskMethod = strtolower(trim((string) (
                $normalization['mask_method'] ?? ''
            )));

            if (
                in_array(
                    $maskMethod,
                    [
                        'none',
                        'opencv-grabcut',
                        'modnet'
                    ],
                    true
                )
            ) {
                $normalizedDiagnostics['mask_method'] = $maskMethod;
            }

            if (isset($normalization['person_score'])) {
                $score = (float) $normalization['person_score'];

                if (is_finite($score)) {
                    $normalizedDiagnostics['person_score'] = max(
                        0.0,
                        min(1.0, $score)
                    );
                }
            }

            $cropStrategy = strtolower(trim((string) (
                $normalization['crop_strategy'] ?? ''
            )));

            if (
                in_array(
                    $cropStrategy,
                    [
                        'subject-bbox',
                        'aspect-fill',
                        'torso-normalize',
                        'torso-zoom-out'
                    ],
                    true
                )
            ) {
                $normalizedDiagnostics['crop_strategy'] =
                    $cropStrategy;
            }

            foreach (
                [
                    'torso_ratio_before',
                    'torso_target_ratio',
                    'torso_ratio_after',
                    'zoom_scale',
                    'mask_foreground_ratio'
                ] as $ratioKey
            ) {
                if (!isset($normalization[$ratioKey])) {
                    continue;
                }

                $ratio = (float) $normalization[$ratioKey];

                if (is_finite($ratio)) {
                    $normalizedDiagnostics[$ratioKey] = max(
                        0.0,
                        min(1.0, $ratio)
                    );
                }
            }
        }

        $sha256 = strtolower(trim((string) (
            $original['sha256'] ?? ''
        )));

        if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw new RuntimeException(
                'Некоректна контрольна сума оригіналу.'
            );
        }

        return [
            'source_path' => $expectedSource,
            'job_id' => $jobId,
            'processor_version' => $storedProcessorVersion,
            'source_sha256' => $sha256,
            'source_bytes' => self::positiveInt(
                $original['bytes'] ?? 0,
                'Некоректний розмір оригіналу.'
            ),
            'source_width' => self::positiveInt(
                $input['width'] ?? 0,
                'Некоректна ширина оригіналу.'
            ),
            'source_height' => self::positiveInt(
                $input['height'] ?? 0,
                'Некоректна висота оригіналу.'
            ),
            'original_path' => self::resultPath(
                $original['path'] ?? '',
                'originals',
                $jobId,
                null
            ),
            'master_path' => self::resultPath(
                $master['path'] ?? '',
                'processed',
                $jobId,
                'master.webp'
            ),
            'master_width' => self::positiveInt(
                $master['width'] ?? 0,
                'Некоректна ширина master.'
            ),
            'master_height' => self::positiveInt(
                $master['height'] ?? 0,
                'Некоректна висота master.'
            ),
            'master_bytes' => self::positiveInt(
                $master['bytes'] ?? 0,
                'Некоректний розмір master.'
            ),
            'thumb_path' => self::resultPath(
                $thumb['path'] ?? '',
                'processed',
                $jobId,
                'thumb.webp'
            ),
            'thumb_width' => self::positiveInt(
                $thumb['width'] ?? 0,
                'Некоректна ширина thumb.'
            ),
            'thumb_height' => self::positiveInt(
                $thumb['height'] ?? 0,
                'Некоректна висота thumb.'
            ),
            'thumb_bytes' => self::positiveInt(
                $thumb['bytes'] ?? 0,
                'Некоректний розмір thumb.'
            ),
            'normalization' => $normalizedDiagnostics
        ];
    }


    private static function normalizeSourcePath($path)
    {
        $path = trim(str_replace('\\', '/', (string) $path));

        if (strpos($path, '/Anabelka/') === 0) {
            $path = substr($path, strlen('/Anabelka/'));
        }

        return ltrim($path, '/');
    }


    private static function resultPath(
        $path,
        $bucket,
        $jobId,
        $requiredFilename
    ) {
        $path = trim(str_replace('\\', '/', (string) $path));
        $path = ltrim($path, '/');

        $root = $bucket === 'processed'
            ? 'uploads/products/processed/'
            : 'storage/image-processor/' . $bucket . '/';

        $prefix = $root
            . $jobId
            . '/';

        if (
            strpos($path, $prefix) !== 0
            || strpos($path, '../') !== false
        ) {
            throw new RuntimeException(
                'Обробник повернув небезпечний шлях до файла.'
            );
        }

        $filename = substr($path, strlen($prefix));

        if (
            $filename === ''
            || basename($filename) !== $filename
            || (
                $requiredFilename !== null
                && $filename !== $requiredFilename
            )
        ) {
            throw new RuntimeException(
                'Обробник повернув некоректне ім’я файла.'
            );
        }

        return $path;
    }


    private static function positiveInt($value, $message)
    {
        $value = (int) $value;

        if ($value <= 0) {
            throw new RuntimeException((string) $message);
        }

        return $value;
    }
}
