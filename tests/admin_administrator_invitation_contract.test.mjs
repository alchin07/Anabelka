import assert from 'node:assert/strict';
import fs from 'node:fs';

const routes = fs.readFileSync('routes/Web.php', 'utf8');
const securityRoutes = fs.readFileSync('routes/AdminSecurity.php', 'utf8');
const app = fs.readFileSync('app/Core/App.php', 'utf8');
const controller = fs.readFileSync(
    'app/Controllers/AdminAdministratorController.php',
    'utf8'
);
const access = fs.readFileSync(
    'app/Models/AdminAccess.php',
    'utf8'
);
const management = fs.readFileSync(
    'app/Models/AdminManagement.php',
    'utf8'
);
const invitation = fs.readFileSync(
    'app/Models/AdminInvitation.php',
    'utf8'
);
const view = fs.readFileSync(
    'views/admin/administrators/index.php',
    'utf8'
);
const inviteView = fs.readFileSync(
    'views/admin/auth/invite.php',
    'utf8'
);
const router = fs.readFileSync('app/Core/Router.php', 'utf8');

assert.match(
    securityRoutes,
    /get\(\s*['"]\/admin\/administrators['"][\s\S]*?AdminAdministratorController@index/
);
assert.match(
    securityRoutes,
    /post\(\s*['"]\/admin\/administrators\/invite['"][\s\S]*?AdminAdministratorController@createInvitation/
);
assert.match(
    securityRoutes,
    /get\(\s*['"]\/admin\/audit['"][\s\S]*?AdminAdministratorController@audit/
);
assert.match(
    securityRoutes,
    /get\(\s*['"]\/admin\/profile['"][\s\S]*?AdminAdministratorController@profile/
);
assert.match(
    app,
    /Models\/AdminInvitation\.php/
);

assert.match(
    routes,
    /post\(\s*['"]\/admin-invite['"][\s\S]*?csrf_family['"]\s*=>\s*['"]admin['"]/
);

assert.match(router, /administrators\.manage/);
assert.match(router, /administrators\.view/);
assert.match(access, /\['administrators\.view'/);
assert.match(access, /\['administrators\.manage'/);

assert.match(
    invitation,
    /CREATE TABLE IF NOT EXISTS admin_invitations/
);
assert.match(invitation, /token_hash CHAR\(64\) NULL/);
assert.match(invitation, /hash\('sha256', \$token\)/);
assert.match(
    invitation,
    /INSERT INTO admin_users[\s\S]*?is_active[\s\S]*?0\)/
);
assert.match(
    invitation,
    /status = 'accepted'[\s\S]*?token_hash = NULL/
);
assert.match(
    invitation,
    /SET[\s\S]*?password_hash = :password_hash,[\s\S]*?is_active = 1/
);
assert.match(invitation, /slug <> 'owner'/);

assert.match(controller, /public function createInvitation\(\)/);
assert.match(controller, /public function acceptInvitation\(\)/);
assert.match(controller, /admin\.invitation_created/);
assert.match(controller, /admin\.invitation_accepted/);

assert.match(management, /LEFT JOIN admin_invitations ai/);
assert.match(management, /invitation_status/);

assert.match(view, /Запросити адміністратора/);
assert.match(view, /\/admin\/administrators\/invite/);
assert.match(view, /navigator\.share/);
assert.match(view, /Копіювати текст/);

assert.match(inviteView, /action="\/Anabelka\/admin-invite"/);
assert.match(inviteView, /name="_csrf"/);
assert.match(inviteView, /name="password_confirm"/);

process.stdout.write(
    'admin administrator invitation contract passed\n'
);
