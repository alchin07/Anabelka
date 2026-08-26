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
        $path = parse_url($uri, PHP_URL_PATH);

        // Убираем имя папки проекта из URL
        $projectFolder = '/' . basename(dirname(__DIR__, 2));

        if (strpos($path, $projectFolder) === 0) {
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

            // Превращаем {slug} в параметр URL
            $pattern = preg_replace(
                '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#',
                '([^/]+)',
                $route
            );

            $pattern = '#^' . $pattern . '$#';


            // Проверяем URL
            if (preg_match($pattern, $path, $matches)) {

    // Удаляем полное совпадение
    array_shift($matches);

    return $this->callAction(
        $action,
        $matches
    );
}
        }


        // Если маршрут не найден
        http_response_code(404);

        echo "404 — Страница не найдена";
    }


    private function callAction($action, $params = [])
    {
        [$controller, $method] = explode('@', $action);

        $controllerObject = new $controller();

        return $controllerObject->$method(...$params);
    }
}