<?php

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
