<?php

$router->get('/news', 'NewsController@index');
$router->get('/news/{slug}', 'NewsController@show');

$router->get('/admin/news', 'AdminNewsController@index');
$router->post('/admin/news/create', 'AdminNewsController@create');
$router->post('/admin/news/update', 'AdminNewsController@update');
$router->post('/admin/news/publish', 'AdminNewsController@publish');
$router->post('/admin/news/delete', 'AdminNewsController@delete');
