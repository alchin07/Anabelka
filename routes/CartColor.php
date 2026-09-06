<?php

$router->get(
    '/cart/color-options',
    'CartColorController@options'
);

$router->post(
    '/cart/set-color',
    'CartColorController@assign'
);
