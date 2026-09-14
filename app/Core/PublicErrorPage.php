<?php

class PublicErrorPage
{
    private static $projectRoot = '';
    private static $bufferLevel = 0;
    private static $registered = false;


    public static function register($projectRoot)
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;
        self::$projectRoot = rtrim((string) $projectRoot, '/\\');
        ob_start();
        self::$bufferLevel = ob_get_level();
        register_shutdown_function([self::class, 'renderIfNeeded']);
    }


    public static function renderIfNeeded()
    {
        if (
            http_response_code() !== 404
            || self::$bufferLevel < 1
            || ob_get_level() !== self::$bufferLevel
        ) {
            return;
        }

        $body = (string) ob_get_contents();

        if (!self::shouldReplace($body)) {
            return;
        }

        ob_end_clean();

        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: no-store');
        }

        [$errorTitle, $errorMessage] = self::copyFor($body);
        $errorCode = 404;
        $view = self::$projectRoot . '/views/errors/public.php';

        if (!is_file($view)) {
            echo '404 — ' . htmlspecialchars(
                $errorTitle,
                ENT_QUOTES,
                'UTF-8'
            );
            return;
        }

        require $view;
    }


    private static function shouldReplace($body)
    {
        $body = trim((string) $body);

        if ($body === '' || strlen($body) > 1000) {
            return false;
        }

        if (strpos($body, '<') !== false) {
            return false;
        }

        $first = substr($body, 0, 1);

        if ($first === '{' || $first === '[' || self::expectsJson()) {
            return false;
        }

        return true;
    }


    private static function expectsJson()
    {
        foreach (headers_list() as $header) {
            if (
                stripos((string) $header, 'content-type:') === 0
                && stripos((string) $header, 'application/json') !== false
            ) {
                return true;
            }
        }

        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $requestedWith = strtolower(
            (string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')
        );

        return strpos($accept, 'application/json') !== false
            || $requestedWith === 'xmlhttprequest';
    }


    private static function copyFor($body)
    {
        $body = (string) $body;

        if (preg_match('/товар/iu', $body)) {
            return [
                'Товар не знайдено',
                'Можливо, товар було видалено, переміщено або посилання застаріло.'
            ];
        }

        if (preg_match('/категор/iu', $body)) {
            return [
                'Категорію не знайдено',
                'Можливо, категорію було переміщено або посилання застаріло.'
            ];
        }

        return [
            'Сторінку не знайдено',
            'Перевірте адресу або поверніться до каталогу.'
        ];
    }
}
