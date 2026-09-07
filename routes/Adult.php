<?php

$router->get(
    '/18-plus/{slug}',
    'AdultController@entry'
);

$router->post(
    '/18-plus/{slug}',
    'AdultController@confirm'
);
