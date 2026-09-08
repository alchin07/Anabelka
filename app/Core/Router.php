<?php

class Router
{
    private array $routes = [];


    public function get($path, $action)
    {
        $this->routes['GET'][$path] = $action;
    }


    public function post($path, $action)
    {
        $this->routes['POST'][$path] = $action;
    }


    public function dispatch($uri, $method)
    {
        $method = strtoupper((string) $method);

        if ($method === 'HEAD') {
            $method = 'GET';
        }

        $path = parse_url((string) $uri, PHP_URL_PATH);

        if ($path === false || $path === null) {
            $path = '/';
        }

        $path = rawurldecode((string) $path);
        $path = preg_replace('#/+#', '/', $path);

        $projectFolder = '/' . basename(dirname(__DIR__, 2));

        if (
            $path === $projectFolder
            || strpos($path, $projectFolder . '/') === 0
        ) {
            $path = substr($path, strlen($projectFolder));
        }

        if ($path === '' || $path === false) {
            $path = '/';
        }

        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        $this->guardAdminRoute($path, $method, $uri);

        if (class_exists('AdminActionAudit')) {
            AdminActionAudit::watch($path, $method);
        }

        foreach ($this->routes[$method] ?? [] as $route => $action) {
            $pattern = preg_replace(
                '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#',
                '([^/]+)',
                $route
            );

            $pattern = '#^' . $pattern . '$#';

            if (preg_match($pattern, $path, $matches)) {
                array_shift($matches);

                return $this->callAction(
                    $action,
                    $matches
                );
            }
        }

        http_response_code(404);

        PublicInterfaceTranslator::seed();

        echo htmlspecialchars(
            Translator::t(
                'public.404',
                '404 — Сторінку не знайдено'
            ),
            ENT_QUOTES,
            'UTF-8'
        );
    }


    private function guardAdminRoute($path, $method, $uri)
    {
        $isAdminPath = $path === '/admin'
            || strpos($path, '/admin/') === 0;

        if (!$isAdminPath || !class_exists('AdminAccess')) {
            return;
        }

        AdminAccess::ensureSchema();

        if (class_exists('AdminRolePermission')) {
            AdminRolePermission::applySavedOverrides();
        }

        if ($path === '/admin/setup') {
            return;
        }

        if (!AdminAccess::hasAdmins()) {
            header('Location: /Anabelka/admin/setup');
            exit;
        }

        if ($path === '/admin/login') {
            return;
        }

        $admin = AdminAccess::current();

        if (!$admin) {
            $returnTo = '/Anabelka' . $path;
            $query = parse_url((string) $uri, PHP_URL_QUERY);

            if (is_string($query) && $query !== '') {
                $returnTo .= '?' . $query;
            }

            $_SESSION['admin_return_to'] = $returnTo;
            header('Location: /Anabelka/admin/login');
            exit;
        }

        if ($path === '/admin/logout') {
            return;
        }

        if (
            $path === '/admin/profile'
            || strpos($path, '/admin/profile/') === 0
        ) {
            return;
        }

        if ($path === '/admin/audit') {
            $permission = 'audit.view';
        } elseif (
            $path === '/admin/administrators'
            || strpos($path, '/admin/administrators/') === 0
        ) {
            $isWrite = $method !== 'GET' && $method !== 'HEAD';
            $permission = $isWrite
                ? 'administrators.manage'
                : 'administrators.view';
        } else {
            $permission = AdminAccess::permissionForRequest(
                $method,
                $path
            );
        }

        if (AdminAccess::can($permission)) {
            return;
        }

        http_response_code(403);
        header('Content-Type: text/html; charset=UTF-8');

        echo '<!DOCTYPE html><html lang="uk"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Доступ заборонено — Анабелька</title></head>'
            . '<body style="font-family:Arial,sans-serif;padding:24px">'
            . '<h1>403 — Недостатньо прав</h1>'
            . '<p>Вашій ролі не дозволено виконувати цю дію.</p>'
            . '<p><a href="/Anabelka/admin">Повернутися до адмін-панелі</a></p>'
            . '</body></html>';
        exit;
    }


    private function callAction($action, $params = [])
    {
        [$controller, $method] = explode('@', $action);

        $controllerObject = new $controller();

        return $controllerObject->$method(...$params);
    }
}
