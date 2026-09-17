import assert from 'node:assert/strict';
import fs from 'node:fs';

const files = {
    preflight: 'database/preflight/2026-09-17_mobile_navigation_preflight.sql',
    migration: 'database/migrations/2026-09-17_mobile_navigation.sql',
    postflight: 'database/postflight/2026-09-17_mobile_navigation_postflight.sql'
};

for (const [label, file] of Object.entries(files)) {
    assert.ok(
        fs.existsSync(file),
        `${label} SQL must exist before mobile navigation schema can pass`
    );
}

const sql = fs.readFileSync(files.migration, 'utf8');

assert.match(sql, /CREATE TABLE\s+mobile_navigation_items\s*\(/i);
assert.match(sql, /name_uk\s+VARCHAR\(160\)\s+NOT NULL/i);
assert.match(sql, /url\s+VARCHAR\(1000\)\s+NOT NULL/i);
assert.match(sql, /is_external\s+TINYINT\(1\)\s+NOT NULL\s+DEFAULT\s+0/i);
assert.match(sql, /is_active\s+TINYINT\(1\)\s+NOT NULL\s+DEFAULT\s+1/i);
assert.match(sql, /sort_order\s+INT\s+NOT NULL\s+DEFAULT\s+0/i);
assert.match(sql, /KEY\s+idx_mobile_navigation_items_order\s*\(\s*is_active\s*,\s*sort_order\s*,\s*id\s*\)/i);

assert.match(sql, /CREATE TABLE\s+mobile_navigation_item_translations\s*\(/i);
assert.match(sql, /PRIMARY KEY\s*\(\s*item_id\s*,\s*language_code\s*\)/i);
assert.match(sql, /CONSTRAINT\s+fk_mobile_navigation_translation_item/i);
assert.match(sql, /ON DELETE\s+CASCADE\s+ON UPDATE\s+RESTRICT/i);

for (const url of [
    '/Anabelka/discounts',
    '/Anabelka/new',
    '/Anabelka/delivery-payment',
    '/Anabelka/contacts'
]) {
    assert.ok(sql.includes(url), `migration must seed ${url}`);
}

assert.doesNotMatch(
    sql,
    /\b(?:DROP|TRUNCATE)\s+TABLE\b|\bALTER\s+TABLE\s+(?:products|categories|users|orders)\b/i,
    'mobile navigation migration must not mutate unrelated core tables'
);

const app = fs.readFileSync('app/Core/App.php', 'utf8');
assert.doesNotMatch(
    app,
    /mobile_navigation.*(?:CREATE TABLE|migration|exec\s*\()/is,
    'App.php must not auto-run the mobile navigation migration'
);

process.stdout.write('mobile navigation schema contract passed\n');
