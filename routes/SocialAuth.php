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
    '/auth/social/complete',
    'SocialAuthController@completeForm'
);

$router->post(
    '/auth/social/complete',
    'SocialAuthController@complete'
);

require __DIR__ . '/AdminSocialAuth.php';
