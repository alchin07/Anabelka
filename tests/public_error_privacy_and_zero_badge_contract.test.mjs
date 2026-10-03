import assert from 'node:assert/strict';
import fs from 'node:fs';

const csrf = fs.readFileSync(
    'app/Core/Csrf.php',
    'utf8'
);
const errorHandler = fs.readFileSync(
    'app/Core/ErrorHandler.php',
    'utf8'
);
const renderer = fs.readFileSync(
    'app/Core/PublicErrorPage.php',
    'utf8'
);
const publicView = fs.readFileSync(
    'views/errors/public.php',
    'utf8'
);
const adminLayout = fs.readFileSync(
    'css/admin-layout.css',
    'utf8'
);
const adminHeader = fs.readFileSync(
    'views/admin/partials/header.php',
    'utf8'
);
const router = fs.readFileSync(
    'app/Core/Router.php',
    'utf8'
);

assert.match(
    csrf,
    /PublicErrorPage::renderGeneric\(403\)/
);
assert.doesNotMatch(
    csrf,
    /CSRF token is missing or invalid|Сесію форми застаріло|CSRF-токен недійсний/
);
assert.match(
    csrf,
    /Не вдалося виконати дію\. Спробуйте ще раз\./
);

const respondStart = errorHandler.indexOf(
    'private static function respondSafely'
);
const respondEnd = errorHandler.indexOf(
    'private static function expectsJson',
    respondStart
);
assert.ok(respondStart >= 0 && respondEnd > respondStart);
const respondBlock = errorHandler.slice(respondStart, respondEnd);

assert.match(
    respondBlock,
    /PublicErrorPage::renderGeneric\(500\)/
);
assert.doesNotMatch(
    respondBlock,
    /Код помилки|ERR-|['"]reference['"]\s*=>/
);

assert.match(
    renderer,
    /public static function renderGeneric\(\$statusCode = 500\)/
);
assert.match(
    renderer,
    /'Щось пішло не так'[\s\S]*?'Спробуйте ще раз\.'/
);

assert.doesNotMatch(publicView, /\$errorCode/);
assert.doesNotMatch(publicView, /public-error-code/);
assert.doesNotMatch(publicView, /Код помилки|ERR-|403|500/);
assert.match(publicView, /Щось пішло не так|\$errorTitle/);
assert.match(publicView, /На головну/);

assert.match(
    errorHandler,
    /return 'ERR-' \. date/
);

assert.match(
    adminLayout,
    /\.admin-nav-badge\[hidden\]\s*\{[^}]*display:\s*none\s*!important/
);
assert.match(
    adminHeader,
    /admin-layout\.css\?v=4/
);

assert.match(
    router,
    /private function forbidAdminAccess\(\)[\s\S]*?PublicErrorPage::renderGeneric\(403\)/
);

process.stdout.write(
    'privacy-safe public errors and zero-badge contract passed\n'
);
