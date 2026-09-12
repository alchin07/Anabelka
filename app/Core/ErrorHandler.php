<?php

class ErrorHandler
{
    private static $projectRoot = '';
    private static $handling = false;
    private static $notifying = false;


    public static function register($projectRoot)
    {
        self::$projectRoot = rtrim((string) $projectRoot, '/\\');

        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');

        set_error_handler([self::class, 'handlePhpError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }


    public static function handlePhpError($severity, $message, $file, $line)
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        $level = self::levelForSeverity((int) $severity);
        self::writeLog($level, 'php_error', [
            'severity' => (int) $severity,
            'message' => (string) $message,
            'file' => self::relativePath((string) $file),
            'line' => (int) $line
        ]);

        // Не змінюємо поведінку звичайних warning/notice під час першого етапу
        // впровадження. display_errors вимкнено, але PHP може записати їх у свій лог.
        return false;
    }


    public static function handleException($throwable)
    {
        if (self::$handling) {
            self::fallbackLog('critical', 'recursive_exception', [
                'message' => is_object($throwable) && method_exists($throwable, 'getMessage')
                    ? $throwable->getMessage()
                    : 'Unknown exception'
            ]);
            return;
        }

        self::$handling = true;

        try {
            $reference = self::writeThrowable('error', 'uncaught_exception', $throwable);
            self::respondSafely($reference);
        } catch (Throwable $loggingError) {
            self::fallbackLog('critical', 'error_handler_failure', [
                'message' => $loggingError->getMessage()
            ]);
            self::respondSafely('ERR-UNAVAILABLE');
        }

        self::$handling = false;
    }


    public static function handleShutdown()
    {
        $error = error_get_last();

        if (!$error || !self::isFatalSeverity((int) ($error['type'] ?? 0))) {
            return;
        }

        if (self::$handling) {
            return;
        }

        self::$handling = true;

        try {
            $reference = self::writeLog('critical', 'fatal_error', [
                'severity' => (int) ($error['type'] ?? 0),
                'message' => (string) ($error['message'] ?? 'Fatal error'),
                'file' => self::relativePath((string) ($error['file'] ?? '')),
                'line' => (int) ($error['line'] ?? 0)
            ]);

            self::respondSafely($reference);
        } catch (Throwable $loggingError) {
            self::fallbackLog('critical', 'shutdown_handler_failure', [
                'message' => $loggingError->getMessage()
            ]);
        }

        self::$handling = false;
    }


    public static function report($throwable, $level = 'error', $kind = 'handled_exception')
    {
        return self::writeThrowable(
            self::normalizeLevel($level),
            trim((string) $kind) !== '' ? trim((string) $kind) : 'handled_exception',
            $throwable
        );
    }


    public static function log($level, $message, array $context = [])
    {
        $context['message'] = (string) $message;

        return self::writeLog(
            self::normalizeLevel($level),
            'application',
            $context
        );
    }


    private static function writeThrowable($level, $kind, $throwable)
    {
        $context = [
            'class' => is_object($throwable) ? get_class($throwable) : 'Unknown',
            'message' => is_object($throwable) && method_exists($throwable, 'getMessage')
                ? (string) $throwable->getMessage()
                : 'Unknown exception',
            'file' => is_object($throwable) && method_exists($throwable, 'getFile')
                ? self::relativePath((string) $throwable->getFile())
                : '',
            'line' => is_object($throwable) && method_exists($throwable, 'getLine')
                ? (int) $throwable->getLine()
                : 0,
            'trace' => is_object($throwable) && method_exists($throwable, 'getTraceAsString')
                ? self::sanitizeTrace((string) $throwable->getTraceAsString())
                : ''
        ];

        return self::writeLog($level, $kind, $context);
    }


    private static function writeLog($level, $kind, array $context)
    {
        $reference = self::reference();
        $record = [
            'time' => date('c'),
            'reference' => $reference,
            'level' => self::normalizeLevel($level),
            'kind' => (string) $kind,
            'request' => self::requestContext(),
            'context' => self::sanitizeContext($context)
        ];

        $directory = self::logDirectory();

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Не вдалося створити каталог системних логів.');
        }

        $line = json_encode(
            $record,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if (!is_string($line)) {
            throw new RuntimeException('Не вдалося сформувати запис системного логу.');
        }

        $path = $directory . '/app-' . date('Y-m-d') . '.log';
        self::appendLogLine($path, $line . PHP_EOL);
        self::notifyExternal($reference);

        return $reference;
    }


    private static function appendLogLine($path, $line)
    {
        // На деяких Android/KSWEB файлових системах advisory locking (LOCK_EX)
        // не підтримується: файл може створитися, але file_put_contents повертає false.
        // Спочатку використовуємо блокування, а потім безпечний fallback без нього.
        $written = @file_put_contents(
            (string) $path,
            (string) $line,
            FILE_APPEND | LOCK_EX
        );

        if ($written !== false) {
            return;
        }

        $written = @file_put_contents(
            (string) $path,
            (string) $line,
            FILE_APPEND
        );

        if ($written === false) {
            throw new RuntimeException('Не вдалося записати системний лог.');
        }
    }


    private static function notifyExternal($reference)
    {
        if (self::$notifying) {
            return;
        }

        self::$notifying = true;

        try {
            $root = self::$projectRoot !== ''
                ? self::$projectRoot
                : dirname(__DIR__, 2);

            require_once __DIR__ . '/Database.php';
            require_once __DIR__ . '/../Models/AppSetting.php';
            require_once __DIR__ . '/../Models/SystemErrorLog.php';
            require_once __DIR__ . '/../Models/SystemErrorExternalNotificationSettings.php';
            require_once __DIR__ . '/../Services/SystemErrorExternalNotifier.php';

            if (!class_exists('SystemErrorLog')
                || !class_exists('SystemErrorExternalNotificationSettings')
                || !class_exists('SystemErrorExternalNotifier')) {
                return;
            }

            $group = SystemErrorLog::groupForReference((string) $reference);

            if (!is_array($group)) {
                return;
            }

            SystemErrorExternalNotifier::sendAutomatic($group);
        } catch (Throwable $notificationError) {
            self::fallbackLog('warning', 'external_notification_failure', [
                'reference' => (string) $reference,
                'message' => $notificationError->getMessage()
            ]);
        } finally {
            self::$notifying = false;
        }
    }


    private static function requestContext()
    {
        $session = isset($_SESSION) && is_array($_SESSION) ? $_SESSION : [];

        return [
            'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
            'uri' => self::sanitizeUri((string) ($_SERVER['REQUEST_URI'] ?? '')),
            'user_id' => (int) ($session['user_id'] ?? 0),
            'admin_user_id' => (int) ($session['admin_user_id'] ?? 0)
        ];
    }


    private static function sanitizeContext(array $context)
    {
        $blocked = [
            'password', 'current_password', 'new_password', 'password_confirmation',
            'token', 'access_token', 'refresh_token', 'client_secret', 'secret',
            'authorization', 'cookie'
        ];

        $walk = function ($value, $key = '') use (&$walk, $blocked) {
            $normalizedKey = strtolower((string) $key);

            foreach ($blocked as $needle) {
                if ($normalizedKey !== '' && strpos($normalizedKey, $needle) !== false) {
                    return '[REDACTED]';
                }
            }

            if (is_array($value)) {
                $clean = [];
                foreach ($value as $childKey => $childValue) {
                    $clean[$childKey] = $walk($childValue, $childKey);
                }
                return $clean;
            }

            if (is_object($value)) {
                return '[OBJECT ' . get_class($value) . ']';
            }

            if (is_resource($value)) {
                return '[RESOURCE]';
            }

            if (is_string($value) && strlen($value) > 8000) {
                return substr($value, 0, 8000) . '…';
            }

            return $value;
        };

        return $walk($context);
    }


    private static function sanitizeTrace($trace)
    {
        $trace = str_replace(self::$projectRoot, '[PROJECT]', (string) $trace);

        return strlen($trace) > 16000
            ? substr($trace, 0, 16000) . '…'
            : $trace;
    }


    private static function sanitizeUri($uri)
    {
        $parts = parse_url((string) $uri);

        if (!is_array($parts)) {
            return '';
        }

        $path = (string) ($parts['path'] ?? '');
        $query = (string) ($parts['query'] ?? '');

        if ($query === '') {
            return $path;
        }

        parse_str($query, $params);
        $params = self::sanitizeContext(is_array($params) ? $params : []);
        $safeQuery = http_build_query($params);

        return $safeQuery !== '' ? $path . '?' . $safeQuery : $path;
    }


    private static function respondSafely($reference)
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        if (!headers_sent()) {
            http_response_code(500);
            header('Cache-Control: no-store, no-cache, must-revalidate');
        }

        $message = 'Виникла внутрішня помилка. Спробуйте ще раз.';

        if (self::expectsJson()) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=UTF-8');
            }

            echo json_encode(
                [
                    'ok' => false,
                    'error' => $message,
                    'reference' => (string) $reference
                ],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            return;
        }

        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
        }

        $safeReference = htmlspecialchars((string) $reference, ENT_QUOTES, 'UTF-8');
        echo '<!DOCTYPE html><html lang="uk"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Помилка — Анабелька</title>'
            . '<style>body{margin:0;background:#faf7ff;color:#302735;font-family:Arial,sans-serif}'
            . '.e{width:min(560px,calc(100% - 32px));margin:12vh auto;padding:26px;box-sizing:border-box;'
            . 'background:#fff;border:1px solid #eadcf7;border-radius:18px;text-align:center}'
            . 'h1{margin:0 0 10px;font-size:24px}p{line-height:1.5;color:#6b6170}'
            . 'a{display:inline-block;margin-top:8px;padding:11px 16px;border-radius:11px;'
            . 'background:#8a2be2;color:#fff;text-decoration:none;font-weight:700}'
            . 'small{display:block;margin-top:16px;color:#9a909e}</style></head><body>'
            . '<main class="e"><h1>Щось пішло не так</h1><p>'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
            . '</p><a href="/Anabelka/">На головну</a><small>Код помилки: '
            . $safeReference
            . '</small></main></body></html>';
    }


    private static function expectsJson()
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');

        return strpos($accept, 'application/json') !== false
            || $requestedWith === 'xmlhttprequest'
            || strpos($uri, '/api/') !== false;
    }


    private static function reference()
    {
        try {
            $suffix = strtoupper(bin2hex(random_bytes(3)));
        } catch (Throwable $e) {
            $suffix = strtoupper(substr(md5(uniqid('', true)), 0, 6));
        }

        return 'ERR-' . date('Ymd-His') . '-' . $suffix;
    }


    private static function logDirectory()
    {
        $root = self::$projectRoot !== ''
            ? self::$projectRoot
            : dirname(__DIR__, 2);

        return $root . '/storage/logs';
    }


    private static function relativePath($file)
    {
        $file = (string) $file;

        if (self::$projectRoot !== '' && strpos($file, self::$projectRoot) === 0) {
            return '[PROJECT]' . substr($file, strlen(self::$projectRoot));
        }

        return $file;
    }


    private static function fallbackLog($level, $kind, array $context)
    {
        $message = '[' . strtoupper((string) $level) . '] '
            . (string) $kind . ' '
            . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        error_log($message);
    }


    private static function levelForSeverity($severity)
    {
        switch ((int) $severity) {
            case E_ERROR:
            case E_USER_ERROR:
            case E_RECOVERABLE_ERROR:
                return 'error';

            case E_WARNING:
            case E_USER_WARNING:
                return 'warning';

            default:
                return 'info';
        }
    }


    private static function normalizeLevel($level)
    {
        $level = strtolower(trim((string) $level));

        return in_array($level, ['info', 'warning', 'error', 'critical'], true)
            ? $level
            : 'error';
    }


    private static function isFatalSeverity($severity)
    {
        return in_array(
            (int) $severity,
            [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR],
            true
        );
    }
}
