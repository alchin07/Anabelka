<?php

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
