import assert from 'node:assert/strict';
import fs from 'node:fs';

const profile = fs.readFileSync(
    'views/admin/administrators/profile.php',
    'utf8'
);

const doctypeCount = (profile.match(/<!DOCTYPE html>/g) || []).length;
const htmlEndCount = (profile.match(/<\/html>/g) || []).length;
const htmlEnd = profile.indexOf('</html>');

assert.equal(doctypeCount, 1);
assert.equal(htmlEndCount, 1);
assert.ok(htmlEnd >= 0);
assert.equal(
    profile.slice(htmlEnd + '</html>'.length).trim(),
    ''
);

assert.match(profile, /'UAH' => '₴'/);
assert.match(profile, /'EUR' => '€'/);
assert.match(profile, /'USD' => '\$'/);
assert.match(profile, /'PLN' => 'zł'/);

assert.match(
    profile,
    /\$formatMoney = static function/
);
assert.match(
    profile,
    /\$formatDateTime = static function/
);
assert.match(profile, /Мій робочий контракт/);

assert.equal(
    (profile.match(/<h3>Дані профілю<\/h3>/g) || []).length,
    1
);
assert.equal(
    (profile.match(/<h3>Змінити пароль<\/h3>/g) || []).length,
    1
);

process.stdout.write(
    'admin profile template integrity contract passed\n'
);
