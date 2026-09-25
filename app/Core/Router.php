<?php

class Router
{
    private array $routes = [];


    public function get($path, $action)
    {
        $this->routes['GET'][$path] = $action;
    }


    public function post($path, $action, array $options = [])
    {
        $this->routes['POST'][$path] = [
            'action' => $action,
            'options' => $options
        ];
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

        $routeMatch = $this->matchRoute(
            $path,
            $method
        );

        if (
            $routeMatch !== null
            && $this->requiresCsrf(
                $method,
                $routeMatch['options']
            )
        ) {
            Csrf::enforce(
                $this->csrfFamily(
                    $path,
                    $routeMatch['options']
                )
            );
        }

        $this->guardAdminRoute($path, $method, $uri);

        if (class_exists('AdminActionAudit')) {
            AdminActionAudit::watch($path, $method);
        }

        if ($routeMatch !== null) {
            return $this->callAction(
                $routeMatch['action'],
                $routeMatch['params']
            );
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


    private function matchRoute($path, $method)
    {
        foreach ($this->routes[$method] ?? [] as $route => $routeDefinition) {
            $action = is_array($routeDefinition)
                ? ($routeDefinition['action'] ?? '')
                : $routeDefinition;
            $options = is_array($routeDefinition)
                ? ($routeDefinition['options'] ?? [])
                : [];

            $pattern = preg_replace(
                '#\\{([a-zA-Z_][a-zA-Z0-9_]*)\\}#',
                '([^/]+)',
                $route
            );

            $pattern = '#^' . $pattern . '$#';

            if (!preg_match($pattern, $path, $matches)) {
                continue;
            }

            array_shift($matches);

            return [
                'action' => $action,
                'options' => $options,
                'params' => $matches
            ];
        }

        return null;
    }


    private function requiresCsrf($method, array $options)
    {
        if (($options['csrf'] ?? null) === false) {
            return false;
        }

        return in_array(
            strtoupper((string) $method),
            ['POST', 'PUT', 'PATCH', 'DELETE'],
            true
        );
    }


    private function csrfFamily($path, array $options)
    {
        $explicitFamily = trim(
            (string) ($options['csrf_family'] ?? '')
        );

        if ($explicitFamily !== '') {
            return $explicitFamily;
        }

        if (
            $path === '/admin'
            || strpos($path, '/admin/') === 0
        ) {
            return 'admin';
        }

        return 'customer';
    }


    private function guardAdminRoute($path, $method, $uri)
    {
        $isAdminPath = $path === '/admin'
            || strpos($path, '/admin/') === 0;

        if (!$isAdminPath || !class_exists('AdminAccess')) {
            return;
        }

        AdminAccess::ensureSchema();

        if (class_exists('AdminRoleCleanup')) {
            AdminRoleCleanup::run();
        }

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

        if (
            $path === '/admin/system'
            || strpos($path, '/admin/system/') === 0
        ) {
            if (($admin['role_slug'] ?? '') === 'owner') {
                return;
            }

            $this->forbidAdminAccess();
        }

        if (
            $path === '/admin/audit'
            || strpos($path, '/admin/audit/') === 0
        ) {
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
            if (class_exists('AdminNotificationCenter')) {
                AdminNotificationCenter::markPathViewed(
                    $path,
                    $method
                );
            }
            return;
        }

        $this->forbidAdminAccess();
    }


    private function forbidAdminAccess()
    {
        http_response_code(403);

        if (
            class_exists('PublicErrorPage')
            && method_exists('PublicErrorPage', 'renderGeneric')
        ) {
            PublicErrorPage::renderGeneric(403);
            exit;
        }

        header('Content-Type: text/html; charset=UTF-8');

        echo '<!DOCTYPE html><html lang="uk"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Анабелька</title></head><body>'
            . '<h1>Щось пішло не так</h1>'
            . '<p>Спробуйте ще раз.</p>'
            . '<p><a href="/Anabelka/">На головну</a></p>'
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
