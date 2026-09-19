import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const testDirectory = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(testDirectory, '..');
const read = relativePath => fs.readFileSync(
    path.join(projectRoot, relativePath),
    'utf8'
);

function methodBody(source, methodName)
{
    const expression = new RegExp(
        'public\\s+function\\s+' + methodName
        + '\\s*\\([^)]*\\)\\s*\\{([\\s\\S]*?)'
        + '(?=\\n\\s*public\\s+function|\\n\\s*private\\s+function|\\n})'
    );
    const match = source.match(expression);

    assert.ok(match, `${methodName} method must exist`);
    return match[1];
}

function mediaBlock(css, minWidth)
{
    const marker = new RegExp(
        '@media\\s*\\(min-width:\\s*' + minWidth + 'px\\)\\s*\\{',
        'i'
    );
    const match = marker.exec(css);

    assert.ok(match, `@media (min-width: ${minWidth}px) must exist`);

    const start = match.index + match[0].length;
    let depth = 1;

    for (let index = start; index < css.length; index += 1) {
        if (css[index] === '{') {
            depth += 1;
        } else if (css[index] === '}') {
            depth -= 1;

            if (depth === 0) {
                return css.slice(start, index);
            }
        }
    }

    assert.fail(`@media (min-width: ${minWidth}px) is not closed`);
}

const newsController = read('app/Controllers/AdminNewsController.php');
const update = methodBody(newsController, 'update');

assert.match(
    update,
    /\$activeLanguages\s*=\s*Language::active\s*\(\s*\)\s*;/,
    'active languages must be prepared before the news transaction'
);
assert.match(update, /\$db\s*=\s*Database::connect\s*\(\s*\)\s*;/);
assert.match(update, /\$db->beginTransaction\s*\(\s*\)\s*;/);
assert.match(update, /SiteNews::update\s*\(/);
assert.match(
    update,
    /foreach\s*\(\s*\$activeLanguages\s+as\s+\$language\s*\)/,
    'the preloaded language list must be reused inside the transaction'
);
assert.match(update, /SiteNewsTranslator::save\s*\(/);
assert.match(update, /\$db->commit\s*\(\s*\)\s*;/);
assert.match(
    update,
    /if\s*\(\s*\$db->inTransaction\s*\(\s*\)\s*\)\s*\{\s*\$db->rollBack\s*\(\s*\)/s,
    'failed source/translation save must roll the whole news edit back'
);
assert.ok(
    update.indexOf('$db->beginTransaction()')
        < update.indexOf('SiteNews::update('),
    'transaction must start before the source news update'
);
assert.ok(
    update.indexOf('$db->commit()')
        < update.indexOf("AdminAccess::audit('news.update'"),
    'audit/success feedback must happen only after the transaction commits'
);

const railCss = read('css/home-right-rail.css');
const desktopRail = mediaBlock(railCss, 1250);

assert.match(
    desktopRail,
    /\.home-direction-grid\s*,\s*\.home-product-grid\s*\{[^{}]*grid-template-columns:\s*repeat\(auto-fill,\s*minmax\(190px,\s*1fr\)\)/s,
    'home product/direction grids must adapt when the 280px right rail appears'
);

process.stdout.write('final review late modules contract passed\n');
