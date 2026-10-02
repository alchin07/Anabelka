<?php

class ProductImageProcessingService
{
    public static function process($imageId)
    {
        $imageId = (int) $imageId;

        if ($imageId <= 0) {
            throw new InvalidArgumentException(
                'Некоректна фотографія товару.'
            );
        }

        ProductImageProcessing::ensureSchema();
        $image = ProductImage::findById($imageId);

        if (!$image) {
            throw new InvalidArgumentException(
                'Фотографію товару не знайдено.'
            );
        }

        $sourcePath = trim((string) ($image['path'] ?? ''));

        if ($sourcePath === '') {
            throw new InvalidArgumentException(
                'Для фотографії не збережено шлях до файла.'
            );
        }

        ProductImageProcessing::markProcessing(
            $imageId,
            $sourcePath
        );

        try {
            $response = ImageProcessorClient::processProductImage(
                $sourcePath
            );
            $data = self::normalizeResult(
                $sourcePath,
                $response
            );

            ProductImageProcessing::markReady(
                $imageId,
                $data
            );

            $stored = ProductImageProcessing::find($imageId);

            if (!$stored || ($stored['status'] ?? '') !== 'ready') {
                throw new RuntimeException(
                    'База не підтвердила результат обробки фотографії.'
                );
            }

            return array_merge(
                $stored,
                [
                    'product_id' => (int) (
                        $image['product_id'] ?? 0
                    )
                ]
            );
        } catch (Throwable $e) {
            ProductImageProcessing::markError(
                $imageId,
                $sourcePath,
                $e->getMessage()
            );

            throw $e;
        }
    }


    private static function normalizeResult(
        $sourcePath,
        array $response
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
            )
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
