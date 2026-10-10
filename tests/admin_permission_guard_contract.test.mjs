import assert from 'node:assert/strict';
import fs from 'node:fs';

const access = fs.readFileSync(
    'app/Models/AdminAccess.php',
    'utf8'
);
const management = fs.readFileSync(
    'app/Models/AdminManagement.php',
    'utf8'
);
const router = fs.readFileSync(
    'app/Core/Router.php',
    'utf8'
);
const app = fs.readFileSync(
    'app/Core/App.php',
    'utf8'
);
const socialRoutes = fs.readFileSync(
    'routes/AdminSocialAuth.php',
    'utf8'
);

assert.match(
    management,
    /substr\(\$key, -7\) !== '\.manage'/
);
assert.match(
    management,
    /\$viewKey = substr\(\$key, 0, -7\) \. '\.view'/
);

assert.match(access, /social_auth\.view/);
assert.match(access, /social_auth\.manage/);
assert.match(
    access,
    /'\/admin\/social-auth'\s*=>\s*\['social_auth\.view', 'social_auth\.manage'\]/
);

assert.match(
    router,
    /\$path === '\/admin\/system'[\s\S]*?strpos\(\$path, '\/admin\/system\/'\)[\s\S]*?role_slug[\s\S]*?=== 'owner'/
);
assert.match(
    router,
    /private function forbidAdminAccess\s*\(/
);

assert.match(app, /Models\/SocialAuthProvider\.php/);
assert.match(app, /Controllers\/AdminSocialAuthController\.php/);
assert.match(app, /routes\/AdminSocialAuth\.php/);

assert.match(
    socialRoutes,
    /get\(\s*['"]\/admin\/social-auth['"]/
);
assert.match(
    socialRoutes,
    /post\(\s*['"]\/admin\/social-auth\/toggle['"]/
);
assert.match(
    socialRoutes,
    /post\(\s*['"]\/admin\/social-auth\/move['"]/
);

process.stdout.write(
    'admin permission guard contract passed\n'
);
