<?php

$router->get('/news', 'NewsController@index');
$router->get('/news/{slug}', 'NewsController@show');

$router->get('/reviews', 'ProductReviewController@index');
$router->post('/product/{slug}/reviews', 'ProductReviewController@store');

$router->get('/admin/news', 'AdminNewsController@index');
$router->post('/admin/news/create', 'AdminNewsController@create');
$router->post('/admin/news/update', 'AdminNewsController@update');
$router->post('/admin/news/publish', 'AdminNewsController@publish');
$router->post('/admin/news/delete', 'AdminNewsController@delete');

$router->get('/admin/reviews', 'AdminReviewController@index');
$router->post('/admin/reviews/approve', 'AdminReviewController@approve');
$router->post('/admin/reviews/reject', 'AdminReviewController@reject');
$router->post('/admin/reviews/delete', 'AdminReviewController@delete');
