import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import {fileURLToPath} from 'node:url';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');
function classes() {
    const names=new Set();
    return {add:k=>names.add(k),remove:k=>names.delete(k),contains:k=>names.has(k)};
}
function parent() {
    const children=[];
    return {children,classList:classes(),appendChild:node=>children.push(node),querySelector:()=>null};
}
function field(name,node,missing=true) {
    const attrs=new Map();
    return {name,type:name==='customer_email'?'email':'text',willValidate:true,
        validity:{valueMissing:missing,valid:false,typeMismatch:!missing},
        parentElement:node,classList:classes(),closest:()=>null,
        setAttribute:(k,v)=>attrs.set(k,v),getAttribute:k=>attrs.get(k)??null,
        removeAttribute:k=>attrs.delete(k)};
}
test('invalid native fields show adjacent notes and only one toast per attempt',()=>{
    const view=read('views/order/checkout.php');
    assert.doesNotMatch(view,/\bnovalidate\b/i);
    assert.match(view,/name="customer_email"[\s\S]{0,110}required/);
    assert.match(view,/name="delivery_address"[\s\S]{0,500}required/);
    assert.match(view,/id="checkout-validation-messages"/);
    assert.match(view,/checkout-validation\.js\?v=3/);
    assert.match(view,/checkout-validation\.css\?v=2/);
    const script=read('js/checkout-validation.js');
    assert.match(script,/addEventListener\('invalid',[\s\S]+?, true\)/);
    assert.match(script,/event\.preventDefault\(\)/);
    assert.doesNotMatch(script,/form\.noValidate\s*=|novalidate/);
    const events={},timers=[],warnings=[],emailBox=parent(),cityBox=parent();
    const email=field('customer_email',emailBox),city=field('delivery_city',cityBox);
    const form={querySelector:()=>null,addEventListener:(name,fn)=>{events[name]=fn;}};
    const document={
        querySelector:selector=>selector==='form[action="/Anabelka/checkout"]'?form:null,
        getElementById:id=>id==='checkout-validation-messages'
          ? {textContent:JSON.stringify({required:'Заповніть поле «{field}».',
                labels:{customer_email:'Email',delivery_city:'Місто'}})}:null,
        createElement:()=>({setAttribute(){},remove(){this.removed=true;},textContent:''})
    };
    const window={setTimeout:callback=>timers.push(callback),
        AnabelkaNotify:{warning:msg=>warnings.push(msg)}};
    vm.runInNewContext(script,{document,window},{filename:'js/checkout-validation.js'});
    let preventedCount=0;
    const invalidEvent=target=>({target,preventDefault(){preventedCount++;}});
    events.invalid(invalidEvent(email));events.invalid(invalidEvent(city));
    assert.equal(preventedCount,2);
    assert.equal(emailBox.children.length,1);assert.equal(cityBox.children.length,1);
    assert.equal(emailBox.children[0].textContent,'Заповніть поле «Email».');
    assert.equal(warnings.length,0);
    timers.shift()();
    assert.deepEqual(warnings,['Заповніть поле «Email».']);
    email.validity.valid=true;events.input({target:email});
    assert.equal(emailBox.children[0].removed,true);
    assert.equal(email.getAttribute('aria-invalid'),null);
    city.validity.valid=true;events.input({target:city});
    assert.equal(cityBox.children[0].removed,true);
});
test('dynamic radio and optional delivery field validation remain supported',()=>{
    const js=read('js/checkout-validation.js');
    const view=read('views/order/checkout.php');
    const backend=read('app/Controllers/OrderController.php');
    for(const name of ['delivery_method','delivery_service','delivery_service_option',
        'delivery_option_input','delivery_address'])assert.ok(js.includes(name));
    assert.match(view,/name="delivery_method"[\s\S]{0,250}required/);
    assert.match(backend,/FILTER_VALIDATE_EMAIL/);
    assert.match(backend,/requiredDeliveryFields/);
    assert.match(read('css/checkout-validation.css'),/#8A2BE2/);
    assert.doesNotMatch(read('css/checkout-validation.css'),/#b63e48|#e53935/i);
});
