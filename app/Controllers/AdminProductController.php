<?php

class AdminProductController extends Controller
{
    private const MAX_IMAGE_SIZE = 8388608;
    private const MAX_IMAGE_COUNT = 8;


    public function index()
    {
        $filters = AdminProduct::normalizeFilters($_GET);
        $products = [];
        $categories = [];
        $priceRanks = [];
        $summary = [
            'all' => 0,
            'active' => 0,
            'out_of_stock' => 0,
            'hidden' => 0
        ];
        $productsError = '';
        $languages = [];

        try {
            $products = AdminProduct::all($filters);
            $categories = AdminProduct::categories();
            $priceRanks = AdminProduct::priceRanks();
            $summary = AdminProduct::summary();
            $languages = Language::active();

            foreach ($products as &$product) {
                $product['translations'] =
                    ProductTranslator::getForProduct(
                        (int) $product['id']
                    );
            }
            unset($product);
        } catch (Throwable $e) {
            $productsError = $e->getMessage();
        }

        $flash = $_SESSION['admin_product_flash'] ?? null;
        unset($_SESSION['admin_product_flash']);

        $this->view(
            'admin/products/index',
            [
                'products' => $products,
                'categories' => $categories,
                'priceRanks' => $priceRanks,
                'languages' => $languages,
                'summary' => $summary,
                'filters' => $filters,
                'productsError' => $productsError,
                'flash' => is_array($flash) ? $flash : null,
                'csrfToken' => $this->csrfToken()
            ]
        );
    }


    public function save()
    {
        $db = null;
        $uploadedPaths = [];
        $removedPaths = [];

        try {
            $this->assertCsrf();

            $productId = (int) ($_POST['product_id'] ?? 0);
            $priceRanks = AdminProduct::priceRanks();
            $data = $this->validateProductInput(
                $productId,
                $priceRanks
            );

            /* DDL має виконатися до початку транзакції. */
            ProductTranslator::getForProduct(0);
            ProductImage::ensureTable();

            $currentSource = null;
            $storedTranslations = [];

            if ($productId > 0) {
                $currentSource = AdminProduct::find($productId);

                if (!$currentSource) {
                    throw new RuntimeException('Товар не знайдено.');
                }

                $storedTranslations =
                    ProductTranslator::getForProduct($productId);
            }

            $db = Database::connect();
            $db->beginTransaction();

            if ($productId > 0) {
                AdminProduct::update($productId, $data);
            } else {
                $productId = AdminProduct::create($data);
            }

            AdminProduct::syncPrices(
                $productId,
                $data['price'],
                $data['rank_prices'],
                $priceRanks
            );
            AdminProduct::syncSizes(
                $productId,
                $data['sizes'],
                $data['stock_mode']
            );

            $this->saveTranslations(
                $productId,
                $currentSource,
                $storedTranslations
            );

            $removedPaths = ProductImage::deleteByIds(
                $productId,
                $this->integerList($_POST['delete_images'] ?? [])
            );
            ProductImage::reorder(
                $productId,
                $this->integerList($_POST['image_order'] ?? [])
            );

            $this->storeUploadedImages($uploadedPaths);
            ProductImage::addPaths($productId, $uploadedPaths);
            ProductImage::selectMain(
                $productId,
                (int) ($_POST['main_image_id'] ?? 0)
            );

            $db->commit();

            foreach ($removedPaths as $path) {
                $this->deleteManagedImageIfUnused($path);
            }

            $this->jsonResponse([
                'success' => true,
                'product_id' => $productId,
                'message' => $currentSource
                    ? 'Товар збережено.'
                    : 'Товар створено.'
            ]);
        } catch (Throwable $e) {
            if ($db instanceof PDO && $db->inTransaction()) {
                $db->rollBack();
            }

            foreach ($uploadedPaths as $path) {
                $this->deleteManagedImage($path);
            }

            $status = $e instanceof InvalidArgumentException
                ? 400
                : 500;
            $this->jsonResponse([
                'success' => false,
                'message' => $e->getMessage()
            ], $status);
        }
    }


    /* Старий URL також веде до єдиного обробника збереження. */
    public function update()
    {
        $this->save();
    }


    public function toggle()
    {
        $filters = AdminProduct::normalizeFilters([
            'status' => $_POST['filter_status'] ?? 'all',
            'category_id' => $_POST['filter_category_id'] ?? 0,
            'q' => $_POST['filter_q'] ?? ''
        ]);

        try {
            $this->assertCsrf();
            $productId = (int) ($_POST['product_id'] ?? 0);

            if ($productId <= 0) {
                throw new InvalidArgumentException(
                    'Некоректний товар.'
                );
            }

            $isActive = (int) ($_POST['is_active'] ?? 0) === 1;
            AdminProduct::setActive($productId, $isActive);
            $_SESSION['admin_product_flash'] = [
                'type' => 'success',
                'message' => $isActive
                    ? 'Товар повернуто на сайт.'
                    : 'Товар приховано з сайту.'
            ];
        } catch (Throwable $e) {
            $_SESSION['admin_product_flash'] = [
                'type' => 'error',
                'message' => $e->getMessage()
            ];
        }

        header('Location: ' . $this->productsUrl($filters));
        exit;
    }


    public function duplicate()
    {
        $filters = AdminProduct::normalizeFilters([
            'status' => $_POST['filter_status'] ?? 'all',
            'category_id' => $_POST['filter_category_id'] ?? 0,
            'q' => $_POST['filter_q'] ?? ''
        ]);
        $db = null;

        try {
            $this->assertCsrf();
            $sourceId = (int) ($_POST['product_id'] ?? 0);

            if ($sourceId <= 0) {
                throw new InvalidArgumentException(
                    'Некоректний товар.'
                );
            }

            ProductTranslator::getForProduct(0);
            ProductImage::ensureTable();
            $db = Database::connect();
            $db->beginTransaction();
            $productId = AdminProduct::duplicate($sourceId);
            $db->commit();

            $_SESSION['admin_product_flash'] = [
                'type' => 'success',
                'message' =>
                    'Копію створено й приховано. Перевірте її перед публікацією.'
            ];

            $filters['status'] = 'all';
            $filters['q'] = '';
            header(
                'Location: '
                . $this->productsUrl($filters, $productId)
            );
            exit;
        } catch (Throwable $e) {
            if ($db instanceof PDO && $db->inTransaction()) {
                $db->rollBack();
            }

            $_SESSION['admin_product_flash'] = [
                'type' => 'error',
                'message' => $e->getMessage()
            ];
        }

        header('Location: ' . $this->productsUrl($filters));
        exit;
    }


    private function validateProductInput($productId, array $priceRanks)
    {
        $productId = (int) $productId;
        $name = $this->limitedText(
            $_POST['name'] ?? '',
            255,
            'Назва товару обов’язкова.'
        );
        $categoryId = (int) ($_POST['category_id'] ?? 0);

        if ($categoryId <= 0 || !AdminProduct::categoryExists($categoryId)) {
            throw new InvalidArgumentException(
                'Оберіть категорію товару.'
            );
        }

        $price = $this->money(
            $_POST['price'] ?? null,
            true,
            'Вкажіть ціну товару.'
        );
        $oldPrice = $this->money(
            $_POST['old_price'] ?? null,
            false,
            'Стара ціна має бути числом.'
        );
        $rankInput = is_array($_POST['rank_price'] ?? null)
            ? $_POST['rank_price']
            : [];
        $rankPrices = [];
        $memberPrice = null;

        foreach ($priceRanks as $rank) {
            $rankId = (int) ($rank['id'] ?? 0);
            $rankSlug = strtolower(trim((string) ($rank['slug'] ?? '')));

            if ($rankId <= 0 || $rankSlug === 'guest') {
                continue;
            }

            $value = $this->money(
                $rankInput[$rankId] ?? null,
                false,
                'Перевірте додаткові ціни.'
            );
            $rankPrices[$rankId] = $value;

            if ($rankSlug === 'member') {
                $memberPrice = $value;
            }
        }

        $stockMode = ($_POST['stock_mode'] ?? 'total') === 'by_size'
            ? 'by_size'
            : 'total';
        $stock = $this->wholeNumber(
            $_POST['stock'] ?? 0,
            'Залишок має бути цілим числом.'
        );
        $sizes = $this->sizesFromRequest();

        if (empty($sizes)) {
            throw new InvalidArgumentException(
                'Додайте хоча б один розмір. Для товару без розміру вкажіть «Універсальний».'
            );
        }

        $slugInput = $this->limitedOptionalText(
            $_POST['slug'] ?? '',
            180,
            'Адреса товару надто довга.'
        );
        $skuInput = $this->limitedOptionalText(
            $_POST['sku'] ?? '',
            100,
            'SKU надто довгий.'
        );
        $slug = AdminProduct::uniqueSlug(
            $slugInput,
            $name,
            $productId
        );
        $sku = AdminProduct::uniqueSku(
            $skuInput,
            $productId
        );

        return [
            'category_id' => $categoryId,
            'name' => $name,
            'slug' => $slug,
            'sku' => $sku,
            'description' => $this->limitedOptionalText(
                $_POST['description'] ?? '',
                20000,
                'Опис надто довгий.'
            ),
            'price' => $price,
            'member_price' => $memberPrice === null
                ? $price
                : $memberPrice,
            'old_price' => $oldPrice,
            'rank_prices' => $rankPrices,
            'stock' => $stockMode === 'total' ? $stock : 0,
            'stock_mode' => $stockMode,
            'show_stock_quantity' =>
                (int) ($_POST['show_stock_quantity'] ?? 0) === 1,
            'sizes' => $sizes,
            'brand' => $this->limitedOptionalText(
                $_POST['brand'] ?? '',
                150,
                'Назва бренду надто довга.'
            ),
            'country' => $this->limitedOptionalText(
                $_POST['country'] ?? '',
                150,
                'Назва країни надто довга.'
            ),
            'is_active' => (int) ($_POST['is_active'] ?? 0) === 1
        ];
    }


    private function sizesFromRequest()
    {
        $ids = is_array($_POST['size_id'] ?? null)
            ? $_POST['size_id']
            : [];
        $names = is_array($_POST['size_name'] ?? null)
            ? $_POST['size_name']
            : [];
        $stocks = is_array($_POST['size_stock'] ?? null)
            ? $_POST['size_stock']
            : [];

        if (count($names) > 100) {
            throw new InvalidArgumentException(
                'Для одного товару можна додати до 100 розмірів.'
            );
        }

        $sizes = [];
        $seen = [];

        foreach ($names as $index => $rawName) {
            $name = $this->limitedOptionalText(
                $rawName,
                80,
                'Назва розміру надто довга.'
            );

            if ($name === '') {
                continue;
            }

            $key = function_exists('mb_strtolower')
                ? mb_strtolower($name, 'UTF-8')
                : strtolower($name);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $sizes[] = [
                'id' => (int) ($ids[$index] ?? 0),
                'name' => $name,
                'stock' => $this->wholeNumber(
                    $stocks[$index] ?? 0,
                    'Залишок розміру має бути цілим числом.'
                )
            ];
        }

        return $sizes;
    }


    private function saveTranslations(
        $productId,
        $currentSource,
        array $storedBefore
    ) {
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $sourceChanged = is_array($currentSource)
            && TranslationWorkflow::sourceChanged(
                $currentSource['name'] ?? '',
                $currentSource['description'] ?? '',
                $name,
                $description
            );

        if ($sourceChanged) {
            ProductTranslator::markOutdated($productId);
        }

        $translationNames = is_array(
            $_POST['translation_name'] ?? null
        ) ? $_POST['translation_name'] : [];
        $translationDescriptions = is_array(
            $_POST['translation_description'] ?? null
        ) ? $_POST['translation_description'] : [];
        $translationSources = is_array(
            $_POST['translation_source'] ?? null
        ) ? $_POST['translation_source'] : [];
        $translationStatuses = is_array(
            $_POST['translation_status'] ?? null
        ) ? $_POST['translation_status'] : [];
        $expected = [];

        foreach (Language::active() as $language) {
            $code = strtolower(trim((string) ($language['code'] ?? '')));

            if ($code === '' || $code === Language::SOURCE_CODE) {
                continue;
            }

            $translationName = trim((string) (
                $translationNames[$code] ?? ''
            ));
            $translationDescription = trim((string) (
                $translationDescriptions[$code] ?? ''
            ));

            if ($this->textLength($translationName) > 255) {
                throw new InvalidArgumentException(
                    'Назва перекладу ' . strtoupper($code)
                    . ' надто довга.'
                );
            }
            $stored = is_array($storedBefore[$code] ?? null)
                ? $storedBefore[$code]
                : [];
            $translationSource = TranslationWorkflow::normalizeSource(
                $translationSources[$code]
                    ?? ($stored['source'] ?? 'manual')
            );
            $hasContent = $translationName !== ''
                || $translationDescription !== '';
            $translationStatus = TranslationWorkflow::normalizeStatus(
                $translationStatuses[$code]
                    ?? ($stored['status'] ?? 'approved'),
                $hasContent
            );
            $translationChanged =
                TranslationWorkflow::translationChanged(
                    $stored,
                    $translationName,
                    $translationDescription
                );
            $oldStatus = TranslationWorkflow::normalizeStatus(
                $stored['status'] ?? 'approved',
                !empty($stored)
            );
            $statusChanged = $translationStatus !== $oldStatus;

            if (
                $sourceChanged
                && !empty($stored)
                && !$translationChanged
                && !$statusChanged
            ) {
                $expected[$code] = [
                    'name' => (string) ($stored['name'] ?? ''),
                    'description' => (string) ($stored['description'] ?? ''),
                    'source' => (string) ($stored['source'] ?? 'manual'),
                    'status' => 'outdated'
                ];
                continue;
            }

            ProductTranslator::saveForProduct(
                $productId,
                $code,
                $translationName,
                $translationDescription,
                $translationSource,
                $translationStatus
            );
            $expected[$code] = [
                'name' => $translationName,
                'description' => $translationDescription,
                'source' => $translationSource,
                'status' => $translationStatus
            ];
        }

        $this->verifySavedTranslations(
            ProductTranslator::getForProduct($productId),
            $expected
        );
    }


    private function verifySavedTranslations(
        array $storedTranslations,
        array $expectedTranslations
    ) {
        foreach ($expectedTranslations as $code => $expected) {
            $expectedName = trim((string) ($expected['name'] ?? ''));
            $expectedDescription = trim(
                (string) ($expected['description'] ?? '')
            );

            if ($expectedName === '' && $expectedDescription === '') {
                if (isset($storedTranslations[$code])) {
                    throw new RuntimeException(
                        'Порожній переклад ' . strtoupper($code)
                        . ' не було видалено.'
                    );
                }
                continue;
            }

            $stored = $storedTranslations[$code] ?? null;

            if (
                !$stored
                || trim((string) ($stored['name'] ?? '')) !== $expectedName
                || trim((string) ($stored['description'] ?? ''))
                    !== $expectedDescription
                || (string) ($stored['source'] ?? '')
                    !== (string) ($expected['source'] ?? 'manual')
                || (string) ($stored['status'] ?? '')
                    !== (string) ($expected['status'] ?? 'approved')
            ) {
                throw new RuntimeException(
                    'База не підтвердила збереження перекладу '
                    . strtoupper($code) . '.'
                );
            }
        }
    }


    private function storeUploadedImages(array &$uploaded)
    {
        $files = $_FILES['product_images'] ?? null;

        if (!is_array($files) || !isset($files['error'])) {
            return;
        }

        $errors = is_array($files['error'])
            ? $files['error']
            : [$files['error']];

        if (count($errors) > self::MAX_IMAGE_COUNT) {
            throw new InvalidArgumentException(
                'За один раз можна завантажити до 8 фотографій.'
            );
        }

        $names = is_array($files['name'] ?? null)
            ? $files['name']
            : [$files['name'] ?? ''];
        $temporaryNames = is_array($files['tmp_name'] ?? null)
            ? $files['tmp_name']
            : [$files['tmp_name'] ?? ''];
        $sizes = is_array($files['size'] ?? null)
            ? $files['size']
            : [$files['size'] ?? 0];
        $mimeExtensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp'
        ];
        $directory = dirname(__DIR__, 2) . '/uploads/products';

        foreach ($errors as $index => $error) {
            $error = (int) $error;

            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($error !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException(
                    'Не вдалося завантажити «'
                    . (string) ($names[$index] ?? 'фотографію')
                    . '».'
                );
            }

            if ((int) ($sizes[$index] ?? 0) > self::MAX_IMAGE_SIZE) {
                throw new InvalidArgumentException(
                    'Одна фотографія не може бути більшою за 8 МБ.'
                );
            }

            $temporaryName = (string) ($temporaryNames[$index] ?? '');
            $imageInfo = $temporaryName !== ''
                ? @getimagesize($temporaryName)
                : false;
            $mime = is_array($imageInfo)
                ? strtolower((string) ($imageInfo['mime'] ?? ''))
                : '';

            if (!isset($mimeExtensions[$mime])) {
                throw new InvalidArgumentException(
                    'Дозволені фотографії JPG, PNG або WebP.'
                );
            }

            if (!is_dir($directory) && !mkdir($directory, 0775, true)) {
                throw new RuntimeException(
                    'Не вдалося створити папку для фотографій.'
                );
            }

            $filename = date('Ymd-His')
                . '-'
                . bin2hex(random_bytes(8))
                . '.'
                . $mimeExtensions[$mime];
            $target = $directory . '/' . $filename;

            if (!move_uploaded_file($temporaryName, $target)) {
                throw new RuntimeException(
                    'Не вдалося зберегти фотографію товару.'
                );
            }

            $uploaded[] = '/Anabelka/uploads/products/' . $filename;
        }

    }


    private function deleteManagedImageIfUnused($path)
    {
        try {
            if (ProductImage::pathIsUsed($path)) {
                return;
            }
        } catch (Throwable $e) {
            return;
        }

        $this->deleteManagedImage($path);
    }


    private function deleteManagedImage($path)
    {
        $prefix = '/Anabelka/uploads/products/';
        $path = (string) $path;

        if (strpos($path, $prefix) !== 0) {
            return;
        }

        $filename = substr($path, strlen($prefix));

        if ($filename === '' || basename($filename) !== $filename) {
            return;
        }

        $file = dirname(__DIR__, 2) . '/uploads/products/' . $filename;

        if (is_file($file)) {
            @unlink($file);
        }
    }


    private function limitedText($value, $limit, $emptyMessage)
    {
        $value = trim((string) $value);

        if ($value === '') {
            throw new InvalidArgumentException($emptyMessage);
        }

        if ($this->textLength($value) > (int) $limit) {
            throw new InvalidArgumentException($emptyMessage);
        }

        return $value;
    }


    private function limitedOptionalText($value, $limit, $message)
    {
        $value = trim((string) $value);

        if ($this->textLength($value) > (int) $limit) {
            throw new InvalidArgumentException($message);
        }

        return $value;
    }


    private function textLength($value)
    {
        return function_exists('mb_strlen')
            ? mb_strlen((string) $value, 'UTF-8')
            : strlen((string) $value);
    }


    private function money($value, $required, $message)
    {
        $value = str_replace(',', '.', trim((string) $value));

        if ($value === '') {
            if ($required) {
                throw new InvalidArgumentException($message);
            }
            return null;
        }

        if (
            !is_numeric($value)
            || (float) $value < 0
            || (float) $value > 99999999.99
        ) {
            throw new InvalidArgumentException($message);
        }

        return round((float) $value, 2);
    }


    private function wholeNumber($value, $message)
    {
        $value = trim((string) $value);

        if ($value === '' || !preg_match('/^\d+$/', $value)) {
            throw new InvalidArgumentException($message);
        }

        return min(1000000000, (int) $value);
    }


    private function integerList($value)
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('intval', $value),
            function ($id) {
                return $id > 0;
            }
        )));
    }


    private function assertCsrf()
    {
        $submitted = (string) ($_POST['csrf_token'] ?? '');

        if (!hash_equals($this->csrfToken(), $submitted)) {
            throw new InvalidArgumentException(
                'Сторінка застаріла. Оновіть її та повторіть дію.'
            );
        }
    }


    private function csrfToken()
    {
        if (empty($_SESSION['admin_product_csrf'])) {
            $_SESSION['admin_product_csrf'] = bin2hex(
                random_bytes(24)
            );
        }

        return (string) $_SESSION['admin_product_csrf'];
    }


    private function productsUrl(array $filters, $highlight = 0)
    {
        $query = [];

        if (($filters['status'] ?? 'all') !== 'all') {
            $query['status'] = $filters['status'];
        }

        if ((int) ($filters['category_id'] ?? 0) > 0) {
            $query['category_id'] = (int) $filters['category_id'];
        }

        if (($filters['q'] ?? '') !== '') {
            $query['q'] = $filters['q'];
        }

        if ((int) $highlight > 0) {
            $query['highlight'] = (int) $highlight;
        }

        return '/Anabelka/admin/products'
            . (empty($query) ? '' : '?' . http_build_query($query));
    }


    private function jsonResponse(array $payload, $status = 200)
    {
        http_response_code((int) $status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
