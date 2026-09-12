<?php

require_once __DIR__ . '/../app/Models/SystemErrorLog.php';
require_once __DIR__ . '/../app/Models/SystemErrorNotification.php';
require_once __DIR__ . '/../app/Controllers/AdminSystemErrorController.php';
require_once __DIR__ . '/../app/Controllers/AdminSystemErrorNotificationController.php';
require_once __DIR__ . '/../app/Controllers/AdminErrorTestController.php';

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
    '/admin/administrators',
    'AdminAdministratorController@index'
);

$router->post(
    '/admin/administrators/create',
    'AdminAdministratorController@create'
);

$router->post(
    '/admin/administrators/role',
    'AdminAdministratorController@changeRole'
);

$router->post(
    '/admin/administrators/toggle',
    'AdminAdministratorController@toggle'
);

$router->post(
    '/admin/administrators/password',
    'AdminAdministratorController@resetPassword'
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
    '/admin/system/errors',
    'AdminSystemErrorController@index'
);

$router->get(
    '/admin/system/error-notifications',
    'AdminSystemErrorNotificationController@status'
);

$router->get(
    '/admin/system/error-test',
    'AdminErrorTestController@index'
);

$router->post(
    '/admin/system/error-test',
    'AdminErrorTestController@trigger'
);
