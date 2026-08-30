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

        /*
         * Некоторые браузеры и режимы предварительной загрузки
         * могут проверять страницу методом HEAD.
         * Для маршрутизации он должен вести себя как обычный GET.
         */
        if ($method === 'HEAD') {
            $method = 'GET';
        }

        $path = parse_url((string) $uri, PHP_URL_PATH);

        if ($path === false || $path === null) {
            $path = '/';
        }

        /*
         * Нормализуем URL перед поиском маршрута:
         * - декодируем %XX;
         * - убираем случайные двойные слеши.
         */
        $path = rawurldecode((string) $path);
        $path = preg_replace('#/+#', '/', $path);

        // Убираем имя папки проекта из URL.
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


        /*
         * Перебираем маршруты.
         *
         * Маршрут вида:
         * /catalog/{slug}
         *
         * сможет принять:
         * /catalog/bras
         */
        foreach ($this->routes[$method] ?? [] as $route => $action) {

            // Превращаем {slug} в параметр URL.
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


    private function callAction($action, $params = [])
    {
        [$controller, $method] = explode('@', $action);

        $controllerObject = new $controller();

        return $controllerObject->$method(...$params);
    }
}
