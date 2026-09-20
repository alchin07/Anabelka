<?php

class Csrf
{
    public static function tokenFromRequest()
    {
        $token = $_POST['_csrf'] ?? $_POST['csrf_token'] ?? '';

        if (is_string($token) && $token !== '') {
            return $token;
        }

        $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

        return is_string($header) ? $header : '';
    }

    public static function verify($family = 'admin')
    {
        $token = self::tokenFromRequest();

        if ($family === 'admin' && class_exists('AdminAccess')) {
            return AdminAccess::verifyCsrf($token);
        }

        if ($family === 'customer' && class_exists('CustomerAccount')) {
            if (method_exists('CustomerAccount', 'verifyCsrf')) {
                return CustomerAccount::verifyCsrf($token);
            }
        }

        return false;
    }

    public static function enforce($family = 'admin')
    {
        if (self::verify($family)) {
            return;
        }

        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');

        echo json_encode([
            'success' => false,
            'message' => 'CSRF token is missing or invalid.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
