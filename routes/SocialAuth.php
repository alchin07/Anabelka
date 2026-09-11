<?php

require_once __DIR__ . '/../app/Models/SocialAuthProvider.php';
require_once __DIR__ . '/../app/Controllers/AdminSocialAuthController.php';

$router->get(
    '/auth/google',
    'SocialAuthController@google'
);

$router->get(
    '/auth/google/callback',
    'SocialAuthController@googleCallback'
);

$router->get(
    '/auth/facebook',
    'SocialAuthController@facebook'
);

$router->get(
    '/auth/facebook/callback',
    'SocialAuthController@facebookCallback'
);

$router->get(
    '/auth/apple',
    'SocialAuthController@apple'
);

$router->get(
    '/auth/apple/callback',
    'SocialAuthController@appleCallback'
);

$router->get(
    '/auth/social/complete',
    'SocialAuthController@completeForm'
);

$router->post(
    '/auth/social/complete',
    'SocialAuthController@complete'
);

require __DIR__ . '/AdminSocialAuth.php';
