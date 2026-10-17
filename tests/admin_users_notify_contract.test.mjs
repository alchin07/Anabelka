import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import {spawnSync} from 'node:child_process';

const read = name => fs.readFileSync(name, 'utf8');
const controller = read('app/Controllers/AdminUserController.php');
const view = read('views/admin/users/index.php');
const css = read('css/admin-users.css');
const bootstrap = read('app/Core/NotificationBootstrap.php');
const entrypoint = read('index.php');

test('rank requests, rank changes and account actions use one shared success flash', () => {
    assert.match(controller, /private function redirectBack\(\$returnQuery, \$key, \$message\)/);
    assert.match(controller, /if \(\$key === 'message'\)/);
    assert.match(controller, /AnabelkaFlash::success\(\$text\)/);
    assert.match(controller, /AnabelkaFlash::info\(\$text\)/);
    assert.match(controller, /unset\(\$query\['message'\], \$query\['error'\]\)/);
    assert.match(controller, /\$encoded = http_build_query\(\$query\)/);
    assert.match(controller, /header\('Location: ' \. \$url\)/);
    for (const message of [
        'Запит схвалено. Ранг користувача підвищено.',
        'Запит на підвищення рангу відхилено.',
        'Ранг користувача змінено.',
        'Запрошення позначено як надіслане.',
        'Акаунт користувача видалено.',
        'Акаунт користувача деактивовано.'
    ]) {
        assert.ok(controller.includes(message), message);
    }
});

test('repeat actions are informational rather than claiming a second mutation', () => {
    assert.match(controller, /'Користувач уже має цей ранг\.'/);
    assert.match(controller, /'Акаунт уже деактивований\.'/);
    assert.match(controller, /in_array\(\$text, \[/);
});

test('invitation token stays in the session and visible invitation card, never in toast', () => {
    assert.match(controller, /\$_SESSION\['admin_user_invite_flash'\]\s*=\s*\$result/);
    assert.match(controller, /'Акаунт для запрошення створено\.'/);
    assert.match(controller, /\$this->redirectBack\(\s*''\s*,\s*'message'/);
    assert.doesNotMatch(controller, /AnabelkaFlash::(?:success|info)\(\s*\$result/);
    assert.match(view, /if \(\$inviteFlash && !empty\(\$inviteFlash\['invite_token'\]\)\)/);
    assert.match(view, /rawurlencode\(\(string\) \$inviteFlash\['invite_token'\]\)/);
    assert.match(view, /class="admin-invite-result"/);
    assert.match(view, /admin-invite-message-field/);
    assert.match(view, /<textarea rows="6" readonly>/);
});

test('permission, audit, CSRF and destructive confirmations remain intact', () => {
    assert.match(controller, /AdminAccess::verifyCsrf/);
    assert.match(controller, /AdminAccess::audit\(/);
    assert.match(controller, /CustomerNotification::create\(/);
    assert.match(controller, /CustomerRankRequest::approve\(/);
    assert.match(controller, /CustomerRankRequest::reject\(/);
    assert.match(controller, /AdminUser::changeRank\(/);
    assert.match(controller, /AdminUser::deleteAccount\(/);
    assert.match(controller, /AdminUser::deactivateAccount\(/);
    assert.match(view, /data-anabelka-confirm-danger="1"/);
    assert.match(view, /data-anabelka-confirm-title="Зміна рангу"/);
    assert.match(view, /name="return_query"/);
    assert.match(view, /<section class="admin-users-panel admin-rank-history">/);
});

test('validation and authorization failures remain persistent with accessible branded UI', () => {
    assert.match(controller, /\$query\[\$key\]\s*=\s*\$message/);
    assert.match(controller, /'error' => trim\(\(string\) \(\$_GET\['error'\] \?\? ''\)\)/);
    assert.match(view, /admin-users-message is-error" role="alert"/);
    assert.match(view, /admin-users-message is-success" role="status"/);
    assert.match(view, /admin-users\.css\?v=4/);
    assert.match(css, /\.admin-users-message\.is-error\s*\{[^}]*--notify-info-bg,\s*#f4eaff/s);
    assert.match(css, /\.admin-users-message\.is-success\s*\{[^}]*--notify-success-bg,\s*#8A2BE2/s);
    assert.doesNotMatch(
        css.match(/\.admin-users-message\.is-error\s*\{([^}]*)\}/s)?.[1] || '',
        /#fff0f2|#e7b9c1|#8e3748/
    );
});

test('shared one-time queue is present without changing application startup', () => {
    assert.match(bootstrap, /js\/anabelka-notify\.js/);
    assert.match(bootstrap, /AnabelkaFlash::consume\(\)/);
    assert.match(entrypoint, /NotificationBootstrap::register\(\)/);
});

test('PHP runtime checks queued outcomes and protected one-time invitation data', () => {
    const result = spawnSync(process.env.PHP_BIN || 'php', [
        '-n', 'tests/admin_users_notify_runtime.php'
    ], {encoding: 'utf8'});
    assert.equal(result.error, undefined);
    assert.equal(result.status, 0, result.stderr || result.stdout);
    assert.match(result.stdout, /admin users notification runtime passed/);
});
