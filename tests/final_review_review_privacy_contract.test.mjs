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

const reviewModel = read('app/Models/ProductReview.php');
const adminReviewView = read('views/admin/reviews/index.php');
const adminAccess = read('app/Models/AdminAccess.php');

assert.match(
    adminAccess,
    /'content_manager'\s*=>\s*\[[\s\S]*?'reviews\.view'[\s\S]*?'reviews\.manage'[\s\S]*?\]/,
    'content manager must keep review moderation access'
);
assert.doesNotMatch(
    reviewModel,
    /u\.email\s+AS\s+customer_email/i,
    'review moderation query must not expose customer email unnecessarily'
);
assert.doesNotMatch(
    adminReviewView,
    /customer_email/,
    'review moderation UI must not render customer email'
);
assert.match(
    adminReviewView,
    /customer_name/,
    'the agreed customer display name remains visible for moderation'
);

process.stdout.write('review moderation privacy contract passed\n');
