<?php

$router->get('/favorites', 'FavoriteController@index');
$router->get('/favorites/state', 'FavoriteController@state');
$router->post('/favorites/toggle', 'FavoriteController@toggle');
