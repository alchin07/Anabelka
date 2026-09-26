import assert from 'node:assert/strict';
import fs from 'node:fs';

const controller = fs.readFileSync(
    'app/Controllers/AdminOrderController.php',
    'utf8'
);
const view = fs.readFileSync(
    'views/admin/orders/index.php',
    'utf8'
);
const router = fs.readFileSync(
    'app/Core/Router.php',
    'utf8'
);
const access = fs.readFileSync(
    'app/Models/AdminAccess.php',
    'utf8'
);

assert.match(
    controller,
    /'csrfToken' => AdminAccess::csrfToken\(\)/
);
assert.doesNotMatch(
    controller,
    /admin_order_csrf/
);
assert.doesNotMatch(
    controller,
    /hash_equals\(\$this->csrfToken\(\)/
);
assert.doesNotMatch(
    controller,
    /private function csrfToken\(/
);

assert.match(
    view,
    /name="_csrf" value="<\?= \$escape\(\$csrfToken\) \?>"/
);
assert.doesNotMatch(
    view,
    /name="csrf_token"/
);

assert.match(
    router,
    /\$isWrite\s*=\s*\$method !== 'GET' && \$method !== 'HEAD'/
);
assert.match(
    access,
    /'\/admin\/orders'\s*=>\s*\['orders\.view', 'orders\.manage'\]/
);

process.stdout.write(
    'admin order centralized CSRF contract passed\n'
);
