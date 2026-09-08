<?php

$router->get(
    '/admin/setup',
    'AdminAuthController@setupForm'
);

$router->post(
    '/admin/setup',
    'AdminAuthController@setup'
);

$router->get(
    '/admin/login',
    'AdminAuthController@loginForm'
);

$router->post(
    '/admin/login',
    'AdminAuthController@login'
);

$router->post(
    '/admin/logout',
    'AdminAuthController@logout'
);
