<?php

class PublicErrorPage
{
    private static $projectRoot = '';
    private static $bufferLevel = 0;
    private static $registered = false;
    private static $rendered = false;


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
        $statusCode = (int) http_response_code();
        if (
            ($statusCode !== 404 && ($statusCode < 500 || $statusCode > 599))
            || self::$rendered
            || self::$bufferLevel < 1
            || ob_get_level() !== self::$bufferLevel
        ) {
            return;
        }

        $body = (string) ob_get_contents();

        // Some existing controllers catch exceptions themselves and echo the
        // message. Apply the same privacy policy to their final 5xx response.
        if ($statusCode >= 500) {
            $json = self::expectsJson() || preg_match('/^\s*[\[{]/', $body);
            ob_end_clean();
            self::$bufferLevel = 0;
            if ($json) {
                self::renderSafeJsonError($statusCode, $body);
            } else {
                self::renderGeneric($statusCode);
            }
            return;
        }

        if (!self::shouldReplace($body)) {
            return;
        }

        ob_end_clean();

        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: no-store');
        }

        [$errorTitle, $errorMessage] = self::copyFor($body);
        self::renderError(
            404,
            $errorTitle,
            $errorMessage
        );
    }


    public static function renderGeneric($statusCode = 500, array $context = [])
    {
        $statusCode = (int) $statusCode;

        if ($statusCode < 400 || $statusCode > 599) {
            $statusCode = 500;
        }

        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Cache-Control: no-store, no-cache, must-revalidate');
        }

        self::renderError(
            $statusCode,
            'Щось пішло не так',
            'Спробуйте ще раз.',
            $context
        );
    }


    public static function renderNotFound($body = '')
    {
        [$title, $message] = self::copyFor($body);
        self::renderError(404, $title, $message);
    }


    // Remove incomplete application output before an exception response.
    public static function discardOutput()
    {
        if (self::$bufferLevel < 1) {
            return;
        }
        while (ob_get_level() >= self::$bufferLevel) {
            if (!@ob_end_clean()) {
                break;
            }
        }
        self::$bufferLevel = 0;
    }


    private static function renderError($statusCode, $errorTitle, $errorMessage, array $context = [])
    {
        self::$rendered = true;
        if (!headers_sent()) {
            http_response_code((int) $statusCode);
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('X-Robots-Tag: noindex, nofollow, noarchive');
        }
        if (self::expectsJson()) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=UTF-8');
            }
            echo json_encode(['ok' => false, 'error' => (string) $errorMessage], JSON_UNESCAPED_UNICODE);
            return;
        }
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
        }
        $context = self::recordHttpError($statusCode, $context);
        self::renderView($statusCode, $errorTitle, $errorMessage, $context);
    }


    private static function recordHttpError($statusCode, array $context = [])
    {
        if (empty($context['reference']) && class_exists('ErrorHandler')) {
            try {
                $context['reference'] = ErrorHandler::log($statusCode >= 500 ? 'error' : 'warning', 'HTTP ' . (int) $statusCode, [
                    'http_status' => (int) $statusCode,
                    'event' => (int) $statusCode === 403 ? 'access_denied' : ($statusCode >= 500 ? 'internal_error' : 'resource_not_found'),
                    'permission' => is_string($context['permission'] ?? null) ? $context['permission'] : ''
                ]);
            } catch (Throwable $e) {
                // Rendering remains available when the existing log is unavailable.
                $context['diagnostics_disabled'] = true;
            }
        }
        return $context;
    }


    private static function renderSafeJsonError($statusCode, $body)
    {
        self::$rendered = true;
        self::recordHttpError($statusCode);
        $payload = json_decode($body);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $payload = (object) ['ok' => false, 'error' => 'Спробуйте ще раз.'];
        }
        $walk = static function ($value, $sensitive = false) use (&$walk) {
            if (is_array($value) || is_object($value)) {
                $clean = is_object($value) ? new stdClass() : [];
                foreach ($value as $key => $child) {
                    $blocked = $sensitive || preg_match('/(?:error|message|reason|exception|trace|stack|file|sql|query|debug|details|password|token|secret|session|cookie|authorization)/i', (string) $key);
                    $child = $walk($child, $blocked);
                    if (is_object($clean)) { $clean->{$key} = $child; } else { $clean[$key] = $child; }
                }
                return $clean;
            }
            return $sensitive && is_string($value) ? 'Спробуйте ще раз.' : $value;
        };
        // Preserve the object/array shape, booleans, numeric codes, retry values
        // and translated labels that existing clients rely on.
        $payload = is_string($payload) ? 'Спробуйте ще раз.' : $walk($payload);
        if (!headers_sent()) {
            http_response_code((int) $statusCode);
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('X-Robots-Tag: noindex, nofollow, noarchive');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }


    private static function renderView($statusCode, $errorTitle, $errorMessage, array $context = [])
    {
        $statusCode = (int) $statusCode;
        $errorTitle = trim((string) $errorTitle);
        $errorMessage = trim((string) $errorMessage);

        if ($errorTitle === '') {
            $errorTitle = 'Щось пішло не так';
        }

        if ($errorMessage === '') {
            $errorMessage = 'Спробуйте ще раз.';
        }

        $root = self::$projectRoot !== ''
            ? self::$projectRoot
            : dirname(__DIR__, 2);
        $view = $root . '/views/errors/public.php';

        $diagnostics = [];
        try {
            require_once __DIR__ . '/../Services/ErrorDisplayMode.php';
            $diagnostics = ErrorDisplayMode::diagnostics($statusCode, $context);
        } catch (Throwable $e) {
            $diagnostics = [];
        }

        if (!is_file($view)) {
            self::renderFallback();
            return;
        }

        $level = ob_get_level();
        ob_start();
        try {
            require $view;
            $html = ob_get_clean();
            echo $html;
        } catch (Throwable $e) {
            while (ob_get_level() > $level && @ob_end_clean()) {}
            self::renderFallback();
        }
    }


    private static function renderFallback()
    {
        echo '<!doctype html><html lang="uk"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow,noarchive"><title>Анабелька</title>'
            . '<style>body{margin:0;background:#faf7ff;color:#20202a;font-family:Arial,sans-serif}'
            . 'main{box-sizing:border-box;width:min(560px,calc(100% - 24px));margin:10vh auto;'
            . 'padding:24px;background:#fff;border:1px solid #eadcf7;border-radius:18px;text-align:center}'
            . 'h1{font-size:28px}p{line-height:1.5}a{display:inline-block;padding:12px 18px;'
            . 'border-radius:12px;background:#8a2be2;color:#fff;text-decoration:none}</style></head>'
            . '<body><main><h1>Щось пішло не так</h1><p>Спробуйте ще раз.</p>'
            . '<a href="/Anabelka/">На головну</a></main></body></html>';
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


    public static function expectsJson()
    {
        foreach (headers_list() as $header) {
            if (
                stripos((string) $header, 'content-type:') === 0
                && preg_match('#application/[^; ]*json#i', (string) $header)
            ) {
                return true;
            }
        }

        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $requestedWith = strtolower(
            (string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')
        );

        return strpos($accept, 'application/json') !== false
            || $requestedWith === 'xmlhttprequest'
            || trim((string) ($_SERVER['HTTP_X_ANABELKA_REQUEST'] ?? '')) !== ''
            || strpos((string) ($_SERVER['REQUEST_URI'] ?? ''), '/api/') !== false;
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
