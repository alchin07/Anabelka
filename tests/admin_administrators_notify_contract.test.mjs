import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import {spawnSync} from 'node:child_process';

const read = p => fs.readFileSync(p, 'utf8');
const controller = read('app/Controllers/AdminAdministratorController.php');
const view = read('views/admin/administrators/index.php');
const css = read('css/admin-administrators.css');
const bootstrap = read('app/Core/NotificationBootstrap.php');
const entrypoint = read('index.php');

test('successful administrator and role operations use shared one-time notifications', () => {
    assert.match(controller, /private function redirect\(\$key, \$message\)/);
    assert.match(controller, /if \(\$key === 'message'\)/);
    assert.match(controller, /AnabelkaFlash::success\(\$text\)/);
    assert.match(controller, /AnabelkaFlash::info\(\$text\)/);
    assert.match(controller, /header\('Location: \/Anabelka\/admin\/administrators'\)/);
    assert.match(controller, /'Адміністратор уже має цю роль\.'/);
    for (const message of [
        'Адміністратора створено.',
        'Запрошення адміністратора створено.',
        'Нове одноразове запрошення створено.',
        'Запрошення адміністратора відкликано.',
        'Роль адміністратора змінено.',
        'Доступ адміністратора увімкнено.',
        'Доступ адміністратора вимкнено.',
        'Пароль адміністратора змінено.',
        'Власну роль створено.',
        'Назву ролі змінено.',
        'Власну роль видалено.',
        'Права ролі збережено.'
    ]) {
        assert.ok(controller.includes(message), message);
    }
});

test('one-time invitation results never enter the common flash or query string', () => {
    assert.equal(
        (controller.match(/\$_SESSION\['admin_administrator_invite_flash'\] = \$result/g) || []).length,
        2
    );
    assert.match(view, /if \(\$inviteFlash && \$inviteMessage !== ''\)/);
    assert.match(view, /rawurlencode\(\(string\) \$inviteFlash\['invite_token'\]\)/);
    assert.match(view, /admin-invite-result/);
    assert.match(view, /id="admin-invite-message"/);
    assert.match(view, /data-invite-message=/);
    assert.doesNotMatch(controller,
        /AnabelkaFlash::(?:success|info)\(\s*\$result/);
    assert.match(controller, /\$this->redirect\(\s*'message'/);
});

test('validation, CSRF and permission errors remain persistent and accessible', () => {
    assert.match(controller, /AdminAccess::verifyCsrf/);
    assert.match(controller, /private function redirectProfile\(\$key, \$message\)/);
    assert.match(controller,
        /http_build_query\(\[\$key => \(string\) \$message\]\)/);
    assert.match(controller,
        /'error' => trim\(\(string\) \(\$_GET\['error'\] \?\? ''\)\)/);
    assert.match(view, /admin-security-message is-error" role="alert"/);
    assert.match(view, /admin-security-message is-success" role="status"/);
    assert.match(view, /data-invite-message=/);
    assert.match(bootstrap, /AnabelkaFlash::consume\(\)/);
    assert.match(entrypoint, /NotificationBootstrap::register\(\)/);
});

test('branded notices preserve the invitation UI, confirms and role protections', () => {
    assert.match(view, /admin-administrators\.css\?v=7/);
    assert.match(css, /\.admin-security-message\.is-error\s*\{[^}]*--notify-info-bg,\s*#f4eaff/s);
    assert.match(css, /\.admin-security-message\.is-success\s*\{[^}]*--notify-success-bg,\s*#8A2BE2/s);
    const legacyStyle = css.match(/\.admin-security-message\.is-error\s*\{([^}]*)\}/s)?.[1] || '';
    assert.doesNotMatch(legacyStyle, /#fff0f2|#e7b9c1|#8e3748/);
    assert.match(view, /data-anabelka-confirm="Змінити роль цього адміністратора\?"/);
    assert.match(view, /name="_csrf"/);
    assert.match(view, /\$canManage/);
    assert.match(controller, /AdminAccess::audit\(/);
});

test('profile, audit and invitation-acceptance routes remain outside this migration', () => {
    assert.match(controller, /public function profile\(\)/);
    assert.match(controller, /public function acceptInvitation\(\)/);
    assert.match(controller, /public function audit\(\)/);
    assert.match(controller, /private function redirectProfile\(\$key, \$message\)/);
    assert.match(controller, /private function renderInvitation\(\$token, \$error = ''\)/);
    assert.match(controller, /header\('Location: \/Anabelka\/admin'\)/);
});

test('PHP verifies redirection, no-op severity, private invitation data and persistent errors', () => {
    const result = spawnSync(process.env.PHP_BIN || 'php', [
        '-n', 'tests/admin_administrators_notify_runtime.php'
    ], {encoding: 'utf8'});
    assert.equal(result.error, undefined);
    assert.equal(result.status, 0, result.stderr || result.stdout);
    assert.match(result.stdout, /admin administrator notifications runtime passed/);
});
