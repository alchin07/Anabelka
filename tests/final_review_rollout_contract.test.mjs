import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const testDirectory = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(testDirectory, '..');

function filePath(relativePath) {
    return path.join(projectRoot, relativePath);
}

function read(relativePath) {
    return fs.readFileSync(filePath(relativePath), 'utf8');
}

function requireFile(relativePath) {
    assert.equal(
        fs.existsSync(filePath(relativePath)),
        true,
        `${relativePath} must exist`
    );

    return read(relativePath);
}

function withoutSqlComments(sql) {
    return sql
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/^\s*--.*$/gm, '')
        .trim();
}

function sqlStatements(sql) {
    return withoutSqlComments(sql)
        .split(';')
        .map((statement) => statement.trim())
        .filter(Boolean);
}

const sidebarCss = read('css/public-catalog-sidebar.css');

assert.match(
    sidebarCss,
    /@media\s*\(min-width:\s*1050px\)[\s\S]*?body\s*>\s*footer\s*\{[^{}]*grid-column:\s*1\s*\/\s*-1/si,
    'desktop public footer must span both catalog-layout columns'
);

const migrationPath = 'database/migrations/2026-09-16_home_content_modules.sql';
const preflightPath = 'database/preflight/2026-09-16_home_content_modules_preflight.sql';
const postflightPath = 'database/postflight/2026-09-16_home_content_modules_postflight.sql';
const migration = read(migrationPath);
const preflight = requireFile(preflightPath);
const postflight = requireFile(postflightPath);

assert.match(
    migration,
    /database\/preflight\/2026-09-16_home_content_modules_preflight\.sql/,
    'migration must tell the operator to run the read-only preflight first'
);
assert.match(
    migration,
    /database\/postflight\/2026-09-16_home_content_modules_postflight\.sql/,
    'migration must tell the operator to run the postflight afterwards'
);
assert.match(
    migration,
    /partial(?:ly)?\s+(?:applied|state)|partially-applied/i,
    'migration must document how to handle a partially applied state'
);

for (const [label, sql] of [
    ['preflight', preflight],
    ['postflight', postflight]
]) {
    const statements = sqlStatements(sql);

    assert.ok(statements.length >= 3, `${label} must contain useful checks`);

    for (const statement of statements) {
        assert.match(
            statement,
            /^(SELECT|SHOW|WITH\s+RECURSIVE)\b/i,
            `${label} must remain read-only: ${statement.slice(0, 60)}`
        );
    }

    for (const table of [
        'site_news',
        'site_news_translations',
        'product_reviews'
    ]) {
        assert.match(
            sql,
            new RegExp(table),
            `${label} must inspect ${table}`
        );
    }
}

for (const referencedTable of ['products', 'users', 'admin_users']) {
    assert.match(
        preflight,
        new RegExp(referencedTable),
        `preflight must inspect referenced table ${referencedTable}`
    );
}

assert.match(postflight, /uq_site_news_slug/i);
assert.match(postflight, /fk_site_news_translations_news/i);
assert.match(postflight, /uq_product_reviews_product_user/i);
assert.match(postflight, /fk_product_reviews_product/i);
assert.match(postflight, /fk_product_reviews_user/i);
assert.match(postflight, /fk_product_reviews_admin/i);

process.stdout.write('final review rollout contract passed\n');
