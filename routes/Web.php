<?php
$router->get('/cart', 'CartController@index');

$router->post('/cart/add', 'CartController@add');
// Увеличить количество товара
$router->post('/cart/increase', 'CartController@increase');

// Уменьшить количество товара
$router->post('/cart/decrease', 'CartController@decrease');

// Удалить позицию из корзины

$router->post('/cart/remove', 'CartController@remove');

$router->get('/', 'HomeController@index');

$router->get('/catalog', 'CatalogController@index');

$router->get('/catalog/{slug}', 'CatalogController@category');

$router->get('/product/{slug}', 'ProductController@show');
$router->get(
    '/register',
    'AuthController@registerForm'
);
$router->post(
    '/checkout',
    'OrderController@store'
);

$router->post(
    '/register',
    'AuthController@register'
);

$router->get(
    '/login',
    'AuthController@loginForm'
);

$router->post(
    '/login',
    'AuthController@login'
);

$router->get(
    '/logout',
    'AuthController@logout'
);

$router->get(
    '/checkout',
    'OrderController@checkout'
); 

$router->get(
    '/order/success',
    'OrderController@success'
);

$router->get(
    '/admin/delivery',
    'AdminDeliveryController@index'
); 

$router->post(
    '/admin/delivery/toggle-method',
    'AdminDeliveryController@toggleMethod'
);

$router->post(
    '/admin/delivery/toggle-service',
    'AdminDeliveryController@toggleService'
);

$router->post(
    '/admin/delivery/toggle-option',
    'AdminDeliveryController@toggleOption'
);

$router->post(
    '/admin/delivery/update',
    'AdminDeliveryController@update'
);

$router->post(
    '/admin/delivery/delete',
    'AdminDeliveryController@delete'
);

$router->post(
    '/admin/delivery/create-method',
    'AdminDeliveryController@createMethod'
);

$router->post(
    '/admin/delivery/create-service',
    'AdminDeliveryController@createService'
);

$router->post(
    '/admin/delivery/create-option',
    'AdminDeliveryController@createOption'
);

$router->post(
    '/admin/delivery/option-input',
    'AdminDeliveryOptionInputController@save'
);