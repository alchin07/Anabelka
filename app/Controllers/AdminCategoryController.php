<?php

class AdminCategoryController extends Controller
{
    public function index()
    {
        // Runtime table creation, where still needed by older installations,
        // happens before any manager transaction is opened.
        CategoryTranslator::getForCategory(0);

        $departments = Department::allForAdmin();
        $categories = Category::getAllForAdmin();
        $categoryIds = array_column($categories, 'id');
        $translations = CategoryTranslator::getForCategories($categoryIds);
        $thumbnailCandidates = Category::thumbnailCandidatesForAdmin(
            $categoryIds
        );

        foreach ($categories as &$category) {
            $categoryId = (int) ($category['id'] ?? 0);
            $category['translations'] = $translations[$categoryId] ?? [];
            $category['thumbnail_candidates'] =
                $thumbnailCandidates[$categoryId] ?? [];
        }
        unset($category);

        $this->view('admin/categories/index', [
            'departments' => $departments,
            'categories' => $categories,
            'categoryForest' => Category::buildAdminForest($categories),
            'languages' => Language::active(),
            'csrfToken' => AdminAccess::csrfToken()
        ]);
    }


    public function create()
    {
        $this->verifyCsrf();

        try {
            $category = CategoryManager::create($_POST);

            $this->jsonSuccess(
                'Категорію створено.',
                ['category' => $category]
            );
        } catch (Throwable $e) {
            $this->handleFailure($e);
        }
    }


    public function update()
    {
        $this->verifyCsrf();

        // Both helpers may perform legacy CREATE TABLE IF NOT EXISTS work.
        // Resolve it before CategoryManager starts the data transaction.
        $activeLanguages = Language::active();
        CategoryTranslator::getForCategory(0);

        try {
            CategoryManager::update(
                (int) ($_POST['category_id'] ?? 0),
                $_POST,
                $activeLanguages
            );

            $this->jsonSuccess('Категорію та переклади збережено.');
        } catch (Throwable $e) {
            $this->handleFailure($e);
        }
    }


    public function thumbnail()
    {
        $this->verifyCsrf();
        $uploadedPath = '';

        try {
            $categoryId = (int) ($_POST['category_id'] ?? 0);

            $category = $categoryId > 0
                ? Category::findAdminById($categoryId)
                : false;

            if (!$category) {
                throw new DomainException('Категорію не знайдено.');
            }

            $previousImage = trim(
                (string) ($category['image'] ?? '')
            );
            $uploadedPath = $this->storeThumbnailUpload($categoryId);
            $thumbnail = CategoryManager::updateThumbnail(
                $categoryId,
                $uploadedPath !== ''
                    ? $uploadedPath
                    : ($_POST['image'] ?? ''),
                $uploadedPath !== ''
            );

            $storedImage = trim(
                (string) ($thumbnail['image'] ?? '')
            );

            if (
                $previousImage !== ''
                && $previousImage !== $storedImage
            ) {
                $this->deleteManagedThumbnail($previousImage);
            }

            $this->jsonSuccess(
                $uploadedPath !== ''
                    ? 'Фото оброблено та встановлено як мініатюру.'
                    : 'Мініатюру категорії збережено.',
                ['thumbnail' => $thumbnail]
            );
        } catch (Throwable $e) {
            if (
                $uploadedPath !== ''
                && strpos(
                    $uploadedPath,
                    '/Anabelka/uploads/categories/thumbnails/'
                ) === 0
            ) {
                $absolute = dirname(__DIR__, 2)
                    . '/'
                    . ltrim(
                        substr($uploadedPath, strlen('/Anabelka/')),
                        '/'
                    );

                if (is_file($absolute)) {
                    @unlink($absolute);
                }
            }

            $this->handleFailure($e);
        }
    }


    public function move()
    {
        $this->verifyCsrf();

        try {
            $direction = trim((string) ($_POST['direction'] ?? ''));
            $categoryId = (int) ($_POST['category_id'] ?? 0);

            if ($direction !== '') {
                $changed = CategoryManager::reorder(
                    $categoryId,
                    $direction
                );

                $this->jsonSuccess(
                    $changed
                        ? 'Порядок категорій змінено.'
                        : 'Категорія вже займає крайню позицію.'
                );
            }

            $result = CategoryManager::move(
                $categoryId,
                $_POST['parent_id'] ?? null,
                (int) ($_POST['department_id'] ?? 0)
            );

            $this->jsonSuccess(
                'Гілку категорій переміщено.',
                ['move' => $result]
            );
        } catch (Throwable $e) {
            $this->handleFailure($e);
        }
    }


    public function toggle()
    {
        $this->verifyCsrf();

        try {
            CategoryManager::toggle(
                (int) ($_POST['category_id'] ?? 0),
                $_POST['field'] ?? '',
                $_POST['value'] ?? 0
            );

            $this->jsonSuccess('Стан категорії змінено.');
        } catch (Throwable $e) {
            $this->handleFailure($e);
        }
    }


    public function delete()
    {
        $this->verifyCsrf();
        CategoryTranslator::getForCategory(0);

        try {
            CategoryManager::delete(
                (int) ($_POST['category_id'] ?? 0)
            );

            $this->jsonSuccess('Категорію видалено.');
        } catch (Throwable $e) {
            $this->handleFailure($e);
        }
    }


    private function storeThumbnailUpload($categoryId)
    {
        $file = $_FILES['thumbnail_file'] ?? null;

        if (!is_array($file)) {
            return '';
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_NO_FILE) {
            return '';
        }

        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(
                'Не вдалося завантажити фото мініатюри.'
            );
        }

        $size = (int) ($file['size'] ?? 0);
        $tmpName = (string) ($file['tmp_name'] ?? '');

        if ($size <= 0 || $size > 8388608) {
            throw new InvalidArgumentException(
                'Фото мініатюри має бути не більше 8 МБ.'
            );
        }

        if (
            $tmpName === ''
            || !is_uploaded_file($tmpName)
            || !function_exists('getimagesize')
            || !function_exists('imagecreatetruecolor')
            || !function_exists('imagecopyresampled')
        ) {
            throw new InvalidArgumentException(
                'Обробка фото на сервері недоступна.'
            );
        }

        $info = @getimagesize($tmpName);

        if (!is_array($info)) {
            throw new InvalidArgumentException(
                'Файл не є підтримуваним зображенням.'
            );
        }

        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        $mime = strtolower((string) ($info['mime'] ?? ''));

        if (
            $width <= 0
            || $height <= 0
            || $width > 12000
            || $height > 12000
            || ($width * $height) > 40000000
        ) {
            throw new InvalidArgumentException(
                'Розмір зображення занадто великий.'
            );
        }

        if ($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) {
            $source = @imagecreatefromjpeg($tmpName);
        } elseif (
            $mime === 'image/png'
            && function_exists('imagecreatefrompng')
        ) {
            $source = @imagecreatefrompng($tmpName);
        } elseif (
            $mime === 'image/webp'
            && function_exists('imagecreatefromwebp')
        ) {
            $source = @imagecreatefromwebp($tmpName);
        } else {
            throw new InvalidArgumentException(
                'Підтримуються JPG, PNG та WebP.'
            );
        }

        if (!$source) {
            throw new InvalidArgumentException(
                'Не вдалося прочитати зображення.'
            );
        }

        if (
            $mime === 'image/jpeg'
            && function_exists('exif_read_data')
            && function_exists('imagerotate')
        ) {
            $exif = @exif_read_data($tmpName);
            $orientation = is_array($exif)
                ? (int) ($exif['Orientation'] ?? 1)
                : 1;
            $angle = 0;

            if ($orientation === 3) {
                $angle = 180;
            } elseif ($orientation === 6) {
                $angle = -90;
            } elseif ($orientation === 8) {
                $angle = 90;
            }

            if ($angle !== 0) {
                $rotated = @imagerotate($source, $angle, 0);

                if ($rotated) {
                    imagedestroy($source);
                    $source = $rotated;
                    $width = imagesx($source);
                    $height = imagesy($source);
                }
            }
        }

        $side = min($width, $height);
        $sourceX = (int) floor(($width - $side) / 2);
        $sourceY = (int) floor(($height - $side) / 2);
        $targetSize = 320;
        $target = imagecreatetruecolor($targetSize, $targetSize);

        if (!$target) {
            imagedestroy($source);
            throw new RuntimeException(
                'Не вдалося створити мініатюру.'
            );
        }

        imagealphablending($target, false);
        imagesavealpha($target, true);
        $transparent = imagecolorallocatealpha(
            $target,
            255,
            255,
            255,
            127
        );
        imagefilledrectangle(
            $target,
            0,
            0,
            $targetSize,
            $targetSize,
            $transparent
        );

        if (!imagecopyresampled(
            $target,
            $source,
            0,
            0,
            $sourceX,
            $sourceY,
            $targetSize,
            $targetSize,
            $side,
            $side
        )) {
            imagedestroy($source);
            imagedestroy($target);
            throw new RuntimeException(
                'Не вдалося масштабувати мініатюру.'
            );
        }

        $directory = dirname(__DIR__, 2)
            . '/uploads/categories/thumbnails';

        if (
            !is_dir($directory)
            && !@mkdir($directory, 0775, true)
            && !is_dir($directory)
        ) {
            imagedestroy($source);
            imagedestroy($target);
            throw new RuntimeException(
                'Не вдалося створити папку мініатюр.'
            );
        }

        $token = bin2hex(random_bytes(8));

        if (function_exists('imagewebp')) {
            $filename = 'category-'
                . (int) $categoryId
                . '-'
                . $token
                . '.webp';
            $absolute = $directory . '/' . $filename;
            $saved = @imagewebp($target, $absolute, 84);
        } elseif (function_exists('imagepng')) {
            $filename = 'category-'
                . (int) $categoryId
                . '-'
                . $token
                . '.png';
            $absolute = $directory . '/' . $filename;
            $saved = @imagepng($target, $absolute, 6);
        } else {
            $saved = false;
            $filename = '';
            $absolute = '';
        }

        imagedestroy($source);
        imagedestroy($target);

        if (!$saved || $filename === '' || !is_file($absolute)) {
            if ($absolute !== '' && is_file($absolute)) {
                @unlink($absolute);
            }

            throw new RuntimeException(
                'Не вдалося записати оброблену мініатюру.'
            );
        }

        return '/Anabelka/uploads/categories/thumbnails/' . $filename;
    }


    private function deleteManagedThumbnail($path)
    {
        $path = trim((string) $path);
        $prefix = '/Anabelka/uploads/categories/thumbnails/';

        if (strpos($path, $prefix) !== 0) {
            return;
        }

        $absolute = dirname(__DIR__, 2)
            . '/'
            . ltrim(
                substr($path, strlen('/Anabelka/')),
                '/'
            );

        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }


    private function verifyCsrf()
    {
        if (AdminAccess::verifyCsrf($_POST['_csrf'] ?? '')) {
            return;
        }

        $this->jsonError('Сесію форми завершено. Оновіть сторінку.', 419);
    }


    private function jsonSuccess($message, array $extra = [])
    {
        header('Content-Type: application/json; charset=UTF-8');

        echo json_encode(
            array_merge([
                'success' => true,
                'message' => (string) $message
            ], $extra),
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }


    private function handleFailure(Throwable $error)
    {
        if (
            $error instanceof DomainException
            || $error instanceof InvalidArgumentException
        ) {
            $this->jsonError($error->getMessage(), 422);
        }

        error_log(
            'Category manager: '
            . get_class($error)
            . ': '
            . $error->getMessage()
        );

        $this->jsonError(
            'Не вдалося виконати операцію з категорією. Перевірте журнал помилок.',
            500
        );
    }


    private function jsonError($message, $status)
    {
        http_response_code((int) $status);
        header('Content-Type: application/json; charset=UTF-8');

        echo json_encode([
            'success' => false,
            'message' => (string) $message
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
