<?php

class App
{
    public function run()
    {  // Запускаем сессию для корзины
        if (session_status() === PHP_SESSION_NONE) {
          session_start();
        }
        require_once __DIR__ . '/Controller.php';
        require_once __DIR__ . '/Router.php';
        require_once __DIR__ . '/Database.php';
        require_once __DIR__ . '/../Models/Category.php';
        require_once __DIR__ . '/../Models/Product.php';
        require_once __DIR__ . '/../Models/Cart.php';
        require_once __DIR__ . '/../Models/User.php';
        // Подключаем контроллер главной страницы
        require_once __DIR__ . '/../Controllers/HomeController.php';

        // Подключаем контроллер каталога
        require_once __DIR__ . '/../Controllers/CatalogController.php';
        require_once __DIR__ . '/../Controllers/ProductController.php';
        require_once __DIR__ . '/../Controllers/CartController.php';
        require_once __DIR__ . '/../Controllers/AuthController.php';
        require_once __DIR__ . '/../Controllers/OrderController.php';
        require_once __DIR__ . '/../Models/Order.php';
        require_once __DIR__ . '/../Models/Delivery.php';
        require_once __DIR__
    . '/../Controllers/AdminDeliveryController.php'; 

        $router = new Router();

        require __DIR__ . '/../../routes/web.php';

        $router->dispatch(
            $_SERVER['REQUEST_URI'],
            $_SERVER['REQUEST_METHOD']
        );
    }
}