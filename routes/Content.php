<?php

$router->get('/news', 'NewsController@index');
$router->get('/news/{slug}', 'NewsController@show');

$router->get('/reviews', 'ProductReviewController@index');
$router->post('/product/{slug}/reviews', 'ProductReviewController@store');

$router->get('/gift-certificates', 'GiftCertificateController@index');

$router->get('/admin/news', 'AdminNewsController@index');
$router->post('/admin/news/create', 'AdminNewsController@create');
$router->post('/admin/news/update', 'AdminNewsController@update');
$router->post('/admin/news/publish', 'AdminNewsController@publish');
$router->post('/admin/news/delete', 'AdminNewsController@delete');

$router->get('/admin/reviews', 'AdminReviewController@index');
$router->post('/admin/reviews/approve', 'AdminReviewController@approve');
$router->post('/admin/reviews/reject', 'AdminReviewController@reject');
$router->post('/admin/reviews/delete', 'AdminReviewController@delete');

$router->get('/admin/home-page', 'AdminHomePageController@index');
$router->post('/admin/home-page/create', 'AdminHomePageController@create');
$router->post('/admin/home-page/delete', 'AdminHomePageController@delete');
$router->post('/admin/home-page/update', 'AdminHomePageController@update');
$router->post('/admin/home-page/toggle', 'AdminHomePageController@toggle');
$router->post('/admin/home-page/move', 'AdminHomePageController@move');
