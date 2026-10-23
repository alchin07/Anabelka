import {test} from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import {fileURLToPath} from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = p => fs.readFileSync(path.join(root, p), 'utf8');
const source = read('js/admin-chat-photo-viewer.js');
const view = read('views/admin/chat/index.php');
const css = read('css/admin-chat.css');

function getGallery(links) {
    const begin = source.indexOf('    function galleryFromThread() {');
    const end = source.indexOf('\n    function resetPointers()', begin);
    assert.ok(begin > 0 && end > begin, 'Gallery builder is present');
    const code = source.slice(begin, end);
    const context = vm.createContext({
        thread: {querySelectorAll() { return links; }},
        window: {location: {href: 'http://127.0.0.1:8000/Anabelka-chat-test/admin/chat',
                            origin: 'http://127.0.0.1:8000'}},
        URL
    });
    return new vm.Script(code + '\ngalleryFromThread();').runInContext(context);
}

const fakeLink = (href, name = 'Фото.jpg') => ({
    getAttribute(key) { return key === 'href' ? href : null; },
    querySelector(key) { return key === 'img' ? {alt: name} : null; }
});

test('gallery stays within loaded thread and server-authorized same-origin attachment route', () => {
    const good1 = fakeLink('/Anabelka-chat-test/admin/chat/file?id=10', 'photo one.jpg');
    const good2 = fakeLink('/Anabelka-chat-test/admin/chat/file?id=22', 'другий.png');
    const collected = getGallery([
        good1,
        fakeLink('https://example.org/Anabelka/admin/chat/file?id=3'),
        fakeLink('/Anabelka-chat-test/admin/chat/file?id=0'),
        fakeLink('/Anabelka-chat-test/admin/chat/file?id=../../secret'),
        fakeLink('/Anabelka-chat-test/storage/admin-chat/file?id=44'),
        good2
    ]);
    assert.equal(collected.length, 2);
    assert.equal(collected[0].link, good1);
    assert.equal(collected[0].name, 'photo one.jpg');
    assert.equal(collected[1].name, 'другий.png');
    assert.ok(collected.every(x => x.url.startsWith('http://127.0.0.1:8000/Anabelka-chat-test/admin/chat/file?id=')));
});

test('markup and cache busting activate modal over chat without external resources', () => {
    for (const id of ['chat-photo-viewer', 'chat-photo-stage', 'chat-photo-image',
        'chat-photo-prev', 'chat-photo-next', 'chat-photo-close', 'chat-photo-save',
        'chat-photo-zoom-in', 'chat-photo-zoom-out']) {
        assert.match(view, new RegExp('id="' + id + '"'));
    }
    assert.match(view, /id="chat-photo-viewer"[^>]*role="dialog"[^>]*aria-modal="true"/);
    assert.match(view, /admin-chat-photo-viewer\.js\?v=1/);
    assert.match(view, /admin-chat\.js\?v=4/);
    assert.match(view, /admin-chat\.css\?v=4/);
    assert.match(css, /\.chat-photo-viewer\[hidden\]\s*\{\s*display:none/);
    assert.match(css, /\.chat-photo-stage\s*\{[^}]*touch-action:none/);
});

test('viewer intercepts photo link only; supports zoom, swipe, Back, and keyboard closing', () => {
    assert.match(source, /thread\.addEventListener\('click'/);
    assert.match(source, /event\.preventDefault\(\);\s*openViewer\(target\)/);
    assert.match(source, /history\.pushState/);
    assert.match(source, /function consumePopState\(\)/);
    assert.match(source, /stage\.addEventListener\('pointerdown'/);
    assert.match(source, /stage\.addEventListener\('pointermove'/);
    assert.match(source, /photo\.pinch\.scale \* distance \/ photo\.pinch\.distance/);
    assert.match(source, /Math\.abs\(dx\) > 65/);
    assert.match(source, /event\.key === 'Escape'/);
    assert.match(source, /save\.download = item\.name/);
    assert.doesNotMatch(source, /\binnerHTML\s*=/);
    const chat = read('js/admin-chat.js');
    assert.match(chat, /window\.AnabelkaChatPhotoViewer\.consumePopState\(\)/);
    const controller = read('app/Controllers/AdminChatController.php');
    assert.match(controller, /AdminChat::file\(/);
    assert.match(controller, /Cache-Control: private, no-store, max-age=0/);
});
