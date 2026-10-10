<?php
// Execute the real route table, permission mapping and CSRF implementation.
require __DIR__ . '/../app/Core/Router.php';
require __DIR__ . '/../app/Core/Csrf.php';
require __DIR__ . '/../app/Models/AdminAccess.php';

function securityCheck($value, $message) {
    if (!$value) { throw new RuntimeException($message); }
}

$_SESSION = [];
$_POST = [];
$_SERVER['HTTP_ACCEPT'] = 'application/json';
$csrf = AdminAccess::csrfToken();
$router = new Router();
require __DIR__ . '/../routes/Web.php';

$suffix = $argv[1] ?? '';
if ($suffix !== '') {
    securityCheck(in_array($suffix, ['preview', 'confirm', 'cancel', 'legacy'], true), 'Unknown fixture');
    $_POST['_csrf'] = 'invalid-token';
    register_shutdown_function(function () {
        echo "\nHTTP_STATUS=" . http_response_code() . "\n";
    });
    $path = '/Anabelka/admin/products/image-process' . ($suffix === 'legacy' ? '' : '-' . $suffix);
    // Missing Database/controller classes make any accidental action/DB access fail.
    $router->dispatch($path, 'POST');
    throw new RuntimeException('Invalid CSRF request reached the action');
}

securityCheck(!Csrf::verify('admin'), 'Missing admin token accepted');
$_POST['_csrf'] = $csrf;
securityCheck(Csrf::verify('admin'), 'Valid admin form token rejected');
$_POST['_csrf'] = 'invalid-token';
securityCheck(!Csrf::verify('admin'), 'Wrong token accepted');
$_POST['_csrf'] = [];
securityCheck(!Csrf::verify('admin'), 'Array token accepted');
$_POST = [];
$_SERVER['HTTP_X_CSRF_TOKEN'] = $csrf;
securityCheck(Csrf::verify('admin'), 'Valid admin header token rejected');
unset($_SERVER['HTTP_X_CSRF_TOKEN']);

$match = new ReflectionMethod(Router::class, 'matchRoute');
$required = new ReflectionMethod(Router::class, 'requiresCsrf');
$family = new ReflectionMethod(Router::class, 'csrfFamily');
foreach (['preview', 'confirm', 'cancel', 'legacy'] as $suffix) {
    $path = '/admin/products/image-process' . ($suffix === 'legacy' ? '' : '-' . $suffix);
    $route = $match->invoke($router, $path, 'POST');
    securityCheck($route !== null, 'Missing processing POST route');
    securityCheck($required->invoke($router, 'POST', $route['options']), 'Mutation bypasses CSRF');
    securityCheck($family->invoke($router, $path, $route['options']) === 'admin', 'Wrong CSRF family');
    securityCheck(AdminAccess::permissionForRequest('POST', $path) === 'products.manage', 'Wrong mutation permission');
}
foreach (['GET', 'HEAD'] as $method) {
    securityCheck(AdminAccess::permissionForRequest($method, '/admin/products/image-process-preview-file') === 'products.manage', 'Private preview readable with view-only permission');
}
securityCheck(AdminAccess::permissionForRequest('GET', '/admin/products') === 'products.view', 'Existing product viewing changed');
echo "product image preview security runtime passed\n";
