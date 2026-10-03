import assert from 'node:assert/strict';
import fs from 'node:fs';

const controller = fs.readFileSync(
    'app/Controllers/AdminBackupController.php',
    'utf8'
);
const app = fs.readFileSync('app/Core/App.php', 'utf8');
const routes = fs.readFileSync('routes/AdminSecurity.php', 'utf8');
const view = fs.readFileSync(
    'views/admin/system/backup.php',
    'utf8'
);
const header = fs.readFileSync(
    'views/admin/partials/header.php',
    'utf8'
);

assert.match(
    app,
    /Controllers\/AdminBackupController\.php/
);

assert.match(
    routes,
    /\/admin\/system\/backup['"][\s\S]*?AdminBackupController@index/
);
assert.match(
    routes,
    /\/admin\/system\/backup\/create['"][\s\S]*?AdminBackupController@create/
);
assert.match(
    routes,
    /\/admin\/system\/backup\/download['"][\s\S]*?AdminBackupController@download/
);

assert.match(
    controller,
    /role_slug['"]\]\s*\?\?\s*['"]['"]\)\s*!==\s*['"]owner['"]/
);
assert.match(
    controller,
    /AdminAccess::verifyCsrf\(\$_POST\['_csrf'\]/
);
assert.match(controller, /class_exists\(['"]ZipArchive['"]\)/);
assert.match(controller, /SHOW FULL TABLES/);
assert.match(controller, /SHOW CREATE TABLE/);
assert.match(controller, /SELECT \* FROM/);
assert.match(controller, /SHOW CREATE VIEW/);
assert.match(controller, /SHOW TRIGGERS/);
assert.match(controller, /backup\/database\.sql/);
assert.match(controller, /backup\/manifest\.json/);
assert.match(controller, /backup\/RESTORE\.txt/);

assert.match(
    controller,
    /storage\/full-backups/
);
assert.match(
    controller,
    /storage\/deploy-backups/
);
assert.match(
    controller,
    /Require all denied/
);
assert.match(
    controller,
    /safeBackupName/
);
assert.match(
    controller,
    /Content-Disposition:\s*attachment/
);

assert.match(
    view,
    /Створити повний ZIP \+ дамп БД/
);
assert.match(
    view,
    /admin\/system\/backup\/download\?file=/
);
assert.match(
    view,
    /OAuth-секрети й API-ключі/
);

assert.match(
    header,
    /href="\/Anabelka\/admin\/system\/backup"/
);
assert.match(
    header,
    /<span>Резервна копія<\/span>/
);

process.stdout.write('full backup contract passed\n');
