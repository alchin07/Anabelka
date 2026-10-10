import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const projectRoot = process.cwd();

function walk(dir) {
    const result = [];

    for (const entry of fs.readdirSync(dir, {withFileTypes: true})) {
        const fullPath = path.join(dir, entry.name);

        if (entry.isDirectory()) {
            result.push(...walk(fullPath));
            continue;
        }

        if (entry.isFile() && entry.name.endsWith('.php')) {
            result.push(fullPath);
        }
    }

    return result;
}

const auditView = fs.readFileSync(
    path.join(projectRoot, 'views/admin/administrators/audit.php'),
    'utf8'
);

const labelsStart = auditView.indexOf('$actionLabels = [');
const labelsEnd = auditView.indexOf('];\n\n$detailLabels', labelsStart);

assert.ok(labelsStart >= 0 && labelsEnd > labelsStart);

const labelsBlock = auditView.slice(labelsStart, labelsEnd);
const labelKeys = new Set();
const labelRegex = /['"]([^'"]+)['"]\s*=>\s*['"][^'"]*['"]/g;
let match;

while ((match = labelRegex.exec(labelsBlock)) !== null) {
    labelKeys.add(match[1]);
}

const actions = new Set();

for (const file of walk(path.join(projectRoot, 'app'))) {
    const source = fs.readFileSync(file, 'utf8');

    for (const regex of [
        /AdminAccess::audit\(\s*['"]([^'"]+)['"]/g,
        /self::audit\(\s*['"]([^'"]+)['"]/g,
        /\$this->audit\(\s*['"]([^'"]+)['"]/g
    ]) {
        let actionMatch;
        while ((actionMatch = regex.exec(source)) !== null) {
            actions.add(actionMatch[1]);
        }
    }
}

const adminActionAudit = fs.readFileSync(
    path.join(projectRoot, 'app/Models/AdminActionAudit.php'),
    'utf8'
);
const specRegex = /['"]action['"]\s*=>\s*['"]([^'"]+)['"]/g;

while ((match = specRegex.exec(adminActionAudit)) !== null) {
    actions.add(match[1]);
}

const missing = [...actions]
    .filter((action) => !labelKeys.has(action))
    .sort();

assert.deepEqual(
    missing,
    [],
    'Audit actions without human-readable labels: ' + missing.join(', ')
);

assert.match(
    auditView,
    /'dashboard\.builder\.link\.update'\s*=>\s*'Оновлено ярлик адмін-главної'/
);
assert.match(
    auditView,
    /'dashboard\.builder\.link\.toggle'\s*=>\s*'Змінено активність ярлика адмін-главної'/
);
assert.match(
    auditView,
    /'dashboard\.builder\.block\.create'\s*=>\s*'Створено блок адмін-главної'/
);
assert.match(
    auditView,
    /'link_id'\s*=>\s*'ID ярлика'/
);
assert.match(
    auditView,
    /'service_key'\s*=>\s*'Служба'/
);

const css = fs.readFileSync(
    path.join(projectRoot, 'css/admin-administrators.css'),
    'utf8'
);

assert.match(
    css,
    /\.admin-audit-group-head\s*>\s*div\s*>\s*span/
);
assert.match(
    css,
    /\.admin-audit-group-head\s+\.admin-audit-new-badge\s*\{[^}]*color:\s*#fff/
);

process.stdout.write(
    'admin audit human-readable labels contract passed\n'
);
