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

function escapeRegExp(value) {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function createTableDdlFrom(relativePath, tableName) {
    const source = read(relativePath);
    const execExpression = /\$db->exec\(\s*"([\s\S]*?)"\s*\);/g;
    let match;

    while ((match = execExpression.exec(source)) !== null) {
        if (new RegExp(
            'CREATE\\s+TABLE\\s+IF\\s+NOT\\s+EXISTS\\s+'
                + escapeRegExp(tableName)
                + '\\b',
            'i'
        ).test(match[1])) {
            return match[1];
        }
    }

    assert.fail(`CREATE TABLE DDL for ${tableName} was not found`);
}

function assertBalancedSqlParentheses(sql) {
    let depth = 0;

    for (const [offset, character] of Array.from(sql).entries()) {
        if (character === '(') {
            depth += 1;
        } else if (character === ')') {
            depth -= 1;
            assert.ok(
                depth >= 0,
                `unmatched closing parenthesis at SQL offset ${offset}`
            );
        }
    }

    assert.equal(depth, 0, 'CREATE TABLE DDL has unclosed parentheses');
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

    ['create', 'update', 'move', 'toggle', 'delete'].forEach(function (method) {
        assert.match(
            controller,
            new RegExp(
                'public\\s+function\\s+'
                    + escapeRegExp(method)
                    + '\\(\\)\\s*\\{\\s*\\$this->verifyCsrf\\(\\);'
            )
        );
    });
});

test('legacy redirects remain temporary because department ownership can move', function () {
    const catalog = read('app/Controllers/CatalogController.php');
    const adult = read('app/Controllers/AdultController.php');

    assert.match(catalog, /header\('Location: '\s*\.\s*\$url,\s*true,\s*302\)/);
    assert.match(adult, /header\('Location: '\s*\.\s*\$url,\s*true,\s*302\)/);
    assert.doesNotMatch(catalog, /true,\s*301/);
    assert.doesNotMatch(adult, /true,\s*301/);
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

test('replacement category foreign keys use fresh constraint symbols', function () {
    const statements = sqlStatements(
        read('database/migrations/2026-09-13_category_manager.sql')
    );
    const categoryAlter = statements.find(function (statement) {
        return /^ALTER\s+TABLE\s+categories\b/i.test(statement);
    });

    assert.ok(categoryAlter);
    assert.match(
        categoryAlter,
        /DROP\s+FOREIGN\s+KEY\s+fk_categories_department\b/i
    );
    assert.match(
        categoryAlter,
        /DROP\s+FOREIGN\s+KEY\s+fk_categories_parent\b/i
    );
    assert.match(
        categoryAlter,
        /ADD\s+CONSTRAINT\s+fk_categories_department_restrict\b/i
    );
    assert.match(
        categoryAlter,
        /ADD\s+CONSTRAINT\s+fk_categories_parent_restrict\b/i
    );
    assert.doesNotMatch(
        categoryAlter,
        /ADD\s+CONSTRAINT\s+fk_categories_department\s/i
    );
    assert.doesNotMatch(
        categoryAlter,
        /ADD\s+CONSTRAINT\s+fk_categories_parent\s/i
    );
});

test('preflight recognizes pristine and migrated category FK names', function () {
    const preflight = withoutSqlComments(
        read('database/preflight/category_manager_preflight.sql')
    );

    [
        'fk_categories_department',
        'fk_categories_parent',
        'fk_categories_department_restrict',
        'fk_categories_parent_restrict'
    ].forEach(function (constraintName) {
        assert.match(
            preflight,
            new RegExp("'" + constraintName + "'", 'i')
        );
    });
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
    assert.match(sql, /fk_categories_department_restrict[\s\S]*ON\s+DELETE\s+RESTRICT/i);
    assert.match(sql, /fk_categories_parent_restrict[\s\S]*ON\s+DELETE\s+RESTRICT/i);
    assert.match(sql, /fk_category_translations_category[\s\S]*ON\s+DELETE\s+CASCADE/i);
});

test('CategoryTranslator emits structurally balanced CREATE TABLE DDL', function () {
    const ddl = createTableDdlFrom(
        'app/Models/CategoryTranslator.php',
        'category_translations'
    );

    assert.match(ddl, /CONSTRAINT\s+fk_category_translations_category/i);
    assertBalancedSqlParentheses(ddl);
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
    assert.match(
        manager,
        /else\s*\{\s*if\s*\(\$requestedDepartmentId\s*<=\s*0\)[\s\S]*?Оберіть підрозділ для кореневої категорії[\s\S]*?\$targetDepartmentId\s*=\s*\$requestedDepartmentId;/
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

test('category collapse swaps symbols without transforming the button box', function () {
    const css = read('css/admin-categories.css');
    const script = read('js/admin-categories.js');
    const collapseButtonRules = Array.from(
        css.matchAll(/([^{}]+)\{([^{}]*)\}/g)
    ).filter(function (match) {
        return match[1].includes('.category-collapse-button');
    });

    collapseButtonRules.forEach(function (rule) {
        assert.doesNotMatch(rule[2], /(?:^|;)\s*transform\s*:/i);
    });
    assert.match(
        script,
        /button\.textContent\s*=\s*isCollapsed\s*\?\s*'›'\s*:\s*'⌄'/
    );
    assert.match(
        script,
        /button\.setAttribute\(\s*'aria-expanded',\s*isCollapsed\s*\?\s*'false'\s*:\s*'true'\s*\)/
    );
    assert.match(script, /sessionStorage\.getItem\(collapsedStorageKey\)/);
    assert.match(script, /sessionStorage\.setItem\(\s*collapsedStorageKey/);
});

test('category mutations use shared flash before immediate reload or replace', function () {
    const script = read('js/admin-categories.js');

    assert.match(
        script,
        /const result = await request\(form\.action, new FormData\(form\)\);\s*window\.AnabelkaNotify\.flash\(\s*'success',\s*result\.message \|\| 'Збережено\.'\s*\);\s*closeModals\(false\);\s*if \(returnUrl\) \{\s*window\.location\.replace\(returnUrl\);\s*\} else \{\s*window\.location\.reload\(\);\s*\}/
    );
    assert.match(
        script,
        /const result = await request\(url, data\);\s*window\.AnabelkaNotify\.flash\(\s*'success',\s*result\.message \|\| 'Збережено\.'\s*\);\s*window\.location\.reload\(\);/
    );
    assert.doesNotMatch(script, /\},\s*(?:250|300)\s*\);/);
    assert.doesNotMatch(script, /storeSuccessMessage|AdminFlashMessage/);

    ['editForm', 'createForm', 'moveForm', 'deleteForm'].forEach(
        function (formName) {
            assert.match(
                script,
                new RegExp(
                    escapeRegExp(formName)
                        + '\\s*\\.addEventListener\\(\\s*'
                        + "'submit',\\s*submitAndReload\\("
                )
            );
        }
    );
    assert.match(
        script,
        /simpleAction\(button, '\/Anabelka\/admin\/categories\/toggle'/
    );
    assert.match(
        script,
        /simpleAction\(button, '\/Anabelka\/admin\/categories\/move'/
    );
});

test('category failures and AI feedback use the shared typed API', function () {
    const manager = read('js/admin-categories.js');
    const ai = read('js/admin-category-ai-translation.js');

    assert.match(manager, /window\.AnabelkaNotify\.error\(/);
    assert.doesNotMatch(
        manager,
        /function\s+showMessage|AdminFlashMessage|anabelka-category-success-flash/
    );
    assert.match(
        ai,
        /window\.AnabelkaNotify\.warning\([\s\S]*Система ШІ-перекладу ще завантажується/
    );
    assert.match(
        ai,
        /window\.AnabelkaNotify\.info\([\s\S]*ШІ-переклад отримано/
    );
    assert.match(
        ai,
        /catch \(error\) \{\s*window\.AnabelkaNotify\.error\(/
    );
    assert.doesNotMatch(ai, /function\s+showMessage|site-message|window\.alert\(/);
});

test('category AI loader cache versions expose the migrated script', function () {
    const nav = read('js/admin-nav.js');
    const adminHeader = read('views/admin/partials/header.php');

    assert.match(
        nav,
        /\/Anabelka\/js\/admin-category-ai-translation\.js\?v=5/
    );
    const loaderVersion = adminHeader.match(/\/Anabelka\/js\/admin-nav\.js\?v=(\d+)/);
    assert.ok(loaderVersion && Number(loaderVersion[1]) >= 18);
});

test('category views rely on the single shared notification partial', function () {
    const categoryView = read('views/admin/categories/index.php');
    const missingTranslationsView = read('views/admin/translations/missing.php');

    [categoryView, missingTranslationsView].forEach(function (view) {
        assert.doesNotMatch(view, /admin-flash-message/);
        assert.doesNotMatch(view, /id="site-message"|class="site-message"/);
        assert.match(view, /require\s+__DIR__\s*\.\s*'\/\.\.\/\.\.\/partials\/header\.php'/);
    });
    assert.match(categoryView, /js\/admin-categories\.js\?v=3/);
    assert.equal(fs.existsSync(path.join(projectRoot, 'js/admin-flash-message.js')), false);
    assert.equal(fs.existsSync(path.join(projectRoot, 'css/admin-flash-message.css')), false);
});

process.stdout.write('category manager contract checks passed\n');
