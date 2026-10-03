import assert from 'node:assert/strict';
import fs from 'node:fs';

const router = fs.readFileSync(
    'app/Core/Router.php',
    'utf8'
);
const auth = fs.readFileSync(
    'app/Controllers/AdminAuthController.php',
    'utf8'
);

assert.match(
    router,
    /guardAdminRoute\([\s\S]*?\$routeMatch !== null/
);
assert.match(
    router,
    /strtoupper\(\(string\) \$method\) === 'GET'[\s\S]*?&& \$routeExists/
);
assert.match(
    router,
    /admin_return_to_validated/
);
assert.doesNotMatch(
    router,
    /\$method\) === 'POST'[\s\S]*?admin_return_to/
);

assert.match(
    auth,
    /if \(!empty\(\$_SESSION\['admin_return_to_validated'\]\)\)/
);
assert.match(
    auth,
    /safeAdminReturnTo\([\s\S]*?\$admin/
);
assert.match(
    auth,
    /unset\([\s\S]*?admin_return_to[\s\S]*?admin_return_to_validated/
);
assert.match(
    auth,
    /private function safeAdminReturnTo\(\$returnTo, array \$admin\)/
);
assert.match(
    auth,
    /\/admin\/work-time[\s\S]*?\['owner', 'store_owner'\]/
);
assert.match(
    auth,
    /\$path === '\/admin\/work-time'[\s\S]*?\? \$returnTo[\s\S]*?: \$fallback/
);
assert.match(
    auth,
    /\/admin\/system[\s\S]*?role_slug[\s\S]*?=== 'owner'/
);

const logoutStart = auth.indexOf('public function logout()');
const logoutEnd = auth.indexOf('private function safeAdminReturnTo', logoutStart);
assert.ok(logoutStart >= 0 && logoutEnd > logoutStart);
const logoutBlock = auth.slice(logoutStart, logoutEnd);

assert.match(logoutBlock, /admin_return_to/);
assert.match(logoutBlock, /admin_return_to_validated/);

process.stdout.write(
    'admin safe post-login return-path contract passed\n'
);
