<?php
$router->get('/cart', 'CartController@index');

$router->post('/cart/add', 'CartController@add');
$router->post('/cart/increase', 'CartController@increase');
$router->post('/cart/decrease', 'CartController@decrease');
$router->post('/cart/remove', 'CartController@remove');

$router->post(
    '/language/change',
    'LanguageController@change'
);

$router->get('/', 'HomeController@index');

$router->get('/catalog', 'CatalogController@index');

$router->get('/search', 'SearchController@index');
$router->get('/search/suggest', 'SearchController@suggest');

$router->get(
    '/catalog/{department_slug}/{category_slug}',
    'CatalogController@category'
);
$router->get('/catalog/{slug}', 'CatalogController@legacyCategory');

$router->get('/product/{slug}/variants', 'ProductController@variants');
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
    '/invite',
    'InvitationController@form'
);

$router->post(
    '/invite',
    'InvitationController@accept'
);

$router->get(
    '/admin-invite',
    'AdminAdministratorController@inviteForm'
);

$router->post(
    '/admin-invite',
    'AdminAdministratorController@acceptInvitation',
    ['csrf' => true, 'csrf_family' => 'admin']
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
    '/orders',
    'CustomerOrderController@index'
);

$router->get(
    '/admin',
    'AdminDashboardController@index'
);

$router->get(
    '/admin/administrators',
    'AdminAdministratorController@index'
);

$router->post(
    '/admin/administrators/create',
    'AdminAdministratorController@create'
);

$router->post(
    '/admin/administrators/invite',
    'AdminAdministratorController@createInvitation'
);

$router->post(
    '/admin/administrators/role',
    'AdminAdministratorController@changeRole'
);

$router->post(
    '/admin/administrators/password',
    'AdminAdministratorController@resetPassword'
);

$router->post(
    '/admin/administrators/toggle',
    'AdminAdministratorController@toggle'
);

$router->post(
    '/admin/administrators/roles/create',
    'AdminAdministratorController@createRole'
);

$router->post(
    '/admin/administrators/roles/permissions',
    'AdminAdministratorController@updateRolePermissions'
);

$router->get(
    '/admin/audit',
    'AdminAdministratorController@audit'
);

$router->get(
    '/admin/profile',
    'AdminAdministratorController@profile'
);

$router->post(
    '/admin/profile',
    'AdminAdministratorController@updateProfile'
);

$router->post(
    '/admin/profile/password',
    'AdminAdministratorController@changeOwnPassword'
);

$router->post(
    '/admin/profile/notification-badge',
    'AdminAdministratorController@updateNotificationBadge'
);

$router->get(
    '/admin/dashboard-builder',
    'AdminDashboardBuilderController@index'
);

$router->post(
    '/admin/dashboard-builder/blocks/create',
    'AdminDashboardBuilderController@createBlock'
);

$router->post(
    '/admin/dashboard-builder/blocks/update',
    'AdminDashboardBuilderController@updateBlock'
);

$router->post(
    '/admin/dashboard-builder/blocks/toggle',
    'AdminDashboardBuilderController@toggleBlock'
);

$router->post(
    '/admin/dashboard-builder/blocks/delete',
    'AdminDashboardBuilderController@deleteBlock'
);

$router->post(
    '/admin/dashboard-builder/links/create',
    'AdminDashboardBuilderController@createLink'
);

$router->post(
    '/admin/dashboard-builder/links/update',
    'AdminDashboardBuilderController@updateLink'
);

$router->post(
    '/admin/dashboard-builder/links/toggle',
    'AdminDashboardBuilderController@toggleLink'
);

$router->post(
    '/admin/dashboard-builder/links/delete',
    'AdminDashboardBuilderController@deleteLink'
);

$router->post(
    '/admin/dashboard-builder/reorder-blocks',
    'AdminDashboardBuilderController@reorderBlocks'
);

$router->post(
    '/admin/dashboard-builder/reorder-links',
    'AdminDashboardBuilderController@reorderLinks'
);

$router->get(
    '/admin/orders',
    'AdminOrderController@index'
);

$router->post(
    '/admin/orders/status',
    'AdminOrderController@updateStatus'
);

$router->get(
    '/admin/search',
    'AdminSearchController@index'
);

$router->get(
    '/admin/users',
    'AdminUserController@index'
);

$router->post(
    '/admin/users/invite/create',
    'AdminUserController@createInvitation'
);

$router->post(
    '/admin/users/invite/sent',
    'AdminUserController@markInvitationSent'
);

$router->post(
    '/admin/users/rank',
    'AdminUserController@updateRank'
);

$router->post(
    '/admin/users/rank-request/approve',
    'AdminUserController@approveRankRequest'
);

$router->post(
    '/admin/users/rank-request/reject',
    'AdminUserController@rejectRankRequest'
);

$router->post(
    '/admin/users/delete',
    'AdminUserController@deleteAccount'
);

$router->post(
    '/admin/users/deactivate',
    'AdminUserController@deactivateAccount'
);

$router->get(
    '/admin/ranks',
    'AdminUserRankController@index'
);

$router->post(
    '/admin/ranks/create',
    'AdminUserRankController@create'
);

$router->post(
    '/admin/ranks/update',
    'AdminUserRankController@update'
);

$router->post(
    '/admin/ranks/move',
    'AdminUserRankController@move'
);

$router->post(
    '/admin/ranks/toggle',
    'AdminUserRankController@toggle'
);

$router->post(
    '/admin/ranks/default',
    'AdminUserRankController@setDefault'
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
    'AdminMobileNavigationTranslationController@index'
);

$router->get(
    '/admin/translations/missing',
    'AdminMobileNavigationTranslationController@missing'
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
    '/admin/categories/create',
    'AdminCategoryController@create',
    ['csrf' => true, 'csrf_family' => 'admin']
);

$router->post(
    '/admin/categories/update',
    'AdminCategoryController@update',
    ['csrf' => true, 'csrf_family' => 'admin']
);

$router->post(
    '/admin/categories/thumbnail',
    'AdminCategoryController@thumbnail',
    ['csrf' => true, 'csrf_family' => 'admin']
);

$router->post(
    '/admin/categories/move',
    'AdminCategoryController@move',
    ['csrf' => true, 'csrf_family' => 'admin']
);

$router->post(
    '/admin/categories/toggle',
    'AdminCategoryController@toggle',
    ['csrf' => true, 'csrf_family' => 'admin']
);

$router->post(
    '/admin/categories/delete',
    'AdminCategoryController@delete',
    ['csrf' => true, 'csrf_family' => 'admin']
);

$router->get(
    '/admin/products',
    'AdminProductController@index'
);

$router->post(
    '/admin/products/update',
    'AdminProductController@update'
);

$router->post(
    '/admin/products/save',
    'AdminProductController@save'
);

$router->get(
    '/admin/products/variant-stock',
    'AdminProductVariantController@index'
);

$router->post(
    '/admin/products/variant-stock/save',
    'AdminProductVariantController@save'
);

$router->post(
    '/admin/products/toggle',
    'AdminProductController@toggle'
);

$router->post(
    '/admin/products/duplicate',
    'AdminProductController@duplicate'
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
