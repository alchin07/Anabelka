import fs from 'node:fs';

const router = fs.readFileSync('app/Core/Router.php', 'utf8');
const app = fs.readFileSync('app/Core/App.php', 'utf8');
const csrf = fs.readFileSync('app/Core/Csrf.php', 'utf8');
const routes = fs.readFileSync('routes/Web.php', 'utf8');
const categoryController = fs.readFileSync(
  'app/Controllers/AdminCategoryController.php',
  'utf8'
);
const publicHeader = fs.readFileSync(
  'views/partials/header.php',
  'utf8'
);
const adminHeader = fs.readFileSync(
  'views/admin/partials/header.php',
  'utf8'
);
const client = fs.readFileSync(
  'js/anabelka-csrf.js',
  'utf8'
);

const checks = [
  [app.includes("require_once __DIR__ . '/Csrf.php';"), 'App loads Csrf'],
  [
    router.indexOf('Csrf::enforce') !== -1
      && router.indexOf('return $this->callAction', router.indexOf('Csrf::enforce'))
        > router.indexOf('Csrf::enforce'),
    'Router enforces CSRF before action'
  ],
  [
    router.includes("['POST', 'PUT', 'PATCH', 'DELETE']")
      && router.includes("($options['csrf'] ?? null) === false"),
    'unsafe methods are protected by default with explicit opt-out'
  ],
  [
    router.includes("return 'admin';")
      && router.includes("return 'customer';"),
    'Router selects admin/customer CSRF family'
  ],
  [csrf.includes('HTTP_X_CSRF_TOKEN'), 'X-CSRF-Token supported'],
  [csrf.includes("$_POST['_csrf']"), '_csrf supported'],
  [csrf.includes("$_POST['csrf_token']"), 'csrf_token supported'],
  [csrf.includes('http_response_code(403)'), 'invalid CSRF returns 403'],
  [csrf.includes('AdminAccess::verifyCsrf'), 'existing admin verifier reused'],
  [csrf.includes('CustomerAccount::verifyCsrf'), 'existing customer verifier reused'],
  [
    publicHeader.includes("Csrf::token('customer')")
      && publicHeader.includes('/js/anabelka-csrf.js?v=1'),
    'public header exposes customer CSRF token to shared client guard'
  ],
  [
    adminHeader.includes('$adminCsrfToken')
      && adminHeader.includes('/js/anabelka-csrf.js?v=1'),
    'admin header exposes admin CSRF token to shared client guard'
  ],
  [
    client.includes("input.name = '_csrf'")
      && client.includes('X-CSRF-Token')
      && client.includes('window.fetch')
      && client.includes('XMLHttpRequest.prototype.open'),
    'shared client guard covers forms, fetch and XHR'
  ],
  [
    !categoryController.includes('$this->verifyCsrf();'),
    'category actions delegate CSRF to Router'
  ],
  [
    !categoryController.includes('private function verifyCsrf'),
    'legacy category CSRF helper removed'
  ]
];

for (const action of ['create', 'update', 'thumbnail', 'move', 'toggle', 'delete']) {
  checks.push([
    routes.includes(`'AdminCategoryController@${action}',\n    ['csrf' => true, 'csrf_family' => 'admin']`),
    `category ${action} remains explicitly protected`
  ]);
}

for (const [ok, name] of checks) {
  if (!ok) throw new Error(name);
}

console.log(`CSRF contract: ${checks.length} checks passed`);
