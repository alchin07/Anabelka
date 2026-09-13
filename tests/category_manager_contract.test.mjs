import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
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

function cssRuleBody(css, selector) {
    const expression = new RegExp(
        '(?:^|})\\s*' + escapeRegExp(selector) + '\\s*\\{([^{}]*)\\}',
        'm'
    );
    const match = css.match(expression);

    assert.ok(match, `CSS rule ${selector} was not found`);
    return match[1];
}

function flashPage(storage, messageElement) {
    const timers = [];
    const window = {
        sessionStorage: {
            getItem(key) {
                return storage.has(key) ? storage.get(key) : null;
            },
            setItem(key, value) {
                storage.set(key, String(value));
            },
            removeItem(key) {
                storage.delete(key);
            }
        },
        clearTimeout() {},
        setTimeout(callback, delay) {
            timers.push({callback, delay});
            return timers.length;
        }
    };
    const document = {
        getElementById(id) {
            return id === 'site-message' ? messageElement : null;
        }
    };

    window.window = window;
    vm.runInNewContext(
        read('js/admin-flash-message.js'),
        {document, window}
    );

    return {timers, window};
}

function renderCategoryBranches(hasChildren, hasProducts) {
    const source = read('views/catalog/category.php');
    const directives = /<\?php\s*(?:(if|elseif)\s*\(([\s\S]*?)\)\s*:|(else)\s*:|(endif)\s*;)\s*\?>/g;
    const frames = [];
    let active = true;
    let cursor = 0;
    let output = '';
    let match;

    function evaluate(expression) {
        const normalized = String(expression || '').replace(/\s+/g, '');

        if (normalized === '!empty($children)') {
            return hasChildren;
        }
        if (normalized === '!empty($products)') {
            return hasProducts;
        }
        if (
            normalized
                === 'empty($children)&&empty($products)'
        ) {
            return !hasChildren && !hasProducts;
        }

        // Nested product-card conditions do not affect section visibility.
        return true;
    }

    while ((match = directives.exec(source)) !== null) {
        if (active) {
            output += source.slice(cursor, match.index);
        }

        if (match[1] === 'if') {
            const condition = evaluate(match[2]);
            const frame = {
                parentActive: active,
                matched: condition,
                active: active && condition
            };

            frames.push(frame);
            active = frame.active;
        } else if (match[1] === 'elseif') {
            const frame = frames.at(-1);
            const condition = evaluate(match[2]);

            assert.ok(frame, 'elseif without matching if');
            frame.active = frame.parentActive
                && !frame.matched
                && condition;
            frame.matched = frame.matched || condition;
            active = frame.active;
        } else if (match[3] === 'else') {
            const frame = frames.at(-1);

            assert.ok(frame, 'else without matching if');
            frame.active = frame.parentActive && !frame.matched;
            frame.matched = true;
            active = frame.active;
        } else {
            const frame = frames.pop();

            assert.ok(frame, 'endif without matching if');
            active = frame.parentActive;
        }

        cursor = directives.lastIndex;
    }

    if (active) {
        output += source.slice(cursor);
    }
    assert.equal(frames.length, 0, 'category template has unclosed if');

    return {
        subcategories: output.includes('class="catalog-categories"'),
        products: output.includes('class="catalog-products"'),
        empty: output.includes('У цій категорії поки немає товарів.'),
        output
    };
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

test('catalog root renders data-driven adult categories after standard roots', function () {
    const view = read('views/catalog/index.php');
    const standardLoop = view.indexOf(
        'foreach ($standardCategories as $category)'
    );
    const adultLoop = view.indexOf(
        'foreach ($adultCategories as $category)'
    );

    assert.match(view, /\$standardCategories\s*=\s*\[\]/);
    assert.match(view, /\$adultCategories\s*=\s*\[\]/);
    assert.match(
        view,
        /foreach\s*\(\$categories\s+as\s+\$category\)[\s\S]*?!empty\(\$category\['is_adult'\]\)[\s\S]*?\$adultCategories\[\]\s*=\s*\$category[\s\S]*?\$standardCategories\[\]\s*=\s*\$category/
    );
    assert.ok(standardLoop >= 0, 'standard category loop was not found');
    assert.ok(
        adultLoop > standardLoop,
        'adult category loop must follow all standard root categories'
    );

    const standardBlock = view.slice(standardLoop, adultLoop);
    const adultBlock = view.slice(adultLoop);

    assert.match(standardBlock, /Category::catalogUrl\(\$category\)/);
    assert.match(adultBlock, /AdultAccess::gateUrl\(\$category\)/);
    assert.match(adultBlock, /\$category\['name'\]/);
    assert.doesNotMatch(adultBlock, /\$category\['id'\]\s*={2,3}\s*\d+/);
    assert.doesNotMatch(
        adultBlock,
        /\$category\['slug'\]\s*={2,3}\s*['"][^'"]+['"]/
    );
    assert.doesNotMatch(adultBlock, /href=['"]\/Anabelka\/18-plus\//);
});

test('catalog adult entry has Anabelka branding and a mobile-safe strawberry card', function () {
    const view = read('views/catalog/index.php');
    const css = read('css/catalog.css');
    const homeCss = read('css/home.css');
    const sidebarCss = read('css/home-desktop-sidebar.css');
    const entryRule = cssRuleBody(css, '.catalog-adult-entry');
    const badgeRule = cssRuleBody(css, '.catalog-adult-badge');

    assert.match(view, /class="catalog-adult-brand-name"[^>]*>\s*Анабелька\s*</);
    assert.match(view, /class="catalog-adult-strawberry"/);
    assert.match(view, /class="catalog-adult-category-name"[\s\S]*?\$category\['name'\]/);
    assert.match(entryRule, /min-width:\s*0/i);
    assert.match(entryRule, /max-width:\s*100%/i);
    assert.match(entryRule, /box-sizing:\s*border-box/i);
    assert.match(entryRule, /#8a2be2/i);
    assert.match(entryRule, /#6519b9/i);
    assert.match(badgeRule, /background:\s*#f4eaff/i);
    assert.match(badgeRule, /color:\s*#6519b9/i);
    assert.match(css, /@media\s*\(max-width:\s*600px\)[\s\S]*?\.catalog-adult-entry[\s\S]*?grid-template-columns:\s*minmax\(0,\s*1fr\)\s+auto/i);
    assert.doesNotMatch(view + css, /#302437/i);
    assert.match(
        cssRuleBody(homeCss, '.home-adult-section'),
        /#8a2be2[\s\S]*#6519b9/i
    );
    assert.doesNotMatch(
        homeCss + sidebarCss,
        /#302437|#2a232d|#171319|#241d27/i
    );
});

test('adult gate keeps the return URL contract and uses the Anabelka palette', function () {
    const controller = read('app/Controllers/AdultController.php');
    const view = read('views/adult/gate.php');
    const css = read('css/adult-gate.css');
    const badgeRule = cssRuleBody(css, '.adult-gate-badge');
    const confirmRule = cssRuleBody(css, '.adult-gate-confirm');

    assert.match(
        controller,
        /\$returnUrl\s*=\s*AdultAccess::safeReturnUrl\([\s\S]*?\$_GET\['return'\]\s*\?\?\s*\$defaultReturn/
    );
    assert.match(
        controller,
        /\$returnUrl\s*=\s*AdultAccess::safeReturnUrl\([\s\S]*?\$_POST\['return_url'\]\s*\?\?\s*\$defaultReturn/
    );
    assert.match(controller, /AdultAccess::confirm\(\);\s*header\('Location: '\s*\.\s*\$returnUrl\)/);
    assert.match(view, /name="return_url"[\s\S]*?\$returnUrl/);
    assert.match(view, /action="<\?=\s*\$escape\(AdultAccess::gateUrl\(\$category\)\)\s*\?>"/);

    assert.match(badgeRule, /background:\s*#8a2be2/i);
    assert.match(confirmRule, /background:\s*#8a2be2/i);
    assert.match(
        css,
        /\.adult-gate-confirm:hover[^{}]*\{[^{}]*background:\s*#6519b9/is
    );
    assert.match(css, /#f4eaff/i);
    assert.match(css, /color:\s*#(?:fff|ffffff)/i);
    assert.doesNotMatch(view + css, /#302437|#241d27/i);
    assert.doesNotMatch(confirmRule, /background:\s*#(?:000|000000)\b/i);
});

test('favorite stays beside the logo before search with bounded mobile geometry', function () {
    const header = read('views/partials/header.php');
    const css = read('css/public-header.css');
    const logoPosition = header.indexOf('public-header-logo');
    const favoritePosition = header.indexOf('header-favorites');
    const searchPosition = header.indexOf('site-search-form');
    const actionsPosition = header.indexOf('public-header-actions');
    const mainRule = cssRuleBody(css, '.public-header-main');
    const brandRule = cssRuleBody(css, '.public-header-brand');

    assert.ok(logoPosition >= 0);
    assert.ok(favoritePosition > logoPosition);
    assert.ok(searchPosition > favoritePosition);
    assert.ok(actionsPosition > searchPosition);
    assert.equal(
        (header.match(/href="\/Anabelka\/favorites"/g) || []).length,
        1
    );
    assert.match(
        header,
        /<nav[\s\S]*?class="public-header-brand"[\s\S]*?public-header-logo[\s\S]*?header-favorites[\s\S]*?<\/nav>\s*<form\s+class="site-search-form"/
    );

    assert.match(brandRule, /display:\s*(?:inline-)?flex/i);
    assert.match(brandRule, /align-items:\s*center/i);
    assert.match(brandRule, /min-width:\s*0/i);
    assert.match(brandRule, /max-width:\s*100%/i);
    assert.match(
        mainRule,
        /grid-template-columns:\s*max-content\s+minmax\(280px,\s*1fr\)\s+auto/i
    );
    assert.match(
        css,
        /@media\s*\(max-width:\s*760px\)[\s\S]*?\.public-header-main\s*\{[\s\S]*?grid-template-columns:\s*minmax\(0,\s*1fr\)\s+auto/i
    );
    assert.match(
        css,
        /@media\s*\(max-width:\s*760px\)[\s\S]*?\.public-header-action\s*\{[^{}]*width:\s*44px[^{}]*min-width:\s*44px[^{}]*height:\s*44px/is
    );
    assert.match(
        css,
        /@media\s*\(max-width:\s*760px\)[\s\S]*?\.public-header-logo\s*\{[^{}]*width:\s*142px/is
    );
    assert.match(
        css,
        /@media\s*\(max-width:\s*400px\)[\s\S]*?\.public-header-logo\s*\{[^{}]*width:\s*112px/is
    );
    assert.match(
        css,
        /@media\s*\(max-width:\s*400px\)[\s\S]*?\.public-header-actions\s*\{[^{}]*display:\s*grid[^{}]*grid-template-columns:\s*repeat\(2,\s*44px\)[^{}]*gap:\s*4px/is
    );
    assert.match(
        css,
        /\.public-header\s+\.site-search-form\s*\{[^{}]*min-width:\s*0[^{}]*max-width:\s*100%/is
    );

    [320, 360, 375, 390, 412, 430].forEach(function (width) {
        const compact = width <= 400;
        const shellWidth = width - (compact ? 14 : 20);
        const brandWidth = compact
            ? 112 + 1 + 44
            : 142 + 4 + 44;
        const fourActionsWidth = compact
            ? (2 * 44) + 4
            : (4 * 44) + (3 * 2);
        const usedWidth = brandWidth + fourActionsWidth + 8;

        assert.ok(width <= 760, `${width}px must use the bounded mobile grid`);
        assert.ok(
            usedWidth <= shellWidth,
            `${width}px header exceeds its ${shellWidth}px shell budget`
        );
    });
});

test('adult status badges use the Anabelka palette without mobile overflow', function () {
    const css = read('css/admin-categories.css');
    const view = read('views/admin/categories/index.php');
    const baseRule = cssRuleBody(css, '.category-status');
    const adultRule = cssRuleBody(css, '.category-status.is-adult');
    const statusContainerRules = Array.from(
        css.matchAll(/([^{}]+)\{([^{}]*)\}/g)
    ).filter(function (match) {
        return match[1]
            .split(',')
            .map((selector) => selector.trim())
            .includes('.category-statuses');
    });

    assert.match(adultRule, /background:\s*#8a2be2/i);
    assert.match(adultRule, /color:\s*#(?:fff|ffffff)/i);
    assert.doesNotMatch(adultRule, /#302437/i);

    const inheritedRule = cssRuleBody(
        css,
        '.category-status.is-adult.is-inherited'
    );

    assert.match(inheritedRule, /background:\s*#f4eaff/i);
    assert.match(inheritedRule, /color:\s*#6519b9/i);
    assert.match(inheritedRule, /border:\s*1px\s+solid\s+#8a2be2/i);
    assert.doesNotMatch(inheritedRule, /#302437/i);

    assert.match(baseRule, /display:\s*inline-flex/i);
    assert.match(baseRule, /white-space:\s*nowrap/i);
    assert.match(adultRule, /box-sizing:\s*border-box/i);
    assert.match(adultRule, /max-width:\s*100%/i);
    assert.ok(
        statusContainerRules.length > 0,
        'CSS rule for .category-statuses was not found'
    );
    assert.match(
        statusContainerRules.map((rule) => rule[2]).join('\n'),
        /flex-wrap:\s*wrap/i
    );

    assert.match(
        view,
        /category-status is-adult<\?=\s*empty\(\$category\['is_adult'\]\)\s*\?\s*' is-inherited'\s*:\s*''\s*\?>/
    );
    assert.match(
        view,
        /18\+<\?=\s*empty\(\$category\['is_adult'\]\)\s*\?\s*' успадковано'\s*:\s*''\s*\?>/
    );
    assert.match(view, /css\/admin-categories\.css\?v=2/);
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

test('category success message survives reload through session storage', function () {
    const storage = new Map();
    const firstPage = flashPage(storage, null);

    firstPage.window.AdminFlashMessage.storeSuccess(
        'Категорію та переклади збережено.'
    );

    assert.equal(storage.size, 1);

    const classes = new Set();
    const messageElement = {
        textContent: '',
        classList: {
            add(name) {
                classes.add(name);
            },
            remove(name) {
                classes.delete(name);
            },
            toggle(name, enabled) {
                if (enabled) {
                    classes.add(name);
                } else {
                    classes.delete(name);
                }
            }
        }
    };
    const reloadedPage = flashPage(storage, messageElement);

    assert.equal(storage.size, 0);
    assert.equal(
        messageElement.textContent,
        'Категорію та переклади збережено.'
    );
    assert.ok(classes.has('show'));
    assert.ok(!classes.has('is-error'));
    assert.equal(reloadedPage.timers.at(-1)?.delay, 2800);

    reloadedPage.window.AdminFlashMessage.show(
        'Операцію не виконано.',
        true
    );

    assert.ok(classes.has('is-error'));
    assert.equal(reloadedPage.timers.at(-1)?.delay, 4800);
    assert.ok(
        reloadedPage.timers.at(-1).delay
            > reloadedPage.timers.at(-2).delay
    );
});

test('category mutations store success before immediate reload or replace', function () {
    const script = read('js/admin-categories.js');

    assert.match(
        script,
        /const result = await request\(form\.action, new FormData\(form\)\);\s*storeSuccessMessage\(result\.message \|\| 'Збережено\.'\);\s*closeModals\(false\);\s*if \(returnUrl\) \{\s*window\.location\.replace\(returnUrl\);\s*\} else \{\s*window\.location\.reload\(\);\s*\}/
    );
    assert.match(
        script,
        /const result = await request\(url, data\);\s*storeSuccessMessage\(result\.message \|\| 'Збережено\.'\);\s*window\.location\.reload\(\);/
    );
    assert.doesNotMatch(script, /\},\s*(?:250|300)\s*\);/);
    assert.doesNotMatch(
        script,
        /showMessage\(result\.message \|\| 'Збережено\.', false\)/
    );

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

test('category flash is available after manager reload and translation return', function () {
    const categoryView = read('views/admin/categories/index.php');
    const missingTranslationsView = read('views/admin/translations/missing.php');

    [categoryView, missingTranslationsView].forEach(function (view) {
        assert.match(view, /css\/admin-flash-message\.css\?v=1/);
        assert.match(view, /id="site-message"[^>]*aria-live="polite"/);
        assert.match(view, /js\/admin-flash-message\.js\?v=1/);
    });
    assert.ok(
        categoryView.indexOf('js/admin-flash-message.js?v=1')
            < categoryView.indexOf('js/admin-categories.js?v=2')
    );
});

test('category flash geometry is centered and safe on 320px screens', function () {
    const css = read('css/admin-flash-message.css');
    const baseRule = cssRuleBody(css, '.site-message');
    const errorRule = cssRuleBody(css, '.site-message.is-error');

    assert.match(baseRule, /position:\s*fixed/i);
    assert.match(baseRule, /top:\s*50%/i);
    assert.match(baseRule, /left:\s*50%/i);
    assert.doesNotMatch(baseRule, /(?:^|;)\s*(?:right|bottom)\s*:/i);
    assert.match(baseRule, /width:\s*max-content/i);
    assert.match(
        baseRule,
        /max-width:\s*min\(360px,\s*calc\(100vw\s*-\s*24px\)\)/i
    );
    assert.match(baseRule, /box-sizing:\s*border-box/i);
    assert.match(baseRule, /overflow-wrap:\s*anywhere/i);
    assert.match(baseRule, /background:\s*#fffaf7/i);
    assert.match(baseRule, /border[^;]*#5f9b68/i);
    assert.match(errorRule, /background:\s*#fff7f5/i);
    assert.match(errorRule, /border[^;]*#c45757/i);
});

test('category page renders subcategories and direct products together', function () {
    const rendered = renderCategoryBranches(true, true);

    assert.equal(rendered.subcategories, true);
    assert.equal(rendered.products, true);
    assert.equal(rendered.empty, false);
    assert.ok(
        rendered.output.indexOf('class="catalog-categories"')
            < rendered.output.indexOf('class="catalog-products"')
    );
});

test('category page renders only subcategories without direct products', function () {
    const rendered = renderCategoryBranches(true, false);

    assert.equal(rendered.subcategories, true);
    assert.equal(rendered.products, false);
    assert.equal(rendered.empty, false);
});

test('category page renders only direct products without subcategories', function () {
    const rendered = renderCategoryBranches(false, true);

    assert.equal(rendered.subcategories, false);
    assert.equal(rendered.products, true);
    assert.equal(rendered.empty, false);
});

test('category page renders empty message only when both lists are empty', function () {
    const rendered = renderCategoryBranches(false, false);

    assert.equal(rendered.subcategories, false);
    assert.equal(rendered.products, false);
    assert.equal(rendered.empty, true);
});

test('category products are not an elseif branch of subcategories', function () {
    const source = read('views/catalog/category.php');

    assert.doesNotMatch(
        source,
        /<\?php\s+elseif\s*\(\s*!empty\(\$products\)\s*\)\s*:\s*\?>/
    );
});

process.stdout.write('category manager contract checks passed\n');
