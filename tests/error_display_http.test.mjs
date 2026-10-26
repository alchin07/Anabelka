import assert from 'node:assert/strict';
import {test} from 'node:test';
import {spawn, spawnSync} from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import {fileURLToPath} from 'node:url';

const root = fileURLToPath(new URL('../', import.meta.url));
const php = process.env.PHP_BIN || 'php';
test('ERR-01 read-only diagnostic policy with real PDO and PHP sessions', () => {
    const result = spawnSync(php, ['tests/error_display_mode_runtime.php'], {cwd: root, encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    assert.match(result.stdout, /\d+ checks passed/);
});

test('ERR-01 isolated MariaDB / HTTP / rendering security', {skip: !process.env.ERR01_TEST_DSN}, async t => {
    const dsn = process.env.ERR01_TEST_DSN;
    assert.match(dsn, /^mysql:.*dbname=anabelka_err01_test(?:_[a-z0-9]+)?(?:;|$)/);
    const work = fs.mkdtempSync(path.join(os.tmpdir(), 'anabelka-err01-http-'));
    const site = path.join(work, 'Anabelka');
    fs.mkdirSync(site);
    for (const name of ['app', 'routes', 'views', 'css', 'js', 'config']) fs.cpSync(path.join(root, name), path.join(site, name), {recursive: true});
    fs.mkdirSync(path.join(site, 'storage/logs'), {recursive: true});
    const sessions = path.join(work, 'sessions');
    fs.mkdirSync(sessions, {mode: 0o700});
    const env = {...process.env, ERR01_SITE_ROOT: site};
    const runPhp = code => {
        const result = spawnSync(php, ['-d', 'log_errors=1', '-d', 'error_log=/dev/stderr', '-r', code], {env, encoding: 'utf8'});
        assert.equal(result.status, 0, result.stderr || result.stdout);
        return result.stdout.trim();
    };
    const dbProgram = `require getenv('ERR01_SITE_ROOT').'/app/Core/Database.php'; $db=Database::connect();`;
    const fields = Object.fromEntries(dsn.slice(6).split(';').filter(Boolean).map(v => v.split('=')));
    assert.match(fields.dbname, /^anabelka_err01_test(?:_[a-z0-9]+)?$/);
    assert.ok(['127.0.0.1','localhost'].includes(fields.host || '127.0.0.1'), 'isolated local MariaDB only');
    const config = {host: fields.host || '127.0.0.1', port: fields.port || '3306', database: fields.dbname, charset: 'utf8mb4', username: process.env.ERR01_TEST_USER || 'root', password: process.env.ERR01_TEST_PASSWORD || ''};
    fs.writeFileSync(path.join(site, 'config/database.php'), '<?php return json_decode(' + JSON.stringify(JSON.stringify(config)).replaceAll('\\/', '/') + ', true);');
    runPhp(`${dbProgram} require getenv('ERR01_SITE_ROOT').'/app/Models/AdminAccess.php'; require getenv('ERR01_SITE_ROOT').'/app/Models/AppSetting.php'; AdminAccess::ensureSchema(); AppSetting::set('error_display_mode','production');
        $p=password_hash('err01-test-password', PASSWORD_DEFAULT);
        foreach (['owner','store_owner','administrator'] as $role) {
            $s=$db->prepare('INSERT INTO admin_users(role_id,name,email,password_hash,is_active) SELECT id,:name,:email,:password,1 FROM admin_roles WHERE slug=:role ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash),is_active=1');
            $s->execute(['name'=>$role,'email'=>$role.'@err01.invalid','password'=>$p,'role'=>$role]);
        }`);
    const port = await new Promise(resolve => { const s=net.createServer(); s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolve(p));}); });
    const logfile = fs.openSync(path.join(work, 'php-server.log'), 'a');
    const server = spawn(php, ['-d', `session.save_path=${sessions}`, '-d', 'session.use_strict_mode=1', '-S', `127.0.0.1:${port}`, '-t', work, path.join(root, 'tests/fixtures/error-display-router.php')], {env, stdio: ['ignore', logfile, logfile]});
    const url = `http://127.0.0.1:${port}/Anabelka`;
    t.after(() => {server.kill(); fs.closeSync(logfile); if (!process.env.ERR01_KEEP_TEST_FILES) fs.rmSync(work,{recursive:true,force:true});});
    let ready=false;
    for(let i=0;i<60;i++){try{await fetch(url+'/ok');ready=true;break;}catch{await new Promise(r=>setTimeout(r,50));}}
    assert.ok(ready, fs.readFileSync(path.join(work,'php-server.log'),'utf8'));
    const client = () => {
        let cookie='';
        return async (p, options={}) => {
            const headers={...options.headers,...(cookie?{cookie}:{})};
            const r=await fetch(url+p,{...options,headers,redirect:'manual'});
            for(const c of r.headers.getSetCookie()) if(c.startsWith('ANABELKA_ERR01_TEST=')) cookie=c.split(';')[0];
            const text=await r.text(); return {status:r.status,headers:r.headers,text};
        };
    };
    const owner=client(), visitor=client(), storeOwner=client(), administrator=client();
    const token = html => {const m=html.match(/name="_csrf"\s+value="([^"]+)"/);assert.ok(m,'CSRF token rendered');return m[1];};
    const post = data => ({method:'POST',headers:{'content-type':'application/x-www-form-urlencoded'},body:new URLSearchParams(data)});
    const login = async (c,role) => {const form=await c('/admin/login'); const r=await c('/admin/login',post({_csrf:token(form.text),email:role+'@err01.invalid',password:'err01-test-password'}));assert.equal(r.status,303,r.text);};
    await login(owner,'owner'); await login(storeOwner,'store_owner'); await login(administrator,'administrator');
    const mode = () => runPhp(`${dbProgram} echo $db->query("SELECT setting_value FROM app_settings WHERE setting_key='error_display_mode'")->fetchColumn();`);
    const setMode = value => runPhp(`${dbProgram} $s=$db->prepare("UPDATE app_settings SET setting_value=? WHERE setting_key='error_display_mode'");$s->execute(['${value}']);`);
    const safe = text => {for(const secret of ['ERR01_SECRET','ERR01_TOKEN','private_orders','BUYER_PRIVATE','PARTIAL_SECRET_OUTPUT',site]) assert.ok(!text.includes(secret),'leaked '+secret);};

    await t.test('settings GET exists only for developer and defaults to production',async()=>{const r=await owner('/admin/system/error-display');assert.equal(r.status,200,r.text);assert.match(r.text,/Режими відображення помилок/);assert.match(r.text,/value="production"[^>]*checked/);});
    await t.test('store_owner and administrator receive 403',async()=>{for(const c of [storeOwner,administrator]){const r=await c('/admin/system/error-display');assert.equal(r.status,403);safe(r.text);assert.ok(!r.text.includes('error-diagnostic'));}});
    await t.test('CSRF, validation and confirmation protect persistent setting',async()=>{const csrf=token((await owner('/admin/system/error-display')).text);assert.equal((await owner('/admin/system/error-display',post({mode:'development',confirmed:'1'}))).status,403);assert.equal(mode(),'production');assert.equal((await owner('/admin/system/error-display',post({_csrf:csrf,mode:'invalid',confirmed:'1'}))).status,422);assert.equal((await owner('/admin/system/error-display',post({_csrf:csrf,mode:'development'}))).status,422);assert.equal(mode(),'production');});
    await t.test('developer POST saves across a new request, with flash and audit actor/old/new',async()=>{const csrf=token((await owner('/admin/system/error-display')).text);const r=await owner('/admin/system/error-display',post({_csrf:csrf,mode:'development',confirmed:'1'}));assert.equal(r.status,303,r.text);assert.equal(mode(),'development');const page=await owner('/admin/system/error-display');const flash=page.text.match(/<script[^>]+id="anabelka-flash"[^>]*>([\s\S]*?)<\/script>/);assert.ok(flash,'branded flash payload');assert.equal(JSON.parse(flash[1])[0].message,'Режим відображення помилок збережено.');const audit=JSON.parse(runPhp(`${dbProgram} echo json_encode($db->query("SELECT admin_user_id,details FROM admin_audit_log WHERE action='system.error_display_mode.update' ORDER BY id DESC LIMIT 1")->fetch());`));assert.ok(Number(audit.admin_user_id)>0);assert.deepEqual(JSON.parse(audit.details),{old_mode:'production',new_mode:'development'});});
    await t.test('other roles cannot POST even with their own valid CSRF',async()=>{for(const c of [storeOwner,administrator]){const csrf=token((await c('/admin/login')).text);assert.equal((await c('/admin/system/error-display',post({_csrf:csrf,mode:'production',confirmed:'1'}))).status,403);}assert.equal(mode(),'development');});
    await t.test('AJAX setting validation and save keep JSON without HTML',async()=>{const csrf=token((await owner('/admin/system/error-display')).text);for(const [value,status] of [['invalid',422],['development',200]]){const options=post({_csrf:csrf,mode:value,confirmed:'1'});options.headers.accept='application/json';const r=await owner('/admin/system/error-display',options);assert.equal(r.status,status);assert.match(r.headers.get('content-type'),/application\/json/);const payload=JSON.parse(r.text);assert.equal(payload.ok,status===200);safe(r.text);}});
    await t.test('GET flags, headers and forged cookies do not grant diagnostics or mutate setting',async()=>{const r=await visitor('/explode?debug=1&admin_user_id=1',{headers:{cookie:'admin_user_id=1; admin_role_slug=owner','x-admin-role':'owner','x-debug':'1'}});assert.equal(r.status,500);assert.ok(!r.text.includes('error-diagnostic'));safe(r.text);await owner('/admin/system/error-display?mode=production');assert.equal(mode(),'development');});
    await t.test('developer exception has safe diagnostics and a journal-resolvable reference',async()=>{const r=await owner('/explode?password=ERR01_SECRET');assert.equal(r.status,500);safe(r.text);assert.match(r.text,/error-diagnostic/);assert.match(r.text,/PDOException/);assert.match(r.headers.get('cache-control'),/no-store/);assert.match(r.headers.get('x-robots-tag'),/noindex/);const ref=r.text.match(/ERR-\d{8}-\d{6}-[A-F0-9]{6}/)?.[0];assert.ok(ref);const journal=await owner('/admin/system/errors?ref='+ref);assert.equal(JSON.parse(journal.text).found,true);});
    await t.test('visitor and other roles retain safe 500 in development',async()=>{for(const c of [visitor,storeOwner,administrator]){const r=await c('/explode');assert.equal(r.status,500);safe(r.text);assert.ok(!r.text.includes('error-diagnostic'));}});
    await t.test('404 status and sanitized unknown route survive both display modes',async()=>{for(const c of [visitor,owner]){const r=await c('/catalog/ERR01_TOKEN?token=ERR01_SECRET');assert.equal(r.status,404);safe(r.text);}});
    await t.test('API, AJAX and explicit JSON Content-Type retain safe JSON and 500',async()=>{for(const [p,headers] of [['/api/explode',{}],['/explode',{accept:'application/json'}],['/explode',{'x-requested-with':'XMLHttpRequest'}],['/explicit-json',{}]]){const r=await owner(p,{headers});assert.equal(r.status,500);assert.match(r.headers.get('content-type'),/application\/json/);assert.deepEqual(JSON.parse(r.text),{ok:false,error:'Спробуйте ще раз.'});safe(r.text);}});
    await t.test('JSON denied admin route remains JSON with HTTP 403',async()=>{const r=await storeOwner('/admin/system/error-display',{headers:{accept:'application/json'}});assert.equal(r.status,403);assert.match(r.headers.get('content-type'),/application\/json/);JSON.parse(r.text);});
    await t.test('generic 403/404/500 preserve explicit JSON Content-Type',async()=>{for(const status of [403,404,500]){const r=await owner('/json-generic/'+status);assert.equal(r.status,status);assert.match(r.headers.get('content-type'),/application\/json/);assert.deepEqual(JSON.parse(r.text),{ok:false,error:'Спробуйте ще раз.'});}});
    await t.test('caught HTML 500 is safely normalized without an uncaught exception',async()=>{for(const c of [visitor,owner]){const r=await c('/caught-html');assert.equal(r.status,500);safe(r.text);assert.match(r.text,/Щось пішло не так/);}});
    await t.test('caught JSON 500 preserves structure and clears unsafe exception message',async()=>{const r=await visitor('/caught-json');assert.equal(r.status,500);safe(r.text);assert.match(r.headers.get('content-type'),/application\/json/);assert.deepEqual(JSON.parse(r.text),{success:false,message:'Спробуйте ще раз.',retry_after:5,labels:{retry:'Спробувати знову'}});});
    await t.test('production hides diagnostics from developer; invalid value also fails closed',async()=>{for(const value of ['production','bad-mode']){setMode(value);const r=await owner('/explode');assert.equal(r.status,500);assert.ok(!r.text.includes('error-diagnostic'));safe(r.text);}setMode('development');});
    await t.test('safe examples/test section do not create new journal records',async()=>{const before=fs.readdirSync(path.join(site,'storage/logs')).map(f=>fs.readFileSync(path.join(site,'storage/logs',f),'utf8')).join('');const r=await owner('/admin/system/error-test');assert.equal(r.status,200);assert.match(r.text,/приклад|Приклад/);const after=fs.readdirSync(path.join(site,'storage/logs')).map(f=>fs.readFileSync(path.join(site,'storage/logs',f),'utf8')).join('');assert.equal(after,before);});
    await t.test('ordinary successful public request has no setting query',async()=>{const r=await visitor('/ok');assert.equal(r.status,200);assert.equal(r.text,'OK');assert.equal(r.headers.get('x-err01-setting-reads'),'0');});
    await t.test('closed session, unavailable MariaDB and missing config fail closed',async()=>{const saved=fs.readFileSync(path.join(site,'config/database.php'));for(const p of ['/no-session','/db-down','/missing-config']){try{const r=await owner(p);assert.equal(r.status,500);safe(r.text);assert.ok(!r.text.includes('error-diagnostic'),p);}finally{fs.writeFileSync(path.join(site,'config/database.php'),saved);fs.rmSync(path.join(site,'config/database.err01-disabled'),{force:true});}}});
    await t.test('broken view and unwritable log fall back atomically without recursion',async()=>{const view=path.join(site,'views/errors/public.php'),saved=fs.readFileSync(view);try{const r=await owner('/bad-view');assert.equal(r.status,500);safe(r.text);assert.equal((r.text.match(/<!doctype html>/ig)||[]).length,1);}finally{fs.writeFileSync(view,saved);}try{const r=await owner('/bad-log');assert.equal(r.status,500);safe(r.text);assert.ok(!r.text.includes('error-diagnostic'),'failed handler must use safe fallback');}finally{fs.rmSync(path.join(site,'storage/logs'),{force:true});fs.renameSync(path.join(site,'storage/logs.err01-disabled'),path.join(site,'storage/logs'));}});
    await t.test('fatal PHP failure retains HTTP 500 and removes partial output',async()=>{const r=await visitor('/fatal');assert.equal(r.status,500);safe(r.text);assert.match(r.text,/Щось пішло не так/);});
    await t.test('failure before session/public-renderer startup uses safe HTML or JSON',async()=>{for(const headers of [{},{accept:'application/json'}]){const r=await owner('/early-error',{headers});assert.equal(r.status,500);safe(r.text);assert.ok(!r.text.includes('error-diagnostic'));if(headers.accept)assert.deepEqual(JSON.parse(r.text),{ok:false,error:'Спробуйте ще раз.'});else assert.match(r.text,/Щось пішло не так/);}});
    await t.test('emergency logging never creates missing app_settings table',async()=>{runPhp(`${dbProgram} $db->exec('RENAME TABLE app_settings TO app_settings_err01_saved');`);try{const r=await owner('/explode');assert.equal(r.status,500);safe(r.text);assert.ok(!r.text.includes('error-diagnostic'));assert.equal(runPhp(`${dbProgram} echo $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='app_settings'")->fetchColumn();`),'0');}finally{runPhp(`${dbProgram} $db->exec('RENAME TABLE app_settings_err01_saved TO app_settings');`);}});
    if(process.env.ERR01_KEEP_TEST_FILES) console.log('ERR01_HTTP_SITE='+site+' ERR01_HTTP_URL='+url);
});
