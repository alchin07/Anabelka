import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const read = path => fs.readFileSync(path, 'utf8');
const view = read('views/admin/administrators/index.php');
const header = read('views/admin/partials/header.php');
const dialog = read('js/anabelka-dialog.js');
const controller = read('app/Controllers/AdminAdministratorController.php');

function administratorForm(action) {
    const actionMarker = 'action="/Anabelka/admin/administrators/' + action + '"';
    const actionPos = view.indexOf(actionMarker);
    assert.ok(actionPos >= 0, 'Existing form must remain: ' + action);
    const start = view.lastIndexOf('<form', actionPos);
    const end = view.indexOf('</form>', actionPos);
    assert.ok(start >= 0 && end > actionPos, 'Entire form: ' + action);
    return view.slice(start, end + '</form>'.length);
}

function dialogHarness() {
    const created = [];
    const listeners = new Map();
    const buttonsSent = [];
    const previousFocus = {focus() {}};
    const classes = set => ({
        names: new Set(),
        add(name) {this.names.add(name);},
        remove(name) {this.names.delete(name);},
        toggle(name, on) {
            if (on) this.names.add(name);
            else this.names.delete(name);
        },
        contains(name) {return this.names.has(name);}
    });

    class Element {
        constructor(tag) {
            this.tag = tag;
            this.className = '';
            this.hidden = false;
            this.children = [];
            this.listeners = {};
            this.classList = classes();
            created.push(this);
        }
        setAttribute(name, value) {this[name] = String(value);}
        appendChild(child) {this.children.push(child);}
        addEventListener(type, callback) {this.listeners[type] = callback;}
        querySelectorAll() {
            const results = [];
            const visit = node => {
                if (node.tag === 'button') results.push(node);
                for (const child of node.children || []) visit(child);
            };
            visit(this);
            return results;
        }
        focus() {document.activeElement = this;}
        click() {this.listeners.click?.();}
    }

    const document = {
        activeElement: previousFocus,
        createElement(tag) {return new Element(tag);},
        body: new Element('body'),
        addEventListener(type, cb) {
            const items = listeners.get(type) || [];
            items.push(cb);
            listeners.set(type, items);
        },
        removeEventListener(type, cb) {
            listeners.set(type, (listeners.get(type) || []).filter(x => x !== cb));
        }
    };
    const window = {
        requestAnimationFrame(callback) {callback();},
        confirm() {throw new Error('Native browser confirm is forbidden');}
    };
    class HTMLFormElement {}

    vm.runInNewContext(dialog, {window, document, HTMLFormElement});

    function makeForm(options) {
        const form = new HTMLFormElement();
        form.dataset = {
            anabelkaConfirm: options.message,
            anabelkaConfirmTitle: options.title,
            anabelkaConfirmConfirmText: options.label,
            anabelkaConfirmDanger: options.danger ? '1' : '0'
        };
        form.csrf = 'unchanged-csrf-token';
        form.action = '/Anabelka/admin/administrators/' + options.action;
        form.hasAttribute = attr => attr === 'data-anabelka-confirm';
        form.requestSubmit = submitter => {
            let prevented = false;
            const event = {
                target: form,
                submitter: submitter || null,
                preventDefault() {prevented = true;}
            };
            for (const listener of listeners.get('submit') || []) listener(event);
            if (!prevented) {
                buttonsSent.push({
                    action: form.action, csrf: form.csrf, submitter: event.submitter
                });
            }
            return prevented;
        };
        return form;
    }

    const panel = () => created.find(item => item.className === 'anabelka-dialog');
    const confirmButton = () => created.find(item =>
        item.className === 'anabelka-dialog-button is-confirm');
    const cancelButton = () => created.find(item =>
        item.className === 'anabelka-dialog-button is-cancel');
    return {makeForm, buttonsSent, panel, confirmButton, cancelButton, document};
}

test('role change is protected by the shared branded dialog, not native confirm', () => {
    const form = administratorForm('role');
    assert.match(form, /data-anabelka-confirm="Змінити роль цього адміністратора\?"/);
    assert.match(form, /data-anabelka-confirm-title="Зміна ролі"/);
    assert.match(form, /data-anabelka-confirm-confirm-text="Змінити"/);
    assert.match(form, /name="_csrf"/);
    assert.match(form, /name="role_id"/);
    assert.match(form, /\$isCurrent \? 'disabled'/);
    assert.doesNotMatch(form, /onsubmit=|return confirm\(/);
});

test('password reset is an explicit danger confirmation and preserves password input', () => {
    const form = administratorForm('password');
    assert.match(form, /data-anabelka-confirm="Встановити новий пароль цьому адміністратору\?"/);
    assert.match(form, /data-anabelka-confirm-title="Зміна пароля"/);
    assert.match(form, /data-anabelka-confirm-confirm-text="Змінити пароль"/);
    assert.match(form, /data-anabelka-confirm-danger="1"/);
    assert.match(form, /type="password" name="password" minlength="10"/);
    assert.match(form, /autocomplete="new-password"/);
    assert.match(form, /name="_csrf"/);
    assert.doesNotMatch(form, /onsubmit=|return confirm\(/);
});

test('access enable/disable confirmations keep their own action and danger semantics', () => {
    const form = administratorForm('toggle');
    assert.match(form, /data-anabelka-confirm="<\?= \$isActive \?/);
    assert.match(form, /data-anabelka-confirm-title="<\?= \$isActive \?/);
    assert.match(form, /data-anabelka-confirm-confirm-text="<\?= \$isActive \?/);
    assert.match(form, /data-anabelka-confirm-danger="<\?= \$isActive \? '1' : '0' \?>"/);
    assert.match(form, /Вимкнути доступ цього адміністратора\?/);
    assert.match(form, /Увімкнути доступ цього адміністратора\?/);
    assert.match(form, /name="_csrf"/);
    assert.match(form, /name="admin_id"/);
    assert.match(form, /type="submit" <\?= \$isCurrent \? 'disabled'/);
    assert.doesNotMatch(form, /onsubmit=|return confirm\(/);
});

test('other high-impact actions retain the already working branded confirmation UI', () => {
    for (const action of [
        'invite/reissue', 'invite/revoke', 'delete', 'roles/delete'
    ]) {
        assert.match(administratorForm(action), /data-anabelka-confirm=/);
    }
    assert.match(administratorForm('invite/revoke'), /data-anabelka-confirm-danger="1"/);
    assert.match(administratorForm('delete'), /data-anabelka-confirm-danger="1"/);
    assert.match(administratorForm('roles/delete'), /data-anabelka-confirm-danger="1"/);
    assert.doesNotMatch(view, /onsubmit="return (?:window\.)?confirm\(/);
    assert.match(header, /js\/anabelka-dialog\.js\?v=3/);
    assert.match(header, /css\/anabelka-dialog\.css\?v=1/);
    assert.match(dialog, /form\.requestSubmit\(submitter\)/);
    assert.match(dialog, /if \(form\.dataset\.anabelkaConfirmed === '1'\)/);
});

test('cancel leaves the original administrator form untouched and makes no POST', async () => {
    const h = dialogHarness();
    const form = h.makeForm({
        action: 'role',
        message: 'Змінити роль цього адміністратора?',
        title: 'Зміна ролі',
        label: 'Змінити'
    });
    const originalButton = {id: 'role-submit'};
    assert.equal(form.requestSubmit(originalButton), true);
    assert.equal(h.buttonsSent.length, 0);
    assert.equal(h.panel().hidden, false);
    assert.equal(h.confirmButton().textContent, 'Змінити');

    h.cancelButton().click();
    await Promise.resolve();
    assert.equal(h.buttonsSent.length, 0);
    assert.equal(h.panel().hidden, true);
    assert.equal(form.dataset.anabelkaConfirmed, undefined);
    assert.equal(form.csrf, 'unchanged-csrf-token');
});

test('confirm submits once with unchanged submitter and CSRF, including a danger action', async () => {
    const h = dialogHarness();
    const role = h.makeForm({
        action: 'role',
        message: 'Змінити роль цього адміністратора?',
        title: 'Зміна ролі',
        label: 'Змінити'
    });
    const submitter = {id: 'role-submit'};
    assert.equal(role.requestSubmit(submitter), true);
    h.confirmButton().click();
    await Promise.resolve();
    assert.equal(h.buttonsSent.length, 1);
    assert.equal(h.buttonsSent[0].action, '/Anabelka/admin/administrators/role');
    assert.equal(h.buttonsSent[0].csrf, 'unchanged-csrf-token');
    assert.equal(h.buttonsSent[0].submitter, submitter);
    assert.equal(role.dataset.anabelkaConfirmed, undefined);

    const password = h.makeForm({
        action: 'password',
        message: 'Встановити новий пароль цьому адміністратору?',
        title: 'Зміна пароля',
        label: 'Змінити пароль',
        danger: true
    });
    assert.equal(password.requestSubmit(), true);
    assert.equal(h.confirmButton().classList.contains('is-danger'), true);
    h.confirmButton().click();
    await Promise.resolve();
    assert.equal(h.buttonsSent.length, 2);
    assert.equal(h.buttonsSent[1].action, '/Anabelka/admin/administrators/password');
    assert.equal(h.buttonsSent[1].csrf, 'unchanged-csrf-token');
    assert.equal(password.dataset.anabelkaConfirmed, undefined);
});

test('view-only access and existing one-time invitation output are untouched', () => {
    assert.match(view, /<\?php elseif \(\$canManage\): \?>/);
    assert.match(view, /\$isOwner/);
    assert.match(view, /if \(\$inviteFlash && \$inviteMessage !== ''\)/);
    assert.match(view, /id="admin-invite-message"/);
    assert.match(view, /data-invite-message=/);
    assert.match(controller, /AdminAccess::verifyCsrf/);
    assert.match(controller, /\$_SESSION\['admin_administrator_invite_flash'\] = \$result/);
    assert.match(controller, /AnabelkaFlash::info\(\$text\)/);
});
