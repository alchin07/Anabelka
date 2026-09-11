<?php

$router->get(
    '/account',
    'CustomerAccountController@index'
);

$router->get(
    '/account/checkout-profile',
    'CustomerAccountController@checkoutProfile'
);

$router->get(
    '/account/social-connections/status',
    'CustomerAccountController@socialConnectionsStatus'
);

$router->get(
    '/account/social-connect',
    'CustomerAccountController@connectSocial'
);

$router->post(
    '/account/social-disconnect',
    'CustomerAccountController@disconnectSocial'
);

$router->get(
    '/account/email-verification/status',
    'EmailVerificationController@status'
);

$router->post(
    '/account/email-verification/resend',
    'EmailVerificationController@resend'
);

$router->get(
    '/verify-email',
    'EmailVerificationController@verify'
);

$router->post(
    '/account/profile',
    'CustomerAccountController@updateProfile'
);

$router->post(
    '/account/rank-request',
    'CustomerAccountController@requestRankUpgrade'
);

$router->post(
    '/account/adult-preferences',
    'CustomerAccountController@updateAdultPreferences'
);

$router->post(
    '/account/password',
    'CustomerAccountController@changePassword'
);

$router->post(
    '/account/address/create',
    'CustomerAccountController@createAddress'
);

$router->post(
    '/account/address/update',
    'CustomerAccountController@updateAddress'
);

$router->post(
    '/account/address/default',
    'CustomerAccountController@setDefaultAddress'
);

$router->post(
    '/account/address/delete',
    'CustomerAccountController@deleteAddress'
);

$router->post(
    '/logout',
    'AuthController@logout'
);
