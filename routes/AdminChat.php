<?php
// Завантажуємо модуль лише в межах звичайного циклу App/Router.
require_once __DIR__ . '/../app/Models/AdminChat.php';
require_once __DIR__ . '/../app/Controllers/AdminChatController.php';

// Центральний Router виконує перевірку сесії, дозволу chat.use та CSRF.
$router->get('/admin/chat', 'AdminChatController@index');
$router->get('/admin/chat/api/list', 'AdminChatController@list');
$router->get('/admin/chat/api/messages', 'AdminChatController@messages');
$router->get('/admin/chat/api/search', 'AdminChatController@search');
$router->get('/admin/chat/file', 'AdminChatController@file');
$router->post('/admin/chat/api/direct', 'AdminChatController@direct');
$router->post('/admin/chat/api/group', 'AdminChatController@group');
$router->post('/admin/chat/api/group/manage', 'AdminChatController@manageGroup');
$router->post('/admin/chat/api/send', 'AdminChatController@send');
$router->post('/admin/chat/api/edit', 'AdminChatController@edit');
$router->post('/admin/chat/api/delete', 'AdminChatController@delete');
$router->post('/admin/chat/api/read', 'AdminChatController@read');
