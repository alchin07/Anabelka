<?php

class AdminActionAudit
{
    private static $registered = false;


    public static function watch($path, $method)
    {
        $method = strtoupper((string) $method);
        $path = rtrim((string) $path, '/');

        if ($path === '') {
            $path = '/';
        }

        if (
            self::$registered
            || $method !== 'POST'
            || !class_exists('AdminAccess')
        ) {
            return;
        }

        $spec = self::specFor($path);

        if (!$spec) {
            return;
        }

        $adminId = AdminAccess::currentId();

        if ($adminId <= 0) {
            return;
        }

        $details = self::detailsFor($path);
        self::$registered = true;

        register_shutdown_function(
            function () use ($spec, $details, $adminId) {
                if (!AdminActionAudit::requestSucceeded($spec)) {
                    return;
                }

                AdminAccess::audit(
                    (string) $spec['action'],
                    $details,
                    $adminId
                );
            }
        );
    }


    private static function specFor($path)
    {
        switch ($path) {
            case '/admin/orders/status':
                return [
                    'action' => 'order.status_changed',
                    'failure_session' => 'admin_order_flash'
                ];

            case '/admin/products/save':
            case '/admin/products/update':
                return [
                    'action' => self::postInt('product_id') > 0
                        ? 'product.updated'
                        : 'product.created'
                ];

            case '/admin/products/toggle':
                return [
                    'action' => 'product.visibility_changed',
                    'failure_session' => 'admin_product_flash'
                ];

            case '/admin/products/duplicate':
                return [
                    'action' => 'product.duplicated',
                    'failure_session' => 'admin_product_flash'
                ];

            case '/admin/products/variant-stock/save':
                return ['action' => 'product.variant_stock_updated'];

            case '/admin/categories/update':
                return ['action' => 'category.updated'];

            case '/admin/users/invite/create':
                return ['action' => 'customer.invitation_created'];

            case '/admin/users/invite/sent':
                return ['action' => 'customer.invitation_marked_sent'];

            case '/admin/users/rank':
                return ['action' => 'customer.rank_changed'];

            case '/admin/ranks/create':
                return ['action' => 'rank.created'];

            case '/admin/ranks/update':
                return ['action' => 'rank.updated'];

            case '/admin/ranks/move':
                return ['action' => 'rank.moved'];

            case '/admin/ranks/toggle':
                return ['action' => 'rank.toggled'];

            case '/admin/ranks/default':
                return ['action' => 'rank.default_changed'];

            case '/admin/delivery/toggle-method':
                return ['action' => 'delivery.method_toggled'];

            case '/admin/delivery/toggle-service':
                return ['action' => 'delivery.service_toggled'];

            case '/admin/delivery/toggle-option':
                return ['action' => 'delivery.option_toggled'];

            case '/admin/delivery/update':
                return ['action' => 'delivery.entity_updated'];

            case '/admin/delivery/delete':
                return ['action' => 'delivery.entity_deleted'];

            case '/admin/delivery/create-method':
                return ['action' => 'delivery.method_created'];

            case '/admin/delivery/create-service':
                return ['action' => 'delivery.service_created'];

            case '/admin/delivery/create-option':
                return ['action' => 'delivery.option_created'];

            case '/admin/delivery/option-input':
                return ['action' => 'delivery.option_field_updated'];

            case '/admin/delivery/translations':
                return ['action' => 'delivery.translations_updated'];

            case '/admin/languages/create':
                return [
                    'action' => 'language.created',
                    'failure_session' => 'language_error'
                ];

            case '/admin/languages/update':
                return [
                    'action' => 'language.updated',
                    'failure_session' => 'language_error'
                ];

            case '/admin/languages/toggle':
                return [
                    'action' => 'language.toggled',
                    'failure_session' => 'language_error'
                ];

            case '/admin/languages/default':
                return [
                    'action' => 'language.default_changed',
                    'failure_session' => 'language_error'
                ];

            case '/admin/languages/delete':
                return [
                    'action' => 'language.deleted',
                    'failure_session' => 'language_error'
                ];

            case '/admin/translations/interface/save':
                return ['action' => 'translation.interface_updated'];
        }

        return null;
    }


    private static function detailsFor($path)
    {
        switch ($path) {
            case '/admin/orders/status':
                $details = [
                    'order_id' => self::postInt('order_id'),
                    'order_type' => self::postString('order_type', 20),
                    'new_status' => self::postString('status', 40)
                ];
                $oldStatus = self::orderStatusBeforeChange(
                    $details['order_type'],
                    $details['order_id']
                );

                if ($oldStatus !== '') {
                    $details['old_status'] = $oldStatus;
                }

                return self::cleanDetails($details);

            case '/admin/products/save':
            case '/admin/products/update':
                return self::cleanDetails([
                    'product_id' => self::postInt('product_id'),
                    'name' => self::postString('name', 180),
                    'sku' => self::postString('sku', 100),
                    'category_id' => self::postInt('category_id'),
                    'is_active' => self::postFlag('is_active'),
                    'stock_mode' => self::postString('stock_mode', 30)
                ]);

            case '/admin/products/toggle':
                return self::cleanDetails([
                    'product_id' => self::postInt('product_id'),
                    'is_active' => self::postFlag('is_active')
                ]);

            case '/admin/products/duplicate':
                return self::cleanDetails([
                    'source_product_id' => self::postInt('product_id')
                ]);

            case '/admin/products/variant-stock/save':
                return self::cleanDetails([
                    'product_id' => self::postInt('product_id')
                ]);

            case '/admin/categories/update':
                return self::cleanDetails([
                    'category_id' => self::postInt('category_id'),
                    'name' => self::postString('name', 180)
                ]);

            case '/admin/users/invite/create':
                return self::cleanDetails([
                    'email' => self::postString('invite_email', 190),
                    'rank_id' => self::postInt('invite_rank_id'),
                    'channel' => self::postString('invite_channel', 30)
                ]);

            case '/admin/users/invite/sent':
                return self::cleanDetails([
                    'user_id' => self::postInt('user_id')
                ]);

            case '/admin/users/rank':
                $details = [
                    'user_id' => self::postInt('user_id'),
                    'new_rank_id' => self::postInt('rank_id')
                ];
                $oldRankId = self::userRankBeforeChange($details['user_id']);

                if ($oldRankId > 0) {
                    $details['old_rank_id'] = $oldRankId;
                }

                return self::cleanDetails($details);

            case '/admin/ranks/create':
                return self::cleanDetails([
                    'name' => self::postString('rank_name', 100)
                ]);

            case '/admin/ranks/update':
                return self::cleanDetails([
                    'rank_id' => self::postInt('rank_id'),
                    'name' => self::postString('name', 100)
                ]);

            case '/admin/ranks/move':
                return self::cleanDetails([
                    'rank_id' => self::postInt('rank_id'),
                    'direction' => self::postString('direction', 20)
                ]);

            case '/admin/ranks/toggle':
            case '/admin/ranks/default':
                return self::cleanDetails([
                    'rank_id' => self::postInt('rank_id')
                ]);

            case '/admin/delivery/toggle-method':
                return self::cleanDetails([
                    'method_id' => self::postInt('method_id'),
                    'is_active' => self::postFlag('is_active')
                ]);

            case '/admin/delivery/toggle-service':
                return self::cleanDetails([
                    'service_id' => self::postInt('service_id'),
                    'is_active' => self::postFlag('is_active')
                ]);

            case '/admin/delivery/toggle-option':
                return self::cleanDetails([
                    'option_id' => self::postInt('option_id'),
                    'is_active' => self::postFlag('is_active')
                ]);

            case '/admin/delivery/update':
                return self::cleanDetails([
                    'type' => self::postString('type', 20),
                    'id' => self::postInt('id'),
                    'name' => self::postString('name', 180)
                ]);

            case '/admin/delivery/delete':
                return self::cleanDetails([
                    'type' => self::postString('type', 20),
                    'id' => self::postInt('id')
                ]);

            case '/admin/delivery/create-method':
                return self::cleanDetails([
                    'name' => self::postString('name', 180)
                ]);

            case '/admin/delivery/create-service':
                return self::cleanDetails([
                    'delivery_method_id' => self::postInt('delivery_method_id'),
                    'name' => self::postString('name', 180)
                ]);

            case '/admin/delivery/create-option':
                return self::cleanDetails([
                    'delivery_service_id' => self::postInt('delivery_service_id'),
                    'name' => self::postString('name', 180)
                ]);

            case '/admin/delivery/option-input':
                return self::cleanDetails([
                    'option_id' => self::postInt('option_id'),
                    'is_enabled' => self::postFlag('is_enabled')
                ]);

            case '/admin/delivery/translations':
                return self::cleanDetails([
                    'type' => self::postString('type', 20),
                    'id' => self::postInt('id')
                ]);

            case '/admin/languages/create':
                return self::cleanDetails([
                    'language_code' => self::postString('language_code', 16)
                ]);

            case '/admin/languages/update':
                return self::cleanDetails([
                    'language_id' => self::postInt('language_id'),
                    'name' => self::postString('name', 100)
                ]);

            case '/admin/languages/toggle':
            case '/admin/languages/default':
            case '/admin/languages/delete':
                return self::cleanDetails([
                    'language_id' => self::postInt('language_id')
                ]);

            case '/admin/translations/interface/save':
                return self::cleanDetails([
                    'translation_key' => self::postString(
                        'translation_key',
                        190
                    )
                ]);
        }

        return [];
    }


    public static function requestSucceeded(array $spec)
    {
        $lastError = error_get_last();

        if (
            is_array($lastError)
            && in_array(
                (int) ($lastError['type'] ?? 0),
                [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR],
                true
            )
        ) {
            return false;
        }

        $status = http_response_code();

        if (is_int($status) && $status >= 400) {
            return false;
        }

        foreach (headers_list() as $header) {
            if (stripos((string) $header, 'Location:') !== 0) {
                continue;
            }

            $location = trim(substr((string) $header, 9));
            $queryString = parse_url($location, PHP_URL_QUERY);

            if (!is_string($queryString) || $queryString === '') {
                continue;
            }

            $query = [];
            parse_str($queryString, $query);

            if (trim((string) ($query['error'] ?? '')) !== '') {
                return false;
            }
        }

        $failureSession = (string) ($spec['failure_session'] ?? '');

        if ($failureSession === 'admin_order_flash') {
            return (string) (
                $_SESSION['admin_order_flash']['type'] ?? ''
            ) !== 'error';
        }

        if ($failureSession === 'admin_product_flash') {
            return (string) (
                $_SESSION['admin_product_flash']['type'] ?? ''
            ) !== 'error';
        }

        if ($failureSession === 'language_error') {
            return trim((string) (
                $_SESSION['language_error'] ?? ''
            )) === '';
        }

        return true;
    }


    private static function orderStatusBeforeChange($type, $orderId)
    {
        $type = strtolower(trim((string) $type));
        $orderId = (int) $orderId;

        if ($orderId <= 0 || !in_array($type, ['regular', 'quick'], true)) {
            return '';
        }

        $table = $type === 'regular' ? 'orders' : 'quick_orders';

        try {
            $stmt = Database::connect()->prepare(
                "SELECT status FROM {$table} WHERE id = :id LIMIT 1"
            );
            $stmt->execute(['id' => $orderId]);

            return trim((string) $stmt->fetchColumn());
        } catch (Throwable $e) {
            return '';
        }
    }


    private static function userRankBeforeChange($userId)
    {
        $userId = (int) $userId;

        if ($userId <= 0) {
            return 0;
        }

        try {
            $stmt = Database::connect()->prepare(
                'SELECT rank_id FROM users WHERE id = :id LIMIT 1'
            );
            $stmt->execute(['id' => $userId]);

            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }


    private static function postInt($key)
    {
        return (int) ($_POST[(string) $key] ?? 0);
    }


    private static function postFlag($key)
    {
        return (int) ($_POST[(string) $key] ?? 0) === 1 ? 1 : 0;
    }


    private static function postString($key, $limit)
    {
        $value = $_POST[(string) $key] ?? '';

        if (!is_scalar($value)) {
            return '';
        }

        $value = trim((string) $value);
        $limit = max(1, (int) $limit);

        if (function_exists('mb_substr')) {
            return (string) mb_substr($value, 0, $limit, 'UTF-8');
        }

        return substr($value, 0, $limit);
    }


    private static function cleanDetails(array $details)
    {
        foreach ($details as $key => $value) {
            if ($value === '' || $value === null) {
                unset($details[$key]);
            }
        }

        return $details;
    }
}
