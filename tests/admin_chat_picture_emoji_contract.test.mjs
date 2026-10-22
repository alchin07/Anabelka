import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = (pathName) => fs.readFileSync(path.join(root, pathName), 'utf8');

test('Image pack is strictly optional and stays out of Git', () => {
    const js = read('js/admin-chat.js');
    assert.match(js, /pictureAvailable\s*=\s*false/);
    assert.match(js, /probe\.onerror/);
    assert.match(js, /return pictureAvailable\s*\?/);
    assert.ok(read('.gitignore').includes('assets/chat-emoji/graphics/'));
    assert.match(read('assets/chat-emoji/README.md'), /Viber/);
});

test('Untrusted message text never becomes executable HTML', () => {
    const js = read('js/admin-chat.js');
    assert.match(js, /appendFormattedMessage\(messageBody/);
    assert.match(js, /document\.createTextNode\(/);
    assert.match(js, /pictureCodes\.has\(code\)/);
    assert.match(js, /\^\[A-Za-z0-9_-\]/);
    assert.doesNotMatch(js, /\.innerHTML\s*=|eval\(/);
});

test('Picture pack has 213 filename slots, no actual PNGs committed', () => {
    const emoji = read('js/admin-chat-emoji.js');
    assert.ok(emoji.includes('window.AnabelkaChatClassicData'));
    assert.ok(emoji.includes('smiley.png'));
    assert.ok(emoji.includes('batman.png'));
    assert.ok(emoji.includes('spiderman.png'));
    const c=read('views/admin/chat/index.php');
    assert.ok(c.includes('data-emoji-graphics='));
    assert.ok(read('css/admin-chat.css').includes('repeat(6, minmax(0, 1fr))'));
});

test('Earlier Unicode picker stays available without graphics', () => {
    const js = read('js/admin-chat.js');
    assert.match(js, /:\s*emojiData\.categories/);
    assert.ok(js.includes('emojiInsert(token)'));
    assert.ok(read('js/admin-chat-emoji.js').includes('AnabelkaChatEmojiData'));
});
