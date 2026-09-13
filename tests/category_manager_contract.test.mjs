import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const testDirectory = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(testDirectory, '..');

function read(relativePath) {
    return fs.readFileSync(path.join(projectRoot, relativePath), 'utf8');
}

function routesFrom(relativePath) {
    const source = read(relativePath);
    const expression = /\$router->(get|post)\(\s*(['"])(.*?)\2\s*,\s*(['"])(.*?)\4\s*\)/gs;
    const routes = [];
    let match;

    while ((match = expression.exec(source)) !== null) {
        routes.push({
            method: match[1].toUpperCase(),
            path: match[3],
            action: match[5]
        });
    }

    return routes;
}

function routePattern(routePath) {
    const escaped = routePath.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    return new RegExp(
        '^' + escaped.replace(/\\\{[a-zA-Z_][a-zA-Z0-9_]*\\\}/g, '([^/]+)') + '$'
    );
}

function dispatch(routes, method, requestPath) {
    return routes.find(function (route) {
        return route.method === method && routePattern(route.path).test(requestPath);
    });
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

function test(name, callback) {
    try {
        callback();
        process.stdout.write(`ok - ${name}\n`);
    } catch (error) {
        process.stderr.write(`not ok - ${name}\n`);
        throw error;
    }
}

test('canonical catalog route wins before the one-segment legacy route', function () {
    const routes = routesFrom('routes/Web.php');

    assert.equal(
        dispatch(routes, 'GET', '/catalog/lingerie/push-up')?.action,
        'CatalogController@category'
    );
    assert.equal(
        dispatch(routes, 'GET', '/catalog/push-up')?.action,
        'CatalogController@legacyCategory'
    );
});

test('adult gate has canonical GET/POST and isolated legacy handlers', function () {
    const routes = routesFrom('routes/Adult.php');

    assert.equal(
        dispatch(routes, 'GET', '/18-plus/lingerie/intimate')?.action,
        'AdultController@entry'
    );
    assert.equal(
        dispatch(routes, 'POST', '/18-plus/lingerie/intimate')?.action,
        'AdultController@confirm'
    );
    assert.equal(
        dispatch(routes, 'GET', '/18-plus/intimate')?.action,
        'AdultController@legacyEntry'
    );
    assert.equal(
        dispatch(routes, 'POST', '/18-plus/intimate')?.action,
        'AdultController@legacyConfirm'
    );
});

test('category manager mutations are exposed as protected POST routes', function () {
    const routes = routesFrom('routes/Web.php');
    const expected = new Map([
        ['/admin/categories/create', 'AdminCategoryController@create'],
        ['/admin/categories/update', 'AdminCategoryController@update'],
        ['/admin/categories/move', 'AdminCategoryController@move'],
        ['/admin/categories/toggle', 'AdminCategoryController@toggle'],
        ['/admin/categories/delete', 'AdminCategoryController@delete']
    ]);

    expected.forEach(function (action, routePath) {
        assert.equal(dispatch(routes, 'POST', routePath)?.action, action);
        assert.equal(dispatch(routes, 'GET', routePath), undefined);
    });

    const controller = read('app/Controllers/AdminCategoryController.php');
    assert.match(controller, /public function (create|update|move|toggle|delete)\(\)[\s\S]*?\$this->verifyCsrf\(\);/);
});

test('database preflight contains read-only statements only', function () {
    const statements = sqlStatements(
        read('database/preflight/category_manager_preflight.sql')
    );

    assert.ok(statements.length >= 10);

    statements.forEach(function (statement) {
        assert.match(statement, /^(SELECT|SHOW|WITH\s+RECURSIVE)\b/i);
    });
});

test('migration changes only the approved category tables', function () {
    const statements = sqlStatements(
        read('database/migrations/2026-09-13_category_manager.sql')
    );
    const ddl = statements.filter((statement) => /^ALTER\s+TABLE\b/i.test(statement));

    assert.equal(ddl.length, 2);
    assert.match(ddl[0], /^ALTER\s+TABLE\s+category_translations\b/i);
    assert.match(ddl[1], /^ALTER\s+TABLE\s+categories\b/i);
    assert.doesNotMatch(withoutSqlComments(statements.join(';')), /ALTER\s+TABLE\s+products\b/i);
    assert.doesNotMatch(
        withoutSqlComments(statements.join(';')),
        /(?:^|;)\s*(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE)\b/i
    );
});

test('translation ownership is constrained before category FK rules change', function () {
    const sql = withoutSqlComments(
        read('database/migrations/2026-09-13_category_manager.sql')
    );
    const translationFk = sql.indexOf(
        'ALTER TABLE category_translations'
    );
    const categoryAlter = sql.indexOf('ALTER TABLE categories');

    assert.ok(translationFk >= 0);
    assert.ok(categoryAlter > translationFk);
    assert.match(sql, /ADD\s+COLUMN\s+is_adult\s+TINYINT\(1\)\s+NOT\s+NULL\s+DEFAULT\s+0/i);
    assert.match(sql, /fk_categories_department[\s\S]*ON\s+DELETE\s+RESTRICT/i);
    assert.match(sql, /fk_categories_parent[\s\S]*ON\s+DELETE\s+RESTRICT/i);
    assert.match(sql, /fk_category_translations_category[\s\S]*ON\s+DELETE\s+CASCADE/i);
});

test('subtree moves synchronize product departments and preserve no-op order', function () {
    const manager = read('app/Services/CategoryManager.php');

    assert.match(
        manager,
        /UPDATE\s+products\s+SET\s+department_id\s*=\s*:department_id\s+WHERE\s+category_id\s+IN/si
    );
    assert.match(
        manager,
        /p\.department_id\s*<>\s*c\.department_id/i
    );
    assert.match(
        manager,
        /\$positionChanged\s*=\s*\$targetDepartmentId\s*!==\s*\$oldDepartmentId[\s\S]*?\$targetParentId\s*!==\s*\$oldParentId/
    );
    assert.match(manager, /FOR\s+UPDATE/i);
});

test('slug and deletion policies match the production keys', function () {
    const manager = read('app/Services/CategoryManager.php');

    assert.match(
        manager,
        /WHERE\s+department_id\s*=\s*:department_id\s+AND\s+slug\s*=\s*:slug/si
    );
    assert.match(manager, /assertNoSlugCollisions\(/);
    assert.doesNotMatch(manager, /UPDATE\s+products\s+SET\s+slug\b/si);
    assert.match(
        manager,
        /FROM\s+categories\s+WHERE\s+parent_id\s*=\s*:category_id\s+FOR\s+UPDATE/si
    );
    assert.match(
        manager,
        /FROM\s+products\s+WHERE\s+category_id\s*=\s*:category_id\s+FOR\s+UPDATE/si
    );
    assert.match(
        manager,
        /DELETE\s+FROM\s+category_translations\s+WHERE\s+category_id\s*=\s*:category_id/si
    );
});

test('adult visibility is explicit and inherited without name heuristics', function () {
    const category = read('app/Models/Category.php');
    const homePage = read('app/Models/HomePage.php');

    assert.match(category, /\$adult\s*=\s*!empty\(\$category\['is_adult'\]\)/);
    assert.match(category, /\$adult\s*=\s*\$adult\s*\|\|\s*!empty\(\$parentState\['adult'\]\)/);
    assert.doesNotMatch(homePage, /looksAdult|18-plus'\s*,|adult'\s*,|інтим|ерот/i);
});

test('category manager UI keeps stable collapse storage and structural controls', function () {
    const script = read('js/admin-categories.js');

    assert.match(script, /category-collapsed-items/);
    assert.match(script, /descendantIds\(categoryId\)/);
    assert.match(script, /window\.setCategoryTranslationWorkflow/);
});

process.stdout.write('category manager contract checks passed\n');
