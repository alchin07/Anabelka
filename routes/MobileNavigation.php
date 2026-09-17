<?php

$router->get(
    '/admin/mobile-navigation',
    'AdminMobileNavigationController@index'
);

$router->post(
    '/admin/mobile-navigation/create',
    'AdminMobileNavigationController@create'
);

$router->post(
    '/admin/mobile-navigation/update',
    'AdminMobileNavigationController@update'
);

$router->post(
    '/admin/mobile-navigation/toggle',
    'AdminMobileNavigationController@toggle'
);

$router->post(
    '/admin/mobile-navigation/move',
    'AdminMobileNavigationController@move'
);

$router->post(
    '/admin/mobile-navigation/delete',
    'AdminMobileNavigationController@delete'
);
