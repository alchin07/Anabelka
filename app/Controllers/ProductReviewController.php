<?php

class ProductReviewController extends Controller
{
    public function index()
    {
        PublicInterfaceTranslator::seed();
        $currentLanguage = Translator::currentLanguage();
        $reviews = [];

        try {
            $reviews = ProductReview::latestApprovedStandard(100);
        } catch (Throwable $e) {
            error_log('Public reviews list: ' . $e->getMessage());
        }

        $this->view('reviews/index', [
            'currentLanguage' => $currentLanguage,
            'reviews' => $reviews
        ]);
    }


    public function store($slug)
    {
        $slug = trim((string) $slug);
        $product = Product::findBySlug($slug);

        if (!$product) {
            http_response_code(404);
            die('Товар не знайдено');
        }

        $productCategory = Category::findById(
            (int) ($product['category_id'] ?? 0)
        );

        if (
            is_array($productCategory)
            && !empty($productCategory['effective_adult'])
            && !AdultAccess::isConfirmed()
        ) {
            header(
                'Location: '
                . AdultAccess::gateUrl(
                    $productCategory,
                    '/Anabelka/product/' . rawurlencode($slug) . '#product-reviews'
                )
            );
            exit;
        }

        $userId = CustomerAccount::currentId();

        if ($userId <= 0) {
            $_SESSION['product_review_flash'] = [
                'type' => 'error',
                'message' => 'Увійдіть до акаунта, щоб залишити відгук.'
            ];
            header('Location: /Anabelka/login');
            exit;
        }

        if (!CustomerAccount::verifyCsrf($_POST['_csrf'] ?? '')) {
            $_SESSION['product_review_flash'] = [
                'type' => 'error',
                'message' => 'Сесію форми завершено. Оновіть сторінку.'
            ];
            $this->redirectToProduct($slug);
        }

        try {
            ProductReview::submit(
                (int) ($product['id'] ?? 0),
                $userId,
                (int) ($_POST['rating'] ?? 0),
                (string) ($_POST['body'] ?? '')
            );

            $_SESSION['product_review_flash'] = [
                'type' => 'success',
                'message' => 'Дякуємо! Відгук надіслано на модерацію.'
            ];
        } catch (Throwable $e) {
            if (
                $e instanceof InvalidArgumentException
                || $e instanceof DomainException
            ) {
                $_SESSION['product_review_flash'] = [
                    'type' => 'error',
                    'message' => $e->getMessage()
                ];
            } else {
                error_log(
                    'Product review submit: '
                    . get_class($e)
                    . ': '
                    . $e->getMessage()
                );
                $_SESSION['product_review_flash'] = [
                    'type' => 'error',
                    'message' => 'Не вдалося надіслати відгук.'
                ];
            }
        }

        $this->redirectToProduct($slug);
    }


    private function redirectToProduct($slug)
    {
        header(
            'Location: /Anabelka/product/'
            . rawurlencode((string) $slug)
            . '#product-reviews'
        );
        exit;
    }
}
