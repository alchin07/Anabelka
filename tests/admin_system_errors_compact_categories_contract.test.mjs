import assert from 'node:assert/strict';
import fs from 'node:fs';

const model = fs.readFileSync(
    'app/Models/SystemErrorLog.php',
    'utf8'
);
const controller = fs.readFileSync(
    'app/Controllers/AdminSystemErrorController.php',
    'utf8'
);
const view = fs.readFileSync(
    'views/admin/system/errors.php',
    'utf8'
);
const css = fs.readFileSync(
    'css/admin-system-errors.css',
    'utf8'
);

assert.match(
    model,
    /public static function categoryForItem\(array \$item\)/
);
assert.match(model, /'key' => 'database'[\s\S]*?'label' => 'База даних'/);
assert.match(model, /'key' => 'security'[\s\S]*?'label' => 'Авторизація та доступ'/);
assert.match(model, /'key' => 'external'[\s\S]*?'label' => 'Зовнішні сервіси'/);
assert.match(model, /'key' => 'files'[\s\S]*?'label' => 'Файли та зображення'/);
assert.match(model, /'key' => 'http'[\s\S]*?'label' => 'Маршрути та HTTP'/);
assert.match(model, /'key' => 'php'[\s\S]*?'label' => 'PHP та код'/);
assert.match(
    model,
    /public static function categorizeItems\(array \$items\)/
);

assert.match(
    controller,
    /array_slice\(\$items, 0, 3\)[\s\S]*?\$recentItems/
);
assert.match(
    controller,
    /SystemErrorLog::categorizeItems\([\s\S]*?array_slice\(\$items, 3\)/
);

assert.match(view, /<details[\s\S]*?class="system-error-card/);
assert.match(view, /\$renderErrorCard\(\$item, true\)/);
assert.match(view, /\$renderErrorCard\(\$item, false\)/);
assert.match(view, /3 найсвіжіші помилки/);
assert.match(view, /За характером помилки/);
assert.match(view, /system-error-category-badge/);

assert.match(css, /\.system-error-compact-summary/);
assert.match(css, /details\.system-error-card\[open\]/);
assert.match(css, /\.system-error-category-group/);

process.stdout.write(
    'compact categorized system errors contract passed\n'
);
