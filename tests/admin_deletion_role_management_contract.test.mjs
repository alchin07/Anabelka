import assert from 'node:assert/strict';
import fs from 'node:fs';

const routes = fs.readFileSync(
    'routes/AdminSecurity.php',
    'utf8'
);
const controller = fs.readFileSync(
    'app/Controllers/AdminAdministratorController.php',
    'utf8'
);
const management = fs.readFileSync(
    'app/Models/AdminManagement.php',
    'utf8'
);
const view = fs.readFileSync(
    'views/admin/administrators/index.php',
    'utf8'
);
const audit = fs.readFileSync(
    'views/admin/administrators/audit.php',
    'utf8'
);

assert.doesNotMatch(
    routes,
    /['"]\/admin\/administrators\/create['"]/
);
assert.match(
    routes,
    /post\(\s*['"]\/admin\/administrators\/delete['"][\s\S]*?AdminAdministratorController@deleteAdministrator/
);
assert.match(
    routes,
    /post\(\s*['"]\/admin\/administrators\/roles\/rename['"][\s\S]*?AdminAdministratorController@renameRole/
);
assert.match(
    routes,
    /post\(\s*['"]\/admin\/administrators\/roles\/delete['"][\s\S]*?AdminAdministratorController@deleteRole/
);

assert.match(
    controller,
    /public function deleteAdministrator\(\)/
);
assert.match(controller, /admin\.deleted/);
assert.match(
    controller,
    /public function renameRole\(\)/
);
assert.match(controller, /admin\.role_renamed/);
assert.match(
    controller,
    /public function deleteRole\(\)/
);
assert.match(controller, /admin\.role_deleted/);

assert.match(
    management,
    /public static function deleteAdministrator\s*\(/
);
assert.match(
    management,
    /\$adminId === AdminAccess::currentId\(\)/
);
assert.match(
    management,
    /role_slug[\s\S]*?=== 'owner'[\s\S]*?Розробника/
);
assert.match(
    management,
    /UPDATE customer_rank_requests[\s\S]*?SET admin_user_id = NULL/
);
assert.match(
    management,
    /DELETE FROM admin_notification_state/
);
assert.match(
    management,
    /DELETE FROM admin_notification_preferences/
);
assert.match(
    management,
    /DELETE FROM admin_system_error_notification_state/
);
assert.match(
    management,
    /UPDATE admin_system_error_notes[\s\S]*?updated_by_admin_id = NULL/
);
assert.match(
    management,
    /UPDATE admin_system_error_status[\s\S]*?updated_by_admin_id = NULL/
);
assert.match(
    management,
    /DELETE FROM admin_users[\s\S]*?WHERE id = :id/
);

assert.match(
    management,
    /public static function renameCustomRole\s*\(/
);
assert.match(
    management,
    /Назву системної ролі змінювати не можна/
);
assert.match(
    management,
    /public static function deleteCustomRole\s*\(/
);
assert.match(
    management,
    /Системну роль видалити не можна/
);
assert.match(
    management,
    /SELECT COUNT\(\*\)[\s\S]*?FROM admin_users[\s\S]*?WHERE role_id = :role_id[\s\S]*?FOR UPDATE/
);
assert.match(
    management,
    /DELETE FROM admin_roles[\s\S]*?NOT EXISTS \([\s\S]*?FROM admin_users[\s\S]*?role_id = :assigned_role_id/
);
assert.match(
    management,
    /\$assignedCount > 0[\s\S]*?Спочатку призначте адміністраторам іншу роль/
);
assert.match(
    management,
    /SELECT COUNT\(\*\)[\s\S]*?WHERE role_id = :role_id[\s\S]*?Не вдалося видалити роль/
);
assert.match(
    management,
    /createCustomRole[\s\S]*?WHERE name = :name[\s\S]*?Роль із такою назвою вже існує/
);

assert.match(
    view,
    /\/admin\/administrators\/delete/
);
assert.match(
    view,
    /Видалити адміністратора/
);
assert.match(
    view,
    /\/admin\/administrators\/roles\/rename/
);
assert.match(
    view,
    /\/admin\/administrators\/roles\/delete/
);
assert.match(
    view,
    /\$role\['admin_count'\][\s\S]*?> 0 \? 'disabled'/
);

assert.match(audit, /admin\.deleted/);
assert.match(audit, /admin\.role_renamed/);
assert.match(audit, /admin\.role_deleted/);

process.stdout.write(
    'admin deletion and custom role management contract passed\n'
);
