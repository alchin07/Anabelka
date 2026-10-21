import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';
import test, {before, after} from 'node:test';

const require = createRequire(import.meta.url);
const runtime = process.env.ANABELKA_UI_TEST_DEPS || process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES;
const playwright = runtime ? createRequire(path.resolve(runtime, 'package.json'))('playwright') : require('playwright');
const repo = fileURLToPath(new URL('../', import.meta.url));
let browser, server, origin;
const fixture = `<!doctype html><html lang="uk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="/css/admin-mask-editor.css"><style>body{font-family:Arial,Helvetica,sans-serif}</style></head><body><button id="underlying">Preview</button><div id="site-message"></div><script src="/js/anabelka-notify.js"></script><script src="/js/admin-mask-editor.js"></script><script>window.calls=[];window.compared=[];window.closeEvents=[];window.addEventListener('popstate',()=>AnabelkaMaskEditor.handleBack());window.openMask=()=>{history.pushState({mask:true},'');AnabelkaMaskEditor.open({imageId:17,previewId:'preview-1',revision:1,width:2500,height:3250,sourceUrl:'/source.svg',alphaUrl:'/alpha.svg',regions:[{reason:'uncertain-background',bbox:[1000,1000,200,250],polygon:[[1000,1000],[1200,1000],[1200,1250],[1000,1250]]},{reason:'uncertain-edge',bbox:[1700,1700,100,100],polygon:[[1700,1700],[1800,1700],[1800,1800]]}]},{onAuxiliary:open=>{if(open)history.pushState({helper:true},'');},onValidate:async(payload,context)=>{calls.push(payload);window.validationSignal=context.signal;return window.nextResult||{success:true,state:'needs_review',review:{revision:2,regions:[],reasons:['needs-more-local-input']}};},onCompare:result=>compared.push(result),onClose:result=>closeEvents.push(result)});};</script></body></html>`;
before(async () => {
    browser = await playwright.chromium.launch({headless:true,executablePath:process.env.ANABELKA_UI_TEST_BROWSER || '/tmp/chromium',args:['--no-sandbox','--disable-dev-shm-usage']});
    server = http.createServer((req,res)=>{
        const pathname = new URL(req.url,'http://localhost').pathname;
        if(pathname==='/'){res.setHeader('Content-Type','text/html; charset=utf-8');res.end(fixture);return;}
        if(pathname==='/source.svg'||pathname==='/alpha.svg'){res.setHeader('Content-Type','image/svg+xml');res.end(`<svg xmlns="http://www.w3.org/2000/svg" width="2500" height="3250"><rect width="2500" height="3250" fill="${pathname.includes('alpha')?'white':'#e9dcf3'}"/><rect x="800" y="600" width="900" height="2000" fill="${pathname.includes('alpha')?'black':'#665177'}"/></svg>`);return;}
        if(pathname==='/actual-source.jpg'&&process.env.ANABELKA_MASK_UI_REAL_SOURCE){res.setHeader('Content-Type','image/jpeg');res.end(fs.readFileSync(process.env.ANABELKA_MASK_UI_REAL_SOURCE));return;}
        if(pathname==='/actual-alpha.png'&&process.env.ANABELKA_MASK_UI_REAL_ALPHA){res.setHeader('Content-Type','image/png');res.end(fs.readFileSync(process.env.ANABELKA_MASK_UI_REAL_ALPHA));return;}
        const file=path.join(repo,pathname);if(!fs.existsSync(file)){res.writeHead(404);res.end();return;}res.setHeader('Content-Type',pathname.endsWith('.css')?'text/css':'text/javascript; charset=utf-8');res.end(fs.readFileSync(file));
    });
    await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));origin=`http://127.0.0.1:${server.address().port}`;
});
after(async()=>{await browser?.close();await new Promise(resolve=>server?.close(resolve));});
async function harness(t,width=390,height=720){
    const context=await browser.newContext({viewport:{width,height},hasTouch:true});t.after(()=>context.close());
    const page=await context.newPage();page.setDefaultTimeout(5000);const errors=[];page.on('pageerror',e=>errors.push(e.message));t.after(()=>assert.deepEqual(errors,[]));
    await page.goto(origin);assert.equal(await page.evaluate(()=>typeof window.AnabelkaMaskEditor),'object','Dedicated smart mask editor API must exist');
    await page.evaluate(()=>openMask());await page.locator('.mask-editor').waitFor();await page.waitForFunction(()=>document.querySelector('.mask-editor').dataset.ready==='true');
    return page;
}
async function saveScreenshot(page,label){
    const directory=process.env.ANABELKA_MASK_UI_SCREENSHOTS;if(!directory)return;
    fs.mkdirSync(directory,{recursive:true});const viewport=page.viewportSize();await page.screenshot({path:path.join(directory,`${label}-${viewport.width}x${viewport.height}.png`)});
}
async function tapCenter(page){const b=await page.locator('[data-mask-stage]').boundingBox();await page.mouse.click(b.x+b.width/2,b.y+b.height/2);}
async function pointer(page,type,id,x,y,pointerType='touch'){await page.locator('[data-mask-stage]').dispatchEvent(type,{pointerId:id,pointerType,clientX:x,clientY:y,buttons:type==='pointerup'?0:1,isPrimary:id===1});}

test('smart mask editor creates a dedicated accessible dialog',async t=>{const page=await harness(t);assert.equal(await page.locator('.mask-editor').getAttribute('role'),'dialog');});
test('initial review reasons retain a visible choose-mode then tap instruction on a small screen',async t=>{
    const page=await harness(t,320,280);await page.evaluate(()=>{AnabelkaMaskEditor.close();AnabelkaMaskEditor.open({imageId:17,previewId:'initial-hint',revision:0,width:2500,height:3250,sourceUrl:'/source.svg',alphaUrl:'/alpha.svg',regions:[],reasons:['source_background_retained','source_local_material_loss']},{onValidate:async()=>({success:true,state:'needs_review'})});});await page.waitForFunction(()=>document.querySelector('.mask-editor').dataset.ready==='true');const status=await page.locator('[data-mask-status]').textContent();assert.match(status,/^Оберіть.*Фон.*Модель.*Неясно.*торкніться фото/);assert.deepEqual(await page.evaluate(()=>AnabelkaMaskEditor.getActions()),[]);
    assert.equal(await page.locator('[data-mask-status]').evaluate(node=>{const text=node.firstChild,index=text.textContent.indexOf('торкніться фото'),range=document.createRange();range.setStart(text,index);range.setEnd(text,index+'торкніться фото'.length);const word=range.getBoundingClientRect(),box=node.getBoundingClientRect();return word.left>=box.left&&word.right<=box.right&&word.top>=box.top&&word.bottom<=box.bottom;}),true,'Tap instruction fits the visible status line');
    await page.locator('[data-mask-help]').click();assert.match(await page.locator('.mask-editor-aux-card').textContent(),/не обов.язково фон/);assert.match(await page.locator('.mask-editor-aux-card').textContent(),/залишився фон|матеріал моделі/);
});
test('choosing point modes explains how to tap without creating an annotation',async t=>{
    const page=await harness(t);for(const kind of ['background','foreground','unsure']){await page.locator('[data-mask-kind="'+kind+'"]').click();assert.match(await page.locator('[data-mask-status]').textContent(),/торкніться.*фото|торкніться.*матеріалу|торкніться.*фону/i);assert.deepEqual(await page.evaluate(()=>AnabelkaMaskEditor.getActions()),[]);}await tapCenter(page);assert.equal((await page.evaluate(()=>AnabelkaMaskEditor.getActions())).length,1);
});
test('tap coordinates use full resolution and region navigation does not paint bounding rectangles',async t=>{
    const page=await harness(t);await tapCenter(page);let actions=await page.evaluate(()=>AnabelkaMaskEditor.getActions());assert.equal(actions.length,1);assert.deepEqual(actions[0].points,[[1250,1625]]);assert.equal(actions[0].tool,'point');assert.equal(actions[0].kind,'background');
    await page.locator('[data-mask-next]').click();assert.deepEqual(await page.evaluate(()=>AnabelkaMaskEditor.getActions()),actions);
    await page.locator('[data-mask-undo]').click();assert.deepEqual(await page.evaluate(()=>AnabelkaMaskEditor.getActions()),[]);
    await page.locator('[data-mask-redo]').click();assert.deepEqual(await page.evaluate(()=>AnabelkaMaskEditor.getActions()),actions);
});
test('dirty cancel uses accessible helper; first Back dismisses helper and next clean Back closes mask',async t=>{
    const page=await harness(t);await tapCenter(page);await page.locator('[data-mask-close]').click();assert.equal(await page.evaluate(()=>AnabelkaMaskEditor.hasAuxiliary()),true);
    assert.equal(await page.locator('.mask-editor-aux').getAttribute('role'),'alertdialog');await page.evaluate(()=>history.back());await page.waitForFunction(()=>!AnabelkaMaskEditor.hasAuxiliary());assert.equal(await page.evaluate(()=>AnabelkaMaskEditor.isOpen()),true);
    await page.locator('[data-mask-close]').click();await page.locator('[data-mask-discard]').click();assert.equal(await page.evaluate(()=>AnabelkaMaskEditor.isOpen()),false);assert.equal(await page.evaluate(()=>closeEvents.length),1);
    assert.equal(await page.locator('#underlying').evaluate(n=>n.inert),false);
});
test('pinch or pan cancels provisional drawing and zoom remains capped at twelve',async t=>{
    const page=await harness(t);await page.locator('[data-mask-tool="brush"]').first().click();const b=await page.locator('[data-mask-stage]').boundingBox();const x=b.x+b.width/2,y=b.y+b.height/2;
    await pointer(page,'pointerdown',1,x,y);await pointer(page,'pointermove',1,x+4,y+4);await pointer(page,'pointerdown',2,x+50,y);await pointer(page,'pointermove',2,x+110,y);await pointer(page,'pointerup',2,x+110,y);await pointer(page,'pointerup',1,x+4,y+4);assert.deepEqual(await page.evaluate(()=>AnabelkaMaskEditor.getActions()),[]);
    await page.locator('[data-mask-tool="pan"]').click();await pointer(page,'pointerdown',1,x,y);await pointer(page,'pointermove',1,x+90,y);await pointer(page,'pointerup',1,x+90,y);assert.deepEqual(await page.evaluate(()=>AnabelkaMaskEditor.getActions()),[]);
    for(let i=0;i<20;i++)await page.locator('[data-mask-zoom-in]').click();assert.equal(await page.locator('[data-mask-stage]').getAttribute('data-zoom'),'12');
});
test('needs review keeps refusal visible and only validated ready output reaches compare',async t=>{
    const page=await harness(t);await tapCenter(page);await page.locator('[data-mask-validate]').click();await page.waitForFunction(()=>calls.length===1&&document.querySelector('.mask-editor').getAttribute('aria-busy')==='false');assert.equal(await page.evaluate(()=>compared.length),0);assert.match(await page.locator('[data-mask-status]').textContent(),/потребує|перевір|уточн/i);
    await tapCenter(page);await page.evaluate(()=>window.nextResult={success:true,state:'ready',preview_id:'validated',preview_url:'/validated.jpg',processing:{status:'ready'}});await page.locator('[data-mask-validate]').click();await page.waitForFunction(()=>compared.length===1);assert.equal(await page.evaluate(()=>AnabelkaMaskEditor.isOpen()),false);
});
test('unvalidated output cannot escape editor even if it contains a candidate URL',async t=>{
    const page=await harness(t);await tapCenter(page);await page.evaluate(()=>window.nextResult={state:'ready',preview_id:'bad',preview_url:'/bad.jpg',processing:{status:'ready'}});await page.locator('[data-mask-validate]').click();await page.waitForFunction(()=>calls.length===1&&document.querySelector('.mask-editor').getAttribute('aria-busy')==='false');assert.equal(await page.evaluate(()=>compared.length),0);assert.equal(await page.evaluate(()=>AnabelkaMaskEditor.isOpen()),true);
});
test('soft brush exports source pixels, shows an offset magnifier, and permits reset without closing',async t=>{
    const page=await harness(t);await page.locator('[data-mask-help]').click();await page.locator('[data-mask-radius]').fill('37');await page.locator('[data-mask-hardness]').fill('35');await page.locator('[data-mask-helper-close]').click();await page.locator('[data-mask-brush="foreground"]').click();
    const b=await page.locator('[data-mask-stage]').boundingBox(),x=b.x+b.width/2,y=b.y+b.height/2;await pointer(page,'pointerdown',1,x,y);await pointer(page,'pointermove',1,x+5,y+5);await page.locator('.mask-editor-magnifier').waitFor();const m=await page.locator('.mask-editor-magnifier').boundingBox();assert.ok(!(x>m.x&&x<m.x+m.width&&y>m.y&&y<m.y+m.height),'Loupe must be offset from finger');await pointer(page,'pointerup',1,x+5,y+5);
    const action=(await page.evaluate(()=>AnabelkaMaskEditor.getActions()))[0];assert.equal(action.tool,'brush');assert.equal(action.kind,'foreground');assert.equal(action.radius,37);assert.equal(action.hardness,.35);assert.deepEqual(action.points[0],[1250,1625]);
    await page.locator('[data-mask-reset]').click();await page.locator('[data-mask-confirm-reset]').click();assert.deepEqual(await page.evaluate(()=>AnabelkaMaskEditor.getActions()),[]);assert.equal(await page.evaluate(()=>AnabelkaMaskEditor.isOpen()),true);
});
test('region navigation centers actual source bbox without painting its rectangle',async t=>{
    const page=await harness(t);await page.locator('[data-mask-next]').click();await tapCenter(page);const actions=await page.evaluate(()=>AnabelkaMaskEditor.getActions());assert.deepEqual(actions[0].points,[[1750,1750]]);assert.equal(actions[0].tool,'point');assert.equal(actions[0].radius,10);
});
test('repeated pinch movement keeps the same source pixel under gesture center',async t=>{
    const page=await harness(t);const b=await page.locator('[data-mask-stage]').boundingBox(),x=b.x+b.width/2,y=b.y+b.height/2;
    await pointer(page,'pointerdown',1,x-40,y);await pointer(page,'pointerdown',2,x+40,y);await pointer(page,'pointermove',1,x-50,y+5);await pointer(page,'pointermove',2,x+90,y+5);await pointer(page,'pointermove',1,x-80,y+10);await pointer(page,'pointermove',2,x+120,y+10);await pointer(page,'pointerup',1,x-80,y+10);await pointer(page,'pointerup',2,x+120,y+10);
    await page.mouse.click(x+20,y+10);const actions=await page.evaluate(()=>AnabelkaMaskEditor.getActions());assert.deepEqual(actions[0].points,[[1250,1625]]);
});
test('local history is bounded and actions do not add browser history entries',async t=>{
    const page=await harness(t);const before=await page.evaluate(()=>history.length);const b=await page.locator('[data-mask-stage]').boundingBox(),x=b.x+b.width/2,y=b.y+b.height/2;
    for(let i=0;i<66;i++){await pointer(page,'pointerdown',1,x,y);await pointer(page,'pointerup',1,x,y);}
    assert.equal((await page.evaluate(()=>AnabelkaMaskEditor.getActions())).length,64);assert.equal(await page.evaluate(()=>history.length),before);
});
test('closing aborts pending validation and ignores its later ready response',async t=>{
    const page=await harness(t);await page.evaluate(()=>{window.nextResult=new Promise(resolve=>window.resolveValidation=resolve);});await tapCenter(page);await page.locator('[data-mask-validate]').click();await page.waitForFunction(()=>calls.length===1);await page.evaluate(()=>AnabelkaMaskEditor.close({discarded:true}));assert.equal(await page.evaluate(()=>validationSignal.aborted),true);
    await page.evaluate(()=>resolveValidation({success:true,state:'ready',preview_id:'late',preview_url:'/late.jpg',processing:{status:'ready'}}));await page.waitForTimeout(20);assert.equal(await page.evaluate(()=>compared.length),0);assert.equal(await page.locator('.mask-editor').count(),0);
});
test('rapid replacement and close clean up image requests and keep newest editor usable',async t=>{
    const page=await harness(t);await page.evaluate(()=>{AnabelkaMaskEditor.close();openMask();AnabelkaMaskEditor.close();openMask();});await page.waitForFunction(()=>document.querySelector('.mask-editor').dataset.ready==='true');assert.equal(await page.locator('.mask-editor').count(),1);await tapCenter(page);assert.equal((await page.evaluate(()=>AnabelkaMaskEditor.getActions())).length,1);
});
test('mask refusals explain protected material and branded notifications appear above overlay',async t=>{
    const page=await harness(t);await page.addStyleTag({path:path.join(repo,'css/anabelka-notify.css')});await tapCenter(page);await page.evaluate(()=>window.nextResult={success:true,state:'needs_review',review:{revision:2,regions:[],reasons:['source_local_material_loss']}});await page.locator('[data-mask-validate]').click();await page.waitForFunction(()=>calls.length===1&&document.querySelector('.mask-editor').getAttribute('aria-busy')==='false'&&document.querySelector('.mask-editor').dataset.ready==='true');
    assert.match(await page.locator('[data-mask-status]').textContent(),/матеріал|модел/i);assert.equal(await page.evaluate(()=>Number(getComputedStyle(document.querySelector('#site-message')).zIndex)>Number(getComputedStyle(document.querySelector('.mask-editor')).zIndex)),true);
});
test('helper reports all four independent validation gates without controls to disable them',async t=>{
    const page=await harness(t);await tapCenter(page);await page.evaluate(()=>window.nextResult={success:true,state:'needs_review',review:{revision:2,regions:[],reasons:['source_local_material_loss'],gates:{component:{accepted:true},local:{accepted:false},thin:{accepted:true},background:{accepted:true}}}});await page.locator('[data-mask-validate]').click();await page.waitForFunction(()=>calls.length===1&&document.querySelector('.mask-editor').getAttribute('aria-busy')==='false'&&document.querySelector('.mask-editor').dataset.ready==='true');await page.locator('[data-mask-help]').click();
    assert.equal(await page.locator('[data-mask-gate]').count(),4);assert.match(await page.locator('[data-mask-gate="local"]').textContent(),/потребує уточнення/);assert.match(await page.locator('[data-mask-gate="thin"]').textContent(),/збережено/);assert.equal(await page.locator('[data-mask-gate] input,[data-mask-gate] button').count(),0);
});
test('undo all after review can submit empty cumulative log to restore immutable mask',async t=>{
    const page=await harness(t);await tapCenter(page);await page.locator('[data-mask-validate]').click();await page.waitForFunction(()=>calls.length===1&&document.querySelector('.mask-editor').getAttribute('aria-busy')==='false'&&document.querySelector('.mask-editor').dataset.ready==='true');await page.locator('[data-mask-undo]').click();assert.equal(await page.locator('[data-mask-validate]').isDisabled(),false);await page.locator('[data-mask-validate]').click();await page.waitForFunction(()=>calls.length===2);assert.deepEqual(await page.evaluate(()=>calls[1].actions),[]);assert.equal(await page.evaluate(()=>calls[1].revision),2);
});
test('review reload uses the new authenticated revision and retains immutable source without refetching stale URLs',async t=>{
    const page=await harness(t);const requests=[];page.on('request',request=>requests.push(request.url()));await tapCenter(page);await page.evaluate(()=>window.nextResult={success:true,state:'needs_review',review:{revision:2,regions:[],reasons:['source_local_material_loss'],source_url:'/source.svg?revision=2',alpha_url:'/alpha.svg?revision=2'}});await page.locator('[data-mask-validate]').click();await page.waitForFunction(()=>calls.length===1&&document.querySelector('.mask-editor').getAttribute('aria-busy')==='false'&&document.querySelector('.mask-editor').dataset.ready==='true');
    assert.ok(requests.some(url=>url.endsWith('/alpha.svg?revision=2')));assert.equal(requests.some(url=>url.endsWith('/source.svg')),false,'Immutable source must not be refetched with old authenticated revision');assert.equal(await page.locator('.mask-editor').getAttribute('data-ready'),'true');
});
const reviewed = (actions, actionResults, extra = {}) => ({success:true,state:'needs_review',review:{revision:2,status:'needs_review',regions:[],actions,action_results:actionResults,...extra}});
async function waitReviewed(page, count = 1) {await page.waitForFunction(count=>calls.length===count&&document.querySelector('.mask-editor')?.getAttribute('aria-busy')==='false'&&document.querySelector('.mask-editor')?.dataset.ready==='true',count);}
async function openConflicts(page) {const button=page.locator('[data-mask-conflicts-open]');if(await button.count())await button.click();}
async function closeConflicts(page) {const button=page.locator('[data-mask-conflicts-close]');if(await button.count())await button.click();}
async function tapOffset(page, offset) {const b=await page.locator('[data-mask-stage]').boundingBox();await page.mouse.click(b.x+b.width/2+offset,b.y+b.height/2);}

test('an unchanged refused action log cannot be submitted again after revision advances',async t=>{
    const page=await harness(t);await tapCenter(page);const actions=await page.evaluate(()=>AnabelkaMaskEditor.getActions());
    await page.evaluate(result=>window.nextResult=result,reviewed(actions,[{index:0,status:'rejected',reasons:['unsafe_background_annotation']}]));await page.locator('[data-mask-validate]').click();await waitReviewed(page);
    assert.equal(await page.locator('[data-mask-validate]').isDisabled(),true);
    await page.locator('[data-mask-validate]').evaluate(button=>button.click());assert.equal(await page.evaluate(()=>calls.length),1);
    await page.locator('[data-mask-undo]').click();await page.locator('[data-mask-redo]').click();assert.equal(await page.locator('[data-mask-validate]').isDisabled(),true,'Undo then redo to the same refused log remains blocked');
    await tapOffset(page,25);assert.equal(await page.locator('[data-mask-validate]').isDisabled(),false);
});
test('delete one rejected action preserves valid actions and undo redo restores conflict feedback',async t=>{
    const page=await harness(t);await tapOffset(page,-35);await tapOffset(page,0);await tapOffset(page,35);const actions=await page.evaluate(()=>AnabelkaMaskEditor.getActions());
    await page.evaluate(result=>window.nextResult=result,reviewed(actions,[{index:0,status:'accepted',reasons:[]},{index:1,status:'rejected',reasons:['unsafe_background_annotation']},{index:2,status:'accepted',reasons:[]}]));await page.locator('[data-mask-validate]').click();await waitReviewed(page);
    await openConflicts(page);assert.equal(await page.locator('[data-mask-conflict]').count(),1);assert.match(await page.locator('[data-mask-conflict]').textContent(),/зачіпає модель/);
    await page.locator('[data-mask-delete-action="1"]').click();assert.deepEqual(await page.evaluate(()=>AnabelkaMaskEditor.getActions()),[actions[0],actions[2]]);assert.equal(await page.locator('[data-mask-conflict]').count(),0);await closeConflicts(page);
    await page.locator('[data-mask-undo]').click();assert.deepEqual(await page.evaluate(()=>AnabelkaMaskEditor.getActions()),actions);await openConflicts(page);assert.equal(await page.locator('[data-mask-conflict]').count(),1);await closeConflicts(page);assert.equal(await page.locator('[data-mask-validate]').isDisabled(),true);
    await page.locator('[data-mask-redo]').click();assert.deepEqual(await page.evaluate(()=>AnabelkaMaskEditor.getActions()),[actions[0],actions[2]]);assert.equal(await page.locator('[data-mask-validate]').isDisabled(),false);
});
test('replace rejected action updates its type and location without appending or changing valid actions',async t=>{
    const page=await harness(t);await tapOffset(page,-30);await tapOffset(page,30);const actions=await page.evaluate(()=>AnabelkaMaskEditor.getActions());
    await page.evaluate(result=>window.nextResult=result,reviewed(actions,[{index:0,status:'accepted',reasons:[]},{index:1,status:'rejected',reasons:['unsupported_foreground_annotation']}]));await page.locator('[data-mask-validate]').click();await waitReviewed(page);
    await openConflicts(page);await page.locator('[data-mask-replace-action="1"]').click();await page.locator('[data-mask-kind="foreground"]').click();await tapOffset(page,15);const changed=await page.evaluate(()=>AnabelkaMaskEditor.getActions());
    assert.equal(changed.length,2);assert.deepEqual(changed[0],actions[0]);assert.equal(changed[1].kind,'foreground');assert.notDeepEqual(changed[1].points,actions[1].points);
    await page.locator('[data-mask-undo]').click();assert.deepEqual(await page.evaluate(()=>AnabelkaMaskEditor.getActions()),actions);
    await page.locator('[data-mask-redo]').click();assert.deepEqual(await page.evaluate(()=>AnabelkaMaskEditor.getActions()),changed);
});
test('accepted mask rendering retry allows unchanged log but marks result retry explicitly',async t=>{
    const page=await harness(t);await tapCenter(page);const actions=await page.evaluate(()=>AnabelkaMaskEditor.getActions());
    await page.evaluate(result=>window.nextResult=result,reviewed(actions,[{index:0,status:'accepted',reasons:[]}],{status:'accepted',retry_result:true}));await page.locator('[data-mask-validate]').click();await waitReviewed(page);
    assert.equal(await page.locator('[data-mask-validate]').isDisabled(),false);await page.evaluate(()=>window.nextResult={success:true,state:'ready',preview_id:'validated',preview_url:'/validated.jpg',processing:{status:'ready'}});await page.locator('[data-mask-validate]').click();await page.waitForFunction(()=>compared.length===1);
    assert.equal(await page.evaluate(()=>calls[1].retryResult),true);assert.deepEqual(await page.evaluate(()=>calls[1].actions),actions);
});
test('resync keeps divergent local edits visible and imports canonical conflict results without silent loss',async t=>{
    const page=await harness(t);await tapOffset(page,-20);await tapOffset(page,20);const actions=await page.evaluate(()=>AnabelkaMaskEditor.getActions());
    await page.evaluate(result=>window.nextResult=result,reviewed([actions[0]],[{index:0,status:'accepted',reasons:[]}],{resynced:true}));await page.locator('[data-mask-validate]').click();await waitReviewed(page);
    assert.deepEqual(await page.evaluate(()=>AnabelkaMaskEditor.getActions()),actions);assert.match(await page.locator('[data-mask-status]').textContent(),/версі|локальн/i);assert.equal(await page.locator('[data-mask-validate]').isDisabled(),false);
    await page.locator('[data-mask-validate]').click();await waitReviewed(page,2);assert.equal(await page.evaluate(()=>calls[1].revision),2);
});
test('server alpha does not receive a second colored paint pass for applied accepted actions',async t=>{
    const page=await harness(t);await page.waitForTimeout(30);const read=()=>page.locator('.mask-editor-canvas').evaluate(canvas=>{const p=canvas.getContext('2d').getImageData(Math.floor(canvas.width/2),Math.floor(canvas.height/2),1,1);return [...p.data];});const before=await read();
    await page.locator('[data-mask-kind="foreground"]').click();await tapCenter(page);const actions=await page.evaluate(()=>AnabelkaMaskEditor.getActions());
    await page.evaluate(result=>window.nextResult=result,reviewed(actions,[{index:0,status:'accepted',reasons:[]}],{alpha_url:'/alpha.svg?revision=2'}));await page.locator('[data-mask-validate]').click();await waitReviewed(page);await page.waitForTimeout(30);
    assert.deepEqual(await read(),before,'Server alpha is already the evaluated cumulative mask');
    await page.locator('[data-mask-undo]').click();await page.waitForTimeout(30);assert.deepEqual(await read(),before,'Undo replays on immutable raw alpha rather than stale revised alpha');
});
test('unresolved unsure actions have targeted correction controls while surrounding valid actions survive',async t=>{
    const page=await harness(t);await tapOffset(page,-35);await page.locator('[data-mask-kind="unsure"]').click();await tapCenter(page);await page.locator('[data-mask-kind="foreground"]').click();await tapOffset(page,35);const actions=await page.evaluate(()=>AnabelkaMaskEditor.getActions());
    await page.evaluate(result=>window.nextResult=result,reviewed(actions,[{index:0,status:'accepted',reasons:[]},{index:1,status:'unresolved',reasons:['annotation_unsure']},{index:2,status:'accepted',reasons:[]}]));await page.locator('[data-mask-validate]').click();await waitReviewed(page);
    await openConflicts(page);assert.equal(await page.locator('[data-mask-conflict]').count(),1);assert.match(await page.locator('[data-mask-conflict]').textContent(),/не змінює маску|уточніть/i);
    await page.locator('[data-mask-delete-action="1"]').click();assert.deepEqual(await page.evaluate(()=>AnabelkaMaskEditor.getActions()),[actions[0],actions[2]]);
});
test('reopening a canonical journal permits undo and redo using immutable raw alpha',async t=>{
    const page=await harness(t);const actions=[{kind:'foreground',tool:'point',radius:10,hardness:1,points:[[900,900]]},{kind:'foreground',tool:'point',radius:10,hardness:1,points:[[1600,900]]}];
    await page.evaluate(actions=>{AnabelkaMaskEditor.close();AnabelkaMaskEditor.open({imageId:17,previewId:'restored',revision:4,width:2500,height:3250,sourceUrl:'/source.svg',alphaUrl:'/alpha.svg',rawAlphaUrl:'/alpha.svg',actions,actionResults:actions.map((_,index)=>({index,status:'accepted',reasons:[]}))},{onValidate:async payload=>{calls.push(payload);return {success:true,state:'needs_review',review:{revision:5,actions:payload.actions}};}});},actions);
    await page.waitForFunction(()=>document.querySelector('.mask-editor').dataset.ready==='true');assert.equal(await page.locator('[data-mask-undo]').isDisabled(),false);
    await page.locator('[data-mask-undo]').click();assert.deepEqual(await page.evaluate(()=>AnabelkaMaskEditor.getActions()),[actions[0]]);await page.locator('[data-mask-undo]').click();assert.deepEqual(await page.evaluate(()=>AnabelkaMaskEditor.getActions()),[]);
    assert.equal(await page.locator('[data-mask-validate]').isDisabled(),false);await page.locator('[data-mask-redo]').click();await page.locator('[data-mask-redo]').click();assert.deepEqual(await page.evaluate(()=>AnabelkaMaskEditor.getActions()),actions);assert.equal(await page.locator('[data-mask-validate]').isDisabled(),true);
});
test('mixed review identifies accepted and rejected action numbers without losing either annotation',async t=>{
    const page=await harness(t);await tapOffset(page,-20);await tapOffset(page,20);const actions=await page.evaluate(()=>AnabelkaMaskEditor.getActions());await page.evaluate(result=>window.nextResult=result,reviewed(actions,[{index:0,status:'accepted',reasons:[]},{index:1,status:'rejected',reasons:['unsafe_background_annotation']}],{regions:[{bbox:[100,200,40,40],reason:'source_local_material_loss'}]}));await page.locator('[data-mask-validate]').click();await waitReviewed(page);assert.match(await page.locator('[data-mask-accepted-count]').textContent(),/Прийнято: 1/);assert.match(await page.locator('[data-mask-rejected-count]').textContent(),/1/);await openConflicts(page);
    assert.match(await page.locator('[data-mask-action-result="0"]').textContent(),/Позначка 1.*прийнят/i);assert.match(await page.locator('[data-mask-action-result="1"]').textContent(),/Позначка 2.*відхилен/i);assert.equal(await page.locator('[data-mask-action-result="0"] [data-mask-delete-action]').count(),0);assert.deepEqual(await page.evaluate(()=>AnabelkaMaskEditor.getActions()),actions);
});
test('accepted-only needs review keeps action results reachable while independent defects block comparison',async t=>{
    const page=await harness(t,320,280);await tapCenter(page);const actions=await page.evaluate(()=>AnabelkaMaskEditor.getActions());await page.evaluate(result=>window.nextResult=result,reviewed(actions,[{index:0,status:'accepted',reasons:[]}],{regions:[{bbox:[100,200,40,40],reason:'source_local_material_loss'}],reasons:['source_local_material_loss'],alpha_url:'/alpha.svg?revision=2'}));await page.locator('[data-mask-validate]').click();await waitReviewed(page);assert.equal(await page.locator('[data-mask-conflicts-open]').isDisabled(),false);await openConflicts(page);assert.match(await page.locator('[data-mask-action-result="0"]').textContent(),/прийнят/i);const show=page.locator('[data-mask-focus-action="0"]');await show.scrollIntoViewIfNeeded();const box=await show.boundingBox();assert.ok(box.width>=44&&box.height>=44);await show.click();assert.match(await page.locator('[data-mask-status]').textContent(),/прийнято/i);assert.doesNotMatch(await page.locator('[data-mask-status]').textContent(),/червони|відхилено/i);assert.equal(await page.evaluate(()=>compared.length),0);assert.deepEqual(await page.evaluate(()=>AnabelkaMaskEditor.getActions()),actions);
    await page.locator('[data-mask-help]').click();const help=await page.locator('.mask-editor-aux-card').textContent();assert.match(help,/прийнят.*позначк.*ділянк|позначк.*прийнят.*ділянк/i);assert.match(help,/скасуйте.*перегляд|нову пробу/i);assert.doesNotMatch(help,/Позначка може видалити матеріал/);
});
test('local changes distinguish prior acceptance from new unchecked actions in the results panel',async t=>{
    const page=await harness(t);await tapOffset(page,-20);const actions=await page.evaluate(()=>AnabelkaMaskEditor.getActions());await page.evaluate(result=>window.nextResult=result,reviewed(actions,[{index:0,status:'accepted',reasons:[]}],{regions:[{bbox:[100,200,40,40]}]}));await page.locator('[data-mask-validate]').click();await waitReviewed(page);await tapOffset(page,20);await openConflicts(page);assert.match(await page.locator('[data-mask-action-result="0"]').textContent(),/попередн.*перевір|раніше/i);assert.match(await page.locator('[data-mask-action-result="1"]').textContent(),/не перевірено|неперевірен/i);assert.doesNotMatch(await page.locator('[data-mask-action-result="1"]').textContent(),/прийнят/i);assert.match(await page.locator('[data-mask-accepted-count]').textContent(),/Було прийнято|Раніше/i);
});
test('remaining region navigation stops at boundaries without cycling or adding actions',async t=>{
    const page=await harness(t);assert.equal(await page.locator('[data-mask-previous]').isDisabled(),true);await page.locator('[data-mask-next]').click();assert.equal(await page.locator('[data-mask-next]').isDisabled(),true);const label=await page.locator('[data-mask-count]').textContent();
    await page.locator('[data-mask-next]').evaluate(button=>button.click());assert.equal(await page.locator('[data-mask-count]').textContent(),label);await page.locator('[data-mask-previous]').click();assert.equal(await page.locator('[data-mask-previous]').isDisabled(),true);assert.deepEqual(await page.evaluate(()=>AnabelkaMaskEditor.getActions()),[]);
});
test('original remaining and rejected counters stay separate after new validation',async t=>{
    const page=await harness(t);await tapCenter(page);const actions=await page.evaluate(()=>AnabelkaMaskEditor.getActions());
    await page.evaluate(result=>window.nextResult=result,reviewed(actions,[{index:0,status:'rejected',reasons:['unsafe_background_annotation']}],{initial_region_count:2,regions:[{bbox:[100,200,80,90],reason:'source_background_retained'}]}));await page.locator('[data-mask-validate]').click();await waitReviewed(page);
    assert.match(await page.locator('[data-mask-initial-count]').textContent(),/2/);assert.match(await page.locator('[data-mask-remaining-count]').textContent(),/1/);assert.match(await page.locator('[data-mask-rejected-count]').textContent(),/1/);assert.equal(await page.locator('[data-mask-next]').isDisabled(),true);
    await openConflicts(page);await page.locator('[data-mask-delete-action="0"]').click();await closeConflicts(page);assert.match(await page.locator('[data-mask-initial-count]').textContent(),/2/);assert.match(await page.locator('[data-mask-remaining-count]').textContent(),/1/);assert.match(await page.locator('[data-mask-rejected-count]').textContent(),/0/);
});
test('conflict panel owns one auxiliary Back entry while keeping valid actions and viewport intact',async t=>{
    const page=await harness(t);await tapOffset(page,-20);await tapOffset(page,20);const actions=await page.evaluate(()=>AnabelkaMaskEditor.getActions());await page.evaluate(result=>window.nextResult=result,reviewed(actions,[{index:0,status:'accepted',reasons:[]},{index:1,status:'rejected',reasons:['unsafe_background_annotation']}]));await page.locator('[data-mask-validate]').click();await waitReviewed(page);const historyLength=await page.evaluate(()=>history.length);const stage=await page.locator('[data-mask-stage]').boundingBox();
    await page.locator('[data-mask-conflicts-open]').click();assert.equal(await page.locator('.mask-editor-aux').getAttribute('role'),'dialog');assert.equal(await page.evaluate(()=>history.length),historyLength+1);assert.match(await page.locator('[data-mask-conflict]').textContent(),/фон.*модель|зачіпає модель/i);
    await page.evaluate(()=>history.back());await page.waitForFunction(()=>!AnabelkaMaskEditor.hasAuxiliary());assert.deepEqual(await page.evaluate(()=>AnabelkaMaskEditor.getActions()),actions);assert.deepEqual(await page.locator('[data-mask-stage]').boundingBox(),stage);
});
for(const selector of ['[data-mask-next]','[data-mask-zoom-in]','[data-mask-tool="pan"]'])test(`viewport or tool control ${selector} cancels an active brush instead of bridging source pixels`,async t=>{
    const page=await harness(t);await page.locator('[data-mask-brush="foreground"]').click();const b=await page.locator('[data-mask-stage]').boundingBox(),x=b.x+b.width/2,y=b.y+b.height/2;await pointer(page,'pointerdown',1,x,y);await page.locator(selector).click();await pointer(page,'pointermove',1,x+10,y);await pointer(page,'pointerup',1,x+10,y);assert.deepEqual(await page.evaluate(()=>AnabelkaMaskEditor.getActions()),[]);
    await page.locator('[data-mask-kind="foreground"]').click();await tapCenter(page);assert.equal((await page.evaluate(()=>AnabelkaMaskEditor.getActions())).length,1);
});
test('cancelled pinch cannot turn its surviving touch into a stroke and a fresh touch still works',async t=>{
    const page=await harness(t);await page.locator('[data-mask-brush="foreground"]').click();const b=await page.locator('[data-mask-stage]').boundingBox(),x=b.x+b.width/2,y=b.y+b.height/2;await pointer(page,'pointerdown',1,x-20,y);await pointer(page,'pointerdown',2,x+20,y);await pointer(page,'pointercancel',2,x+20,y);await pointer(page,'pointermove',1,x+20,y);await pointer(page,'pointerup',1,x+20,y);assert.deepEqual(await page.evaluate(()=>AnabelkaMaskEditor.getActions()),[]);await tapCenter(page);assert.equal((await page.evaluate(()=>AnabelkaMaskEditor.getActions())).length,1);
});
test('remaining counters report actual defects even when only some contours are displayed',async t=>{
    const page=await harness(t);await page.evaluate(()=>{AnabelkaMaskEditor.close();AnabelkaMaskEditor.open({imageId:17,previewId:'many-regions',revision:1,width:2500,height:3250,sourceUrl:'/source.svg',alphaUrl:'/alpha.svg',initialRegionCount:128,remainingRegionCount:128,regions:[{bbox:[100,100,40,40]},{bbox:[200,200,40,40]}]},{onValidate:async payload=>{calls.push(payload);return {success:true,state:'needs_review',review:{revision:2,initial_region_count:128,remaining_region_count:127,regions:[{bbox:[100,100,40,40]},{bbox:[200,200,40,40]}],actions:payload.actions,action_results:[{index:0,status:'rejected',reasons:['unsafe_background_annotation']}]}};}});});await page.waitForFunction(()=>document.querySelector('.mask-editor').dataset.ready==='true');
    assert.match(await page.locator('[data-mask-initial-count]').textContent(),/128/);assert.match(await page.locator('[data-mask-remaining-count]').textContent(),/128/);assert.match(await page.locator('[data-mask-count]').textContent(),/показан|контур/i);await tapCenter(page);await page.locator('[data-mask-validate]').click();await waitReviewed(page);assert.match(await page.locator('[data-mask-remaining-count]').textContent(),/127/);
});
test('deleting a conflict keeps keyboard focus inside the correction dialog',async t=>{
    const page=await harness(t);await tapOffset(page,-20);await tapOffset(page,20);const actions=await page.evaluate(()=>AnabelkaMaskEditor.getActions());await page.evaluate(result=>window.nextResult=result,reviewed(actions,[{index:0,status:'rejected',reasons:['unsafe_background_annotation']},{index:1,status:'rejected',reasons:['unsafe_background_annotation']}]));await page.locator('[data-mask-validate]').click();await waitReviewed(page);await openConflicts(page);await page.locator('[data-mask-delete-action="0"]').click();assert.equal(await page.evaluate(()=>Boolean(document.activeElement.closest('.mask-editor-aux'))),true);await page.locator('[data-mask-delete-action="0"]').click();assert.equal(await page.locator('[data-mask-conflicts-close]').evaluate(node=>node===document.activeElement),true);
});
test('mutating an accepted cached log announces pending validation until undo restores that log',async t=>{
    const page=await harness(t);await tapCenter(page);const actions=await page.evaluate(()=>AnabelkaMaskEditor.getActions());await page.evaluate(result=>window.nextResult=result,reviewed(actions,[{index:0,status:'accepted',reasons:[]}],{status:'accepted',retry_result:true}));await page.locator('[data-mask-validate]').click();await waitReviewed(page);await tapOffset(page,20);assert.match(await page.locator('[data-mask-status]').textContent(),/ще не перевірено/i);await page.locator('[data-mask-undo]').click();assert.match(await page.locator('[data-mask-status]').textContent(),/підтверджено|порівнян/i);await page.locator('[data-mask-redo]').click();assert.match(await page.locator('[data-mask-status]').textContent(),/ще не перевірено/i);
});
test('removing the final submitted annotation remains dirty for Back and cancellation',async t=>{
    const page=await harness(t);await tapCenter(page);const actions=await page.evaluate(()=>AnabelkaMaskEditor.getActions());await page.evaluate(result=>window.nextResult=result,reviewed(actions,[{index:0,status:'rejected',reasons:['unsafe_background_annotation']}]));await page.locator('[data-mask-validate]').click();await waitReviewed(page);await page.locator('[data-mask-undo]').click();assert.deepEqual(await page.evaluate(()=>AnabelkaMaskEditor.getActions()),[]);assert.equal(await page.evaluate(()=>AnabelkaMaskEditor.isDirty()),true);await page.locator('[data-mask-close]').click();assert.equal(await page.locator('.mask-editor-aux').getAttribute('role'),'alertdialog');await page.locator('[data-mask-keep]').click();assert.equal(await page.evaluate(()=>AnabelkaMaskEditor.isOpen()),true);
});
for(const [width,height]of [[320,640],[390,720],[640,320],[320,280]])test(`conflict correction controls remain reachable with 44px touch targets at ${width}x${height}`,async t=>{
    const page=await harness(t,width,height);await tapCenter(page);const actions=await page.evaluate(()=>AnabelkaMaskEditor.getActions());await page.evaluate(result=>window.nextResult=result,reviewed(actions,[{index:0,status:'rejected',reasons:['unsupported_foreground_annotation']}],{regions:[{bbox:[100,100,50,50]}]}));await page.locator('[data-mask-validate]').click();await waitReviewed(page);
    assert.ok((await page.locator('[data-mask-stage]').boundingBox()).height>=70);await page.locator('[data-mask-conflicts-open]').click();
    await saveScreenshot(page,'mask-conflict-correction');
    for(const selector of ['[data-mask-focus-action="0"]','[data-mask-replace-action="0"]','[data-mask-delete-action="0"]','[data-mask-conflicts-close]']){const control=page.locator(selector);await control.scrollIntoViewIfNeeded();const b=await control.boundingBox();assert.ok(b.width>=44&&b.height>=44,selector);assert.ok(b.x>=0&&b.right!==0&&b.x+b.width<=width+1&&b.y>=0&&b.y+b.height<=height+1,selector+' reachable inside viewport');}
    await page.locator('[data-mask-focus-action="0"]').click();assert.equal(await page.evaluate(()=>AnabelkaMaskEditor.hasAuxiliary()),false);assert.deepEqual(await page.evaluate(()=>AnabelkaMaskEditor.getActions()),actions);
});
for(const [width,height]of [[320,640],[360,740],[390,720],[412,740],[768,900],[640,320],[320,280],[360,320],[390,320],[412,320]])test(`mask overlay and controls fit ${width}×${height}`,async t=>{
    const page=await harness(t,width,height);const measurements=await page.evaluate(()=>({overflow:document.documentElement.scrollWidth>innerWidth+1,buttons:[...document.querySelectorAll('.mask-editor button')].filter(n=>n.getClientRects().length).map(n=>{const b=n.getBoundingClientRect();return {label:n.getAttribute('aria-label')||n.textContent,w:b.width,h:b.height,x:b.x,y:b.y,right:b.right,bottom:b.bottom};})}));assert.equal(measurements.overflow,false);
    for(const b of measurements.buttons){assert.ok(b.w>=43.99&&b.h>=43.99,`${b.label} target ${b.w}×${b.h}`);assert.ok(b.x>=-1&&b.y>=-1&&b.right<=width+1&&b.bottom<=height+1,`${b.label} inside viewport`);}
    const clippedLabels=await page.evaluate(()=>[...document.querySelectorAll('.mask-editor button span')].filter(span=>{const b=span.parentElement.getBoundingClientRect(),s=span.getBoundingClientRect();return s.width>2&&(s.left<b.left||s.right>b.right||s.top<b.top||s.bottom>b.bottom);}).map(span=>span.textContent));assert.deepEqual(clippedLabels,[],'Visible button labels must remain inside their target.');
    await saveScreenshot(page,'mask-simulation');
});
if(process.env.ANABELKA_MASK_UI_REAL_SOURCE&&process.env.ANABELKA_MASK_UI_REAL_ALPHA&&process.env.ANABELKA_MASK_UI_REAL_REVIEW){
    const report=JSON.parse(fs.readFileSync(process.env.ANABELKA_MASK_UI_REAL_REVIEW,'utf8'));
    const review=report.initial_review||report.review||report.validation;
    assert.equal(review.status,'needs_review','Actual rejected example must remain clearly refused.');
    for(const [width,height]of [[390,720],[640,320]])test(`actual original and raw alpha remain unmodified in mask screenshot ${width}×${height}`,async t=>{
        const page=await harness(t,width,height);await page.evaluate(review=>{AnabelkaMaskEditor.close();AnabelkaMaskEditor.open({imageId:17,previewId:'actual-refused-example',revision:0,width:2500,height:3250,sourceUrl:'/actual-source.jpg',alphaUrl:'/actual-alpha.png',regions:review.regions,gates:review.gates,reasons:review.reasons},{onValidate:async()=>({success:true,state:'needs_review',review})});},review);await page.waitForFunction(()=>document.querySelector('.mask-editor').dataset.ready==='true');assert.deepEqual(await page.evaluate(()=>AnabelkaMaskEditor.getActions()),[]);assert.equal(await page.locator('[data-mask-validate]').isDisabled(),true);await saveScreenshot(page,'mask-actual-original-raw-refused');
        await page.locator('[data-mask-next]').click();await saveScreenshot(page,'mask-actual-region-focus-refused');
    });
}
