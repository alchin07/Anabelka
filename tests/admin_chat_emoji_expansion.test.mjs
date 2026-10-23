import {test} from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import {fileURLToPath} from 'node:url';

const dir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const source = fs.readFileSync(path.join(dir,'js/admin-chat-emoji.js'),'utf8');
const sandbox = {window:{}};
vm.runInNewContext(source,sandbox,{timeout:3000});
const pictures = sandbox.window.AnabelkaChatClassicData;
const codes = ["shy","crying","sleepy","scared","party","confused","proud","tired","sick","cool","hug","sly"];
const names = {"shy":"Соромлюся","crying":"Ридаю","sleepy":"Позіхаю","scared":"Страшно","party":"Свято","confused":"Не розумію","proud":"Пишаюся","tired":"Втомився","sick":"Захворів","cool":"Крутий","hug":"Обіймаю","sly":"Хитрую"};

test('approved second Anabelka set maps 12 codes only to static PNG filenames', () => {
    assert.equal(codes.length,12);
    assert.equal(new Set(codes).size,12);
    for (const code of codes) {
        assert.equal(pictures.files[code],code+'.png');
        assert.equal(pictures.labels[code],names[code]);
        assert.match(pictures.files[code],/^[A-Za-z0-9_-]+\.png$/);
    }
});

test('the original illustrated gallery and Unicode fallback remain available', () => {
    for (const code of ['smiley','laugh','wink','inlove','mwah',
        'surprised','sad','angry','thinking','like','!','unlike']) {
        assert.ok(Object.hasOwn(pictures.files,code),code);
    }
    assert.ok(pictures.categories.some(c=>c.id==='classic'));
    assert.ok(sandbox.window.AnabelkaChatEmojiData.categories.length>1);
    assert.ok(fs.readFileSync(path.join(dir,'.gitignore'),'utf8')
        .includes('assets/chat-emoji/graphics/'));
});

test('the dedicated new set preserves all twelve codes in approved order', () => {
    const target = pictures.categories.find(c=>c.id==='classic-new');
    assert.ok(target);
    assert.deepEqual(Array.from(target.codes),codes);
    assert.ok(target.label.includes('Нові'));
    const view=fs.readFileSync(path.join(dir,'views/admin/chat/index.php'),'utf8');
    assert.ok(view.includes('/Anabelka/js/admin-chat-emoji.js?v=3'));
    assert.ok(view.includes('/Anabelka/js/admin-chat.js?v=6'));
});
