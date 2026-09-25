import assert from 'node:assert/strict';
import fs from 'node:fs';

const model = fs.readFileSync(
    'app/Models/AdminWorkTime.php',
    'utf8'
);
const controller = fs.readFileSync(
    'app/Controllers/AdminAdministratorController.php',
    'utf8'
);
const view = fs.readFileSync(
    'views/admin/administrators/profile.php',
    'utf8'
);
const css = fs.readFileSync(
    'css/admin-profile.css',
    'utf8'
);

assert.match(
    model,
    /public static function currentAdminContract\(\)/
);
assert.match(
    model,
    /\$adminUserId = AdminAccess::currentId\(\)/
);
assert.doesNotMatch(
    model,
    /currentAdminContract\s*\(\s*\$adminUserId/
);
assert.match(
    model,
    /FROM admin_work_compensation[\s\S]*?WHERE admin_user_id = :admin_user_id/
);
assert.match(
    model,
    /'agreement' => \$agreement/
);
assert.match(
    model,
    /'earnings' => \$earnings/
);
assert.match(
    model,
    /'admin_seconds' => \$adminSeconds/
);
assert.match(
    model,
    /'public_seconds' => \$publicSeconds/
);
assert.match(
    model,
    /'session_count' => \$sessionCount/
);
assert.match(
    model,
    /'active_days' => count\(\$daily\)/
);
assert.match(
    model,
    /ORDER BY work_date DESC/
);

assert.match(
    controller,
    /AdminWorkTime::currentAdminContract\(\)/
);
assert.match(
    controller,
    /'workContract' => \$workContract/
);

assert.match(view, /Мій робочий контракт/);
assert.match(view, /Нараховано/);
assert.match(view, /Ставка/);
assert.match(view, /Тип виплати/);
assert.match(view, /Період розрахунку/);
assert.match(view, /Адмін-панель/);
assert.match(view, />Сайт</);
assert.match(view, /Сесій/);
assert.match(view, /Активних днів/);
assert.match(view, /Робочий час по днях/);
assert.match(view, /Умови контракту змінюються лише Розробником або Власником/);
assert.match(view, /admin-profile\.css\?v=3/);

const contractStart = view.indexOf(
    'admin-profile-card admin-profile-work-contract'
);
const contractEnd = view.indexOf(
    '<section class="admin-profile-card">',
    contractStart
);
assert.ok(contractStart >= 0 && contractEnd > contractStart);
const contractBlock = view.slice(contractStart, contractEnd);

assert.doesNotMatch(contractBlock, /name="hourly_rate"/);
assert.doesNotMatch(contractBlock, /name="payout_type"/);
assert.doesNotMatch(contractBlock, /action="\/Anabelka\/admin\/work-time\/compensation"/);

assert.match(css, /\.admin-profile-work-contract/);
assert.match(css, /\.admin-profile-contract-amount/);
assert.match(css, /\.admin-profile-contract-hours/);
assert.match(css, /\.admin-profile-contract-day/);

process.stdout.write(
    'personal administrator work contract profile passed\n'
);
