<?php

$router->get(
    '/account',
    'CustomerAccountController@index'
);

$router->post(
    '/account/profile',
    'CustomerAccountController@updateProfile'
);

$router->post(
    '/account/password',
    'CustomerAccountController@changePassword'
);

$router->post(
    '/logout',
    'AuthController@logout'
);
