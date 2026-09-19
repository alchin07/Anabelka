<?php

class AdminReviewController extends Controller
{
    public function index()
    {
        $status = trim((string) ($_GET['status'] ?? ''));
        $reviews = [];
        $loadError = '';

        try {
            $reviews = ProductReview::adminList($status);
        } catch (Throwable $e) {
            error_log('Admin reviews list: ' . $e->getMessage());
            $loadError = 'Таблиця відгуків ще не підготовлена. Застосуйте міграцію після резервної копії.';
        }

        $flash = is_array($_SESSION['admin_review_flash'] ?? null)
            ? $_SESSION['admin_review_flash']
            : null;
        unset($_SESSION['admin_review_flash']);

        $this->view('admin/reviews/index', [
            'reviews' => $reviews,
            'status' => $status,
            'csrfToken' => AdminAccess::csrfToken(),
            'flash' => $flash,
            'loadError' => $loadError
        ]);
    }


    public function approve()
    {
        $this->verifyCsrf();
        $this->moderate('approved');
    }


    public function reject()
    {
        $this->verifyCsrf();
        $this->moderate('rejected');
    }


    public function delete()
    {
        $this->verifyCsrf();
        $reviewId = (int) ($_POST['review_id'] ?? 0);

        try {
            ProductReview::delete($reviewId);
            AdminAccess::audit('review.delete', ['review_id' => $reviewId]);
            $this->flash('success', 'Відгук видалено.');
        } catch (Throwable $e) {
            $this->handleFailure($e, 'Не вдалося видалити відгук.');
        }

        $this->redirect();
    }


    private function moderate($status)
    {
        $reviewId = (int) ($_POST['review_id'] ?? 0);
        $adminId = AdminAccess::currentId();

        try {
            ProductReview::moderate(
                $reviewId,
                (string) $status,
                $adminId
            );
            AdminAccess::audit('review.moderate', [
                'review_id' => $reviewId,
                'status' => (string) $status
            ]);
            $this->flash(
                'success',
                $status === 'approved'
                    ? 'Відгук схвалено.'
                    : 'Відгук відхилено.'
            );
        } catch (Throwable $e) {
            $this->handleFailure($e, 'Не вдалося змінити статус відгуку.');
        }

        $this->redirect();
    }


    private function verifyCsrf()
    {
        if (AdminAccess::verifyCsrf($_POST['_csrf'] ?? '')) {
            return;
        }

        http_response_code(419);
        $this->flash('error', 'Сесію форми завершено. Оновіть сторінку.');
        $this->redirect();
    }


    private function handleFailure(Throwable $error, $fallback)
    {
        if (
            $error instanceof InvalidArgumentException
            || $error instanceof DomainException
        ) {
            $this->flash('error', $error->getMessage());
            return;
        }

        error_log(
            'Admin review: '
            . get_class($error)
            . ': '
            . $error->getMessage()
        );
        $this->flash('error', (string) $fallback);
    }


    private function flash($type, $message)
    {
        $_SESSION['admin_review_flash'] = [
            'type' => (string) $type,
            'message' => (string) $message
        ];
    }


    private function redirect()
    {
        $status = trim((string) ($_POST['return_status'] ?? ''));
        $url = '/Anabelka/admin/reviews';

        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $url .= '?status=' . rawurlencode($status);
        }

        header('Location: ' . $url);
        exit;
    }
}
