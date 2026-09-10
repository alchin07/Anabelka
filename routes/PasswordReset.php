<?php

$router->get(
    '/forgot-password',
    'PasswordResetController@requestForm'
);

$router->post(
    '/forgot-password',
    'PasswordResetController@requestLink'
);

$router->get(
    '/reset-password',
    'PasswordResetController@resetForm'
);

$router->post(
    '/reset-password',
    'PasswordResetController@reset'
);
