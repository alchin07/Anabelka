<?php

require_once __DIR__ . '/../Core/Database.php';
require_once __DIR__ . '/../Models/AppSetting.php';
require_once __DIR__ . '/../Models/AdminAccess.php';

class ErrorDisplayMode
{
    public const KEY = 'error_display_mode';

    public static function current()
    {
        try {
            $mode = AppSetting::getExisting(self::KEY, 'production');
            return in_array($mode, ['development', 'production'], true) ? $mode : 'production';
        } catch (Throwable $e) {
            return 'production';
        }
    }


    public static function diagnostics($status, array $context = [])
    {
        if (!empty($context['diagnostics_disabled'])) {
            return [];
        }
        try {
            $admin = AdminAccess::currentForDiagnostics();
            if (!$admin || ($admin['role_slug'] ?? '') !== 'owner' || self::current() !== 'development') {
                return [];
            }

            $status = (int) $status;
            $exception = $context['exception'] ?? null;
            $isDatabaseError = $exception instanceof PDOException;
            $event = $status === 403 ? 'Відмовлено в доступі'
                : ($status === 404 ? 'Маршрут або ресурс не знайдено'
                    : ($isDatabaseError ? 'Помилка бази даних' : 'Внутрішня помилка застосунку'));
            $reason = $status === 403 ? 'Перевірка доступу або захисту запиту не дозволила виконати дію.'
                : ($status === 404 ? 'Запит не відповідає доступному маршруту або ресурс відсутній.'
                    : ($isDatabaseError ? 'Не вдалося виконати операцію з базою даних.'
                        : 'Операцію не завершено через внутрішню помилку застосунку.'));
            $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
            $result = [
                'status' => $status,
                'event' => $event,
                'reason' => $reason,
                'method' => in_array($method, ['GET', 'POST', 'HEAD', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true) ? $method : 'HTTP',
                'route' => self::safeRoute((string) ($_SERVER['REQUEST_URI'] ?? ''))
            ];

            // Never display exception messages, traces, SQL or arbitrary context.
            if ($exception instanceof Throwable) {
                $class = get_class($exception);
                // PHP embeds the absolute source path in anonymous class names.
                while ($class && !preg_match('/^[a-zA-Z_][a-zA-Z0-9_\\\\]{0,199}$/D', $class)) {
                    $class = get_parent_class($class);
                }
                $result['exception_class'] = $class ?: 'Throwable';
            }
            $file = self::relativeFile($exception instanceof Throwable ? $exception->getFile() : ($context['file'] ?? ''));
            if ($file !== '') {
                $result['file'] = $file;
                $result['line'] = max(0, (int) ($exception instanceof Throwable ? $exception->getLine() : ($context['line'] ?? 0)));
            }

            $reference = $context['reference'] ?? '';
            if (is_string($reference) && preg_match('/^ERR-\d{8}-\d{6}-[A-F0-9]{6}$/D', $reference)) {
                $result['reference'] = $reference;
                $result['log_url'] = '/Anabelka/admin/system/errors?ref=' . rawurlencode($reference);
            }

            if ($status === 403) {
                $permission = $context['permission'] ?? '';
                if (is_string($permission) && preg_match('/^[a-z_]{1,40}\.[a-z_]{1,40}$/D', $permission)) {
                    $result['permission'] = $permission;
                }
                $result['role'] = (string) $admin['role_slug'];
            }

            return $result;
        } catch (Throwable $e) {
            return [];
        }
    }


    // Called only by the CSRF-protected settings action, never by error rendering.
    public static function save($mode, $confirmed)
    {
        $admin = AdminAccess::currentForDiagnostics();
        if (!$admin || ($admin['role_slug'] ?? '') !== 'owner') {
            throw new RuntimeException('Недостатньо прав для зміни режиму.');
        }
        if (!is_string($mode) || !in_array($mode, ['development', 'production'], true)) {
            throw new InvalidArgumentException('Оберіть один із доступних режимів.');
        }
        if ($confirmed !== '1') {
            throw new InvalidArgumentException('Підтвердьте зміну режиму відображення помилок.');
        }

        // Normal settings actions may initialize existing application schemas.
        // Do this before starting the atomic setting+audit transaction (MySQL DDL
        // commits transactions implicitly).
        AdminAccess::ensureSchema();
        AppSetting::get(self::KEY, 'production');
        $db = Database::connect();
        $db->beginTransaction();
        try {
            $insert = $db->prepare("INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES (:key, 'production')");
            $insert->execute(['key' => self::KEY]);
            $read = $db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :key FOR UPDATE');
            $read->execute(['key' => self::KEY]);
            $old = $read->fetchColumn();
            $old = in_array($old, ['development', 'production'], true) ? $old : 'production';
            AppSetting::setExisting(self::KEY, $mode);
            AdminAccess::audit('system.error_display_mode.update', [
                'old_mode' => $old,
                'new_mode' => $mode
            ], (int) $admin['id'], true);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }


    private static function safeRoute($uri)
    {
        $path = parse_url(substr($uri, 0, 4096), PHP_URL_PATH);
        if (!is_string($path)) {
            return '/[приховано]';
        }
        $known = [
            'Anabelka', basename(dirname(__DIR__, 2)), 'admin', 'system', 'catalog',
            'product', 'search', 'api', 'cart', 'account', 'login', 'logout',
            'error-display', 'errors', 'error-test', 'profile', 'products',
            'categories', 'orders', 'users', 'administrators', 'audit'
        ];
        $segments = explode('/', trim(rawurldecode($path), '/'));
        $safe = [];
        foreach (array_slice($segments, 0, 12) as $segment) {
            if ($segment !== '') {
                $safe[] = in_array($segment, $known, true) ? $segment : '[приховано]';
            }
        }
        return '/' . implode('/', $safe);
    }


    private static function relativeFile($file)
    {
        if (!is_string($file) || strpos($file, "\0") !== false) {
            return '';
        }
        $root = str_replace('\\', '/', dirname(__DIR__, 2)) . '/';
        $file = str_replace('\\', '/', $file);
        $inside = DIRECTORY_SEPARATOR === '\\' ? stripos($file, $root) === 0 : strpos($file, $root) === 0;
        if (!$inside) {
            return '';
        }
        $relative = substr($file, strlen($root));
        return preg_match('#^[a-zA-Z0-9_./-]{1,240}$#D', $relative)
            && !in_array('..', explode('/', $relative), true) ? $relative : '';
    }
}
