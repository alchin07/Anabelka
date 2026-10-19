import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const model = fs.readFileSync('app/Models/AdminProduct.php', 'utf8');
const controller = fs.readFileSync('app/Controllers/AdminProductController.php', 'utf8');
const view = fs.readFileSync('views/admin/products/index.php', 'utf8');
const partial = fs.readFileSync('views/partials/pagination.php', 'utf8');
const styles = fs.readFileSync('css/catalog.css', 'utf8');

test('admin products use bounded SQL pagination with stable sorting', () => {
    assert.match(controller, /\$perPage\s*=\s*20;/);
    assert.match(controller, /AdminProduct::countFiltered\(\$filters\)/);
    assert.match(controller, /\$page\s*=\s*min\(\$page,\s*\$totalPages\)/);
    assert.match(controller, /AdminProduct::page\(\$filters,\s*\$page,\s*\$perPage\)/);
    assert.match(model, /LIMIT :limit OFFSET :offset/);
    assert.match(model, /bindValue\(':limit',\s*\$perPage,\s*PDO::PARAM_INT\)/);
    assert.match(model, /bindValue\(':offset',\s*\$offset,\s*PDO::PARAM_INT\)/);
    assert.match(model, /p\.is_active DESC,[\s\S]*COALESCE\(c\.name, ''\),[\s\S]*p\.name,[\s\S]*p\.id/);
    assert.match(model, /return self::all\(\$filters,\s*\$page,\s*\$perPage\)/);
});

test('count and page use the identical filter conditions', () => {
    assert.match(model, /public static function countFiltered[\s\S]*self::filteredWhere\(\$filters,\s*\$params\)/);
    assert.match(model, /public static function all[\s\S]*self::filteredWhere\(\$filters,\s*\$params\)/);
    for (const filter of ["'active'", "'hidden'", "'out_of_stock'", "'category_id'", "'q'"]) {
        assert.ok(model.includes(filter), filter);
    }
    assert.match(model, /FROM products AS p[\s\S]*LEFT JOIN categories AS c/);
});

test('direct links reveal products on any page', () => {
    assert.match(controller, /AdminProduct::pageForProduct\(\s*\$filters,\s*\$highlight,\s*\$perPage/);
    assert.match(model, /return intdiv\(\$position,\s*\$perPage\) \+ 1/);
    assert.match(controller, /\$page\s*=\s*\$targetPage/);
});

test('filters and post actions preserve navigation', () => {
    assert.match(view, /\$paginationQuery\['status'\]/);
    assert.match(view, /\$paginationQuery\['category_id'\]/);
    assert.match(view, /\$paginationQuery\['q'\]/);
    assert.equal((view.match(/name="filter_page"/g) || []).length, 2);
    assert.equal((controller.match(/\$_POST\['filter_page'\]/g) || []).length, 2);
    assert.match(controller, /\$query\['page'\] = \(int\) \$page/);
    assert.match(controller, /\$query\['highlight'\] = \(int\) \$highlight/);
});

test('admin reuses the established purple numbered pagination', () => {
    assert.match(view, /require __DIR__ \. '\/\.\.\/\.\.\/partials\/pagination\.php';/);
    assert.match(partial, /aria-current="page"/);
    assert.match(partial, /rel="prev"/);
    assert.match(partial, /rel="next"/);
    assert.match(styles, /\.anabelka-pagination-link\.is-current\s*\{[^}]*background:\s*#8A2BE2/i);
    assert.match(styles, /\.anabelka-pagination-link\s*\{[^}]*min-width:\s*44px/i);
});
