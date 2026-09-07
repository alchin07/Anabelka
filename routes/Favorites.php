<?php

$router->get('/favorites', 'FavoriteController@index');
$router->post('/favorites/toggle', 'FavoriteController@toggle');
