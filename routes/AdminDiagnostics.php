<?php

$router->get(
    '/admin/system/error-test',
    'AdminErrorTestController@index'
);

$router->post(
    '/admin/system/error-test',
    'AdminErrorTestController@trigger'
);
