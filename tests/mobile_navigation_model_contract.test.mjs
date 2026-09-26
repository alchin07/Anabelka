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

const modelPath = 'app/Models/MobileNavigation.php';
const translatorPath = 'app/Models/MobileNavigationTranslator.php';

assert.ok(
    fs.existsSync(modelPath),
    'MobileNavigation.php must exist before menu model behavior can pass'
);
assert.ok(
    fs.existsSync(translatorPath),
    'MobileNavigationTranslator.php must exist before menu translations can pass'
);

const model = fs.readFileSync(modelPath, 'utf8');
const translator = fs.readFileSync(translatorPath, 'utf8');

for (const method of [
    'validateUrl',
    'publicItems',
    'adminItems',
    'create',
    'update',
    'setActive',
    'reorder',
    'delete'
]) {
    assert.match(
        model,
        new RegExp(`public\\s+static\\s+function\\s+${method}\\s*\\(`),
        `MobileNavigation must expose ${method}()`
    );
}

assert.match(
    model,
    /preg_match\([^;]*https\?:\/\/[^;]*\)/is,
    'external http(s) URLs must be recognized'
);
assert.match(
    model,
    /\['http',\s*'https'\]/i,
    'external schemes must be restricted to http and https'
);
assert.match(model, /parse_url\s*\(/i, 'external URLs must be parsed server-side');
assert.match(model, /\/Anabelka/i, 'internal URLs must stay under /Anabelka');
assert.match(model, /rawurldecode\s*\(/i, 'encoded internal paths must be normalized before validation');
assert.match(model, /\\\\/i, 'backslashes must be rejected');
assert.match(model, /javascript|data|file/i, 'unsafe URL schemes must be rejected');
assert.match(model, /target|is_external/i, 'URL classification must be derived server-side');
assert.match(model, /beginTransaction\s*\(/i, 'multi-row menu mutations must use transactions');
assert.match(model, /FOR\s+UPDATE/i, 'reorder must lock the current order');
assert.match(model, /sort_order/i);
assert.match(model, /is_active/i);
assert.match(model, /mobile_navigation_items/i);
assert.doesNotMatch(
    model,
    /CREATE\s+TABLE|ALTER\s+TABLE/i,
    'runtime menu model must never create or migrate tables'
);

for (const method of [
    'localizeList',
    'getForItem',
    'save',
    'markOutdated'
]) {
    assert.match(
        translator,
        new RegExp(`public\\s+static\\s+function\\s+${method}\\s*\\(`),
        `MobileNavigationTranslator must expose ${method}()`
    );
}
assert.match(translator, /mobile_navigation_item_translations/i);
assert.match(translator, /outdated/i);
assert.match(translator, /approved/i);
assert.match(translator, /Language::SOURCE_CODE/);
assert.doesNotMatch(
    translator,
    /CREATE\s+TABLE|ALTER\s+TABLE/i,
    'runtime mobile-menu translator must never migrate schema'
);

process.stdout.write('mobile navigation model contract passed\n');
