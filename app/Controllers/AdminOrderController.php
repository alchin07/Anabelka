<?php

class AdminOrderController extends Controller
{
    public function index()
    {
        $filters = AdminOrder::normalizeFilters($_GET);
        $orders = [];
        $summary = [
            'total' => 0,
            'new' => 0,
            'processing' => 0,
            'completed' => 0,
            'cancelled' => 0,
            'regular_total' => 0,
            'regular_new' => 0,
            'quick_total' => 0,
            'quick_new' => 0
        ];
        $allSummary = $summary;
        $ordersError = '';

        try {
            $orders = AdminOrder::getAll($filters);
            $summary = AdminOrder::summary($filters['type']);
            $allSummary = $filters['type'] === 'all'
                ? $summary
                : AdminOrder::summary();
        } catch (Throwable $e) {
            $ordersError = $e->getMessage();
        }

        $flash = $_SESSION['admin_order_flash'] ?? null;
        unset($_SESSION['admin_order_flash']);

        $this->view(
            'admin/orders/index',
            [
                'orders' => $orders,
                'summary' => $summary,
                'filters' => $filters,
                'statusOptions' => AdminOrder::statusOptions(),
                'ordersError' => $ordersError,
                'flash' => is_array($flash) ? $flash : null,
                'csrfToken' => $this->csrfToken(),
                'navBadges' => [
                    'orders' => (int) ($allSummary['new'] ?? 0)
                ]
            ]
        );
    }


    public function updateStatus()
    {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $orderType = trim((string) ($_POST['order_type'] ?? ''));
        $status = trim((string) ($_POST['status'] ?? ''));
        $submittedToken = (string) ($_POST['csrf_token'] ?? '');

        $filters = AdminOrder::normalizeFilters([
            'type' => $_POST['filter_type'] ?? 'all',
            'status' => $_POST['filter_status'] ?? 'all',
            'q' => $_POST['filter_q'] ?? ''
        ]);

        try {
            if (!hash_equals($this->csrfToken(), $submittedToken)) {
                throw new RuntimeException(
                    'Сторінка застаріла. Оновіть її та повторіть дію.'
                );
            }

            AdminOrder::updateStatus(
                $orderType,
                $orderId,
                $status
            );

            $_SESSION['admin_order_flash'] = [
                'type' => 'success',
                'message' => 'Стан замовлення оновлено.'
            ];
        } catch (Throwable $e) {
            $_SESSION['admin_order_flash'] = [
                'type' => 'error',
                'message' => $e->getMessage()
            ];
        }

        header('Location: ' . $this->ordersUrl($filters));
        exit;
    }


    private function ordersUrl(array $filters)
    {
        $query = [];

        if (($filters['type'] ?? 'all') !== 'all') {
            $query['type'] = $filters['type'];
        }

        if (($filters['status'] ?? 'all') !== 'all') {
            $query['status'] = $filters['status'];
        }

        if (($filters['q'] ?? '') !== '') {
            $query['q'] = $filters['q'];
        }

        return '/Anabelka/admin/orders'
            . (empty($query) ? '' : '?' . http_build_query($query));
    }


    private function csrfToken()
    {
        if (empty($_SESSION['admin_order_csrf'])) {
            $_SESSION['admin_order_csrf'] = bin2hex(
                random_bytes(24)
            );
        }

        return (string) $_SESSION['admin_order_csrf'];
    }
}
