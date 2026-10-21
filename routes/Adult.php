<?php

$router->get(
    '/18-plus/{department_slug}/{category_slug}',
    'AdultController@entry'
);

$router->post(
    '/18-plus/{department_slug}/{category_slug}',
    'AdultController@confirm'
);

$router->get(
    '/18-plus/{slug}',
    'AdultController@legacyEntry'
);

$router->post(
    '/18-plus/{slug}',
    'AdultController@legacyConfirm'
);
