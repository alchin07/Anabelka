import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = p => fs.readFileSync(path.join(root, p), 'utf8');
const source = read('js/admin-chat.js');

function makeClassifier(allowedCodes = []) {
    const start = source.indexOf('    function standaloneEmojiTokens(');
    const end = source.indexOf('\n    function renderMessage(', start);
    assert.ok(start > 0 && end > start, 'Function is located before message renderer');
    const snippet = source.slice(start, end);
    const context = vm.createContext({
        Intl,
        allowedCodes: new Set(allowedCodes),
    });
    const program = new vm.Script(
        'const pictureToken = token => {'
        + 'const m = /^\\(([A-Za-z0-9_$!]{1,40})\\)$/.exec(token);'
        + 'return m && allowedCodes.has(m[1]) ? m[1] : "";'
        + '};\n'
        + snippet + '\nstandaloneEmojiTokens;'
    );
    return program.runInContext(context);
}

test('one installed picture token renders standalone; unknown token stays text', () => {
    const classify = makeClassifier(['smiley', 'laugh']);
    assert.equal(classify('(smiley)').length, 1);
    assert.equal(classify('(smiley)(laugh)').length, 2);
    assert.equal(classify(' (smiley) \n (laugh) ').length, 2);
    assert.equal(classify('(unknown)').length, 0);
    assert.equal(classify('(smiley)hello').length, 0);
    assert.equal(classify('hello (smiley)').length, 0);
});
test('one, two, three unicode emoji are standalone without splitting graphemes', () => {
    const classify = makeClassifier();
    assert.equal(classify('😀').length, 1);
    assert.equal(classify('❤️❤️').length, 2);
    assert.equal(classify('👍🏽 🧑‍💻 🇺🇦').length, 3);
    assert.equal(classify('😀 😀 😀 😀').length, 0);
    assert.equal(classify('❤️ hello').length, 0);
});
test('emoji count and attachment checks are explicit in the message renderer', () => {
    assert.match(source, /!deleted && !attachments\.length/);
    assert.match(source, /row\.classList\.add\('is-emoji-only', 'is-emoji-count-'/);
    assert.match(source, /pictureImage\(emoji\.value, 'chat-message-emoji'\)/);
    assert.match(source, /chat-message-native-emoji/);
    assert.match(source, /appendFormattedMessage\(messageBody, entry\.body/);
    assert.doesNotMatch(source, /innerHTML\s*=/);
});
test('message bubbles are borderless; standalone emoji have transparent backgrounds', () => {
    const css = read('css/admin-chat.css');
    const view = read('views/admin/chat/index.php');
    assert.match(css, /\.chat-bubble \{[^}]*border:0/);
    assert.match(css, /\.chat-message-row\.is-mine \.chat-bubble \{[^}]*border:0/);
    assert.match(css, /\.chat-message-row\.is-emoji-only[^}]*\.chat-bubble \{[^}]*background:transparent/);
    assert.match(css, /\.chat-message-row\.is-emoji-only \.chat-message-emoji \{[^}]*width:112px/);
    assert.match(css, /@media \(max-width:730px\)[\s\S]*width:104px/);
    assert.ok(view.includes('admin-chat.css?v=4'));
    assert.ok(view.includes('admin-chat.js?v=4'));
});
