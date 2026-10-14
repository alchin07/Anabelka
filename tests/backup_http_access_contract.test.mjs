import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import {fileURLToPath} from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = name => fs.readFileSync(path.join(root, name), 'utf8');
const htaccess = read('.htaccess');
const directives = htaccess.split(/\r?\n/).map(line => line.trim());

function backupDenyRule() {
    const lines = directives.filter(line =>
        /^RewriteRule\s/.test(line)
        && line.includes('storage/')
        && line.includes('deploy-backups')
        && line.includes('full-backups')
    );

    assert.equal(lines.length, 1, 'Both backup directories must have one unambiguous deny rule');
    const parsed = lines[0].match(/^RewriteRule\s+(\S+)\s+-\s+\[([^\]]+)\]$/);
    assert.ok(parsed, 'The directive must be a valid no-substitution RewriteRule');
    const flags = parsed[2].split(',');
    assert.ok(flags.includes('F'), 'Backup URLs must return HTTP 403, not redirect');
    assert.ok(flags.includes('L'), 'The deny must stop rewrite processing');
    assert.ok(flags.includes('NC'), 'Case variations must not bypass the deny');
    return {line: lines[0], pattern: new RegExp(parsed[1], 'i')};
}

test('deny direct access to both backup folders and every nested path', () => {
    const {pattern} = backupDenyRule();
    for (const urlPath of [
        'storage/deploy-backups',
        'storage/deploy-backups/',
        'storage/deploy-backups/update.zip',
        'storage/deploy-backups/old/subfolder/backup.zip',
        'storage/full-backups',
        'storage/full-backups/',
        'storage/full-backups/Anabelka_full_sample.zip',
        'storage/FULL-BACKUPS/subfolder/database.sql'
    ]) {
        assert.match(urlPath, pattern, 'Direct HTTP access must be denied: ' + urlPath);
    }
});

test('backup guard cannot match unrelated files or protected PHP download route', () => {
    const {pattern} = backupDenyRule();
    for (const urlPath of [
        'storage/deploy-backups-old/update.zip',
        'storage/full-backups-tmp/backup.zip',
        'storage/logs/errors.log',
        'storage/image-processor/originals/image.jpg',
        'uploads/products/product.webp',
        'admin/system/backup/download',
        'catalog/lingerie',
        'index.php'
    ]) {
        assert.doesNotMatch(urlPath, pattern, 'An unrelated route must remain unaffected: ' + urlPath);
    }
});

test('deny runs before Apache serves physical files and existing routing is preserved', () => {
    const {line} = backupDenyRule();
    const denyAt = htaccess.indexOf(line);
    const fileBypassAt = htaccess.indexOf('RewriteCond %{REQUEST_FILENAME} !-f');
    const dirBypassAt = htaccess.indexOf('RewriteCond %{REQUEST_FILENAME} !-d');
    const fallbackAt = htaccess.indexOf('RewriteRule ^ index.php [L]');

    assert.ok(denyAt > htaccess.indexOf('RewriteEngine On'));
    assert.ok(fileBypassAt > denyAt, 'Existing ZIP files must not bypass the block');
    assert.ok(dirBypassAt > denyAt, 'Directory requests must not bypass the block');
    assert.ok(fallbackAt > dirBypassAt, 'The existing front-controller route must remain active');
    assert.match(htaccess, /^RewriteRule \^storage\/image-processor\/ - \[F,L\]$/m,
        'Existing image-processor privacy guard must remain intact');
});

test('backup ZIP files remain untracked in Git; Git ignore is not used as HTTP protection', () => {
    const ignore = read('.gitignore');
    assert.match(ignore, /^storage\/deploy-backups\/$/m);
    assert.match(ignore, /^storage\/full-backups\/$/m);
});


test('the complete database directory is private over HTTP, including SQL migrations', () => {
    const matches = directives.filter(line => /^RewriteRule\s/.test(line)
        && line.includes('^database'));
    assert.equal(matches.length, 1, 'A single database guard must exist');
    const parsed = matches[0].match(/^RewriteRule\s+(\S+)\s+-\s+\[([^\]]+)\]$/);
    assert.ok(parsed, 'The directive must not rewrite SQL requests to index.php');
    const flags = parsed[2].split(',');
    assert.ok(flags.includes('F'), 'Apache must return HTTP 403');
    assert.ok(flags.includes('L'), 'The SQL download must stop rewriting');
    assert.ok(flags.includes('NC'), 'Case variations must be blocked');
    const pattern = new RegExp(parsed[1], 'i');

    for (const name of [
        'database',
        'database/',
        'database/migrations/2026-09-13_category_manager.sql',
        'database/preflight/check.sql',
        'database/postflight/check.sql',
        'DATABASE/migrations/secret.sql',
        'database/dumps/production.sql'
    ]) {
        assert.match(name, pattern, 'Database path must be private: ' + name);
    }

    for (const name of [
        'database-public/guide.txt',
        'databases/schema.sql',
        'assets/style.css',
        'admin/system/backup/download',
        'catalog',
        'index.php',
        'storage/deploy-backups/backup.zip'
    ]) {
        assert.doesNotMatch(name, pattern, 'A non-database path must not be blocked: ' + name);
    }

    const blockAt = htaccess.indexOf(matches[0]);
    const existingFilesAt = htaccess.indexOf('RewriteCond %{REQUEST_FILENAME} !-f');
    const fallbackAt = htaccess.indexOf('RewriteRule ^ index.php [L]');
    assert.ok(blockAt >= 0 && existingFilesAt > blockAt,
        'The guard must run before Apache serves an existing SQL file');
    assert.ok(fallbackAt > existingFilesAt, 'The normal site router must remain active');
});
