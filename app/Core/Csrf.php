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


    public static function token($family = 'customer')
    {
        if ($family === 'admin' && class_exists('AdminAccess')) {
            return AdminAccess::csrfToken();
        }

        if ($family === 'customer' && class_exists('CustomerAccount')) {
            if (method_exists('CustomerAccount', 'csrfToken')) {
                return CustomerAccount::csrfToken();
            }
        }

        return '';
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

        self::forbidden();
    }


    private static function forbidden()
    {
        http_response_code(403);

        $accept = strtolower(
            (string) ($_SERVER['HTTP_ACCEPT'] ?? '')
        );
        $requestedWith = strtolower(
            (string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')
        );
        $anabelkaRequest = trim(
            (string) ($_SERVER['HTTP_X_ANABELKA_REQUEST'] ?? '')
        );

        $expectsJson =
            strpos($accept, 'application/json') !== false
            || $requestedWith === 'xmlhttprequest'
            || $anabelkaRequest !== '';

        if ($expectsJson) {
            header('Content-Type: application/json; charset=UTF-8');

            echo json_encode(
                [
                    'success' => false,
                    'message' => 'CSRF token is missing or invalid.'
                ],
                JSON_UNESCAPED_UNICODE
            );
            exit;
        }

        header('Content-Type: text/html; charset=UTF-8');

        echo '<!doctype html>'
            . '<html lang="uk">'
            . '<head>'
            . '<meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>403 — Анабелька</title>'
            . '</head>'
            . '<body style="font-family:system-ui,sans-serif;padding:24px">'
            . '<h1>403 — Запит відхилено</h1>'
            . '<p>Сесію форми застаріло або CSRF-токен недійсний.</p>'
            . '<p>Оновіть сторінку та повторіть дію.</p>'
            . '</body>'
            . '</html>';
        exit;
    }
}
