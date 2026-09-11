<?php

$router->get(
    '/admin/social-auth',
    'AdminSocialAuthController@index'
);

$router->post(
    '/admin/social-auth/toggle',
    'AdminSocialAuthController@toggle'
);

$router->post(
    '/admin/social-auth/move',
    'AdminSocialAuthController@move'
);
