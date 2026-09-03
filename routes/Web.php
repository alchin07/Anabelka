<?php
$router->get('/cart', 'CartController@index');

$router->post('/cart/add', 'CartController@add');
// Увеличить количество товара
$router->post('/cart/increase', 'CartController@increase');

// Уменьшить количество товара
$router->post('/cart/decrease', 'CartController@decrease');

// Удалить позицию из корзины
$router->post('/cart/remove', 'CartController@remove');

$router->post(
    '/language/change',
    'LanguageController@change'
);

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
    '/quick-order',
    'QuickOrderController@form'
);

$router->post(
    '/quick-order',
    'QuickOrderController@store'
);

$router->get(
    '/quick-order/success',
    'QuickOrderController@success'
);

$router->get(
    '/delivery/option-input',
    'DeliveryOptionInputController@show'
);

$router->get(
    '/order/success',
    'OrderController@success'
);

$router->get(
    '/admin',
    'AdminDashboardController@index'
);

$router->get(
    '/admin/orders',
    'AdminQuickOrderController@index'
);

$router->get(
    '/admin/languages',
    'AdminLanguageController@index'
);

$router->post(
    '/admin/languages/create',
    'AdminLanguageController@create'
);

$router->post(
    '/admin/languages/update',
    'AdminLanguageController@update'
);

$router->post(
    '/admin/languages/toggle',
    'AdminLanguageController@toggle'
);

$router->post(
    '/admin/languages/default',
    'AdminLanguageController@setDefault'
);

$router->post(
    '/admin/languages/delete',
    'AdminLanguageController@delete'
);

$router->get(
    '/admin/translations',
    'AdminTranslationController@index'
);

$router->get(
    '/admin/translations/missing',
    'AdminTranslationController@missing'
);

$router->get(
    '/admin/translations/interface',
    'AdminTranslationController@interfaceEdit'
);

$router->post(
    '/admin/translations/interface/save',
    'AdminTranslationController@saveInterface'
);

$router->get(
    '/admin/ai-translation',
    'AdminAITranslationController@index'
);

$router->post(
    '/admin/ai-translation/default-provider',
    'AdminAITranslationController@setDefaultProvider'
);

$router->post(
    '/admin/ai-translation/test-provider',
    'AdminAITranslationController@testProvider'
);

$router->get(
    '/admin/ai-translation/providers',
    'AdminAITranslationController@providers'
);

$router->post(
    '/admin/ai-translation/provider',
    'AdminAITranslationController@setProvider'
);

$router->post(
    '/admin/ai-translation/suggest',
    'AdminAITranslationController@suggest'
);

$router->get(
    '/admin/categories',
    'AdminCategoryController@index'
);

$router->post(
    '/admin/categories/update',
    'AdminCategoryController@update'
);

$router->get(
    '/admin/products',
    'AdminProductController@index'
);

$router->post(
    '/admin/products/update',
    'AdminProductController@update'
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

$router->get(
    '/admin/delivery/option-input',
    'AdminDeliveryOptionInputController@show'
);

$router->post(
    '/admin/delivery/option-input',
    'AdminDeliveryOptionInputController@save'
);

$router->get(
    '/admin/delivery/translations',
    'AdminDeliveryTranslationController@show'
);

$router->post(
    '/admin/delivery/translations',
    'AdminDeliveryTranslationController@save'
);
