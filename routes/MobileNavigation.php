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

// These registrations intentionally come after routes/Web.php in App.php.
// Router uses the latest exact-path registration, so the extended dashboard
// becomes live without duplicating the existing interface-edit/save routes.
$router->get(
    '/admin/translations',
    'AdminMobileNavigationTranslationController@index'
);

$router->get(
    '/admin/translations/missing',
    'AdminMobileNavigationTranslationController@missing'
);
