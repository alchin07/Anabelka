import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import {fileURLToPath} from 'node:url';

const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read=p=>fs.readFileSync(path.join(root,p), 'utf8');

test('both order forms use one native validator without bypassing HTML5 constraints', () => {
    const script=read('js/checkout-validation.js');
    const quick=read('views/order/quick.php');
    const checkout=read('views/order/checkout.php');
    assert.match(script,/form\[action="\/Anabelka\/checkout"\]/);
    assert.match(script,/form\[action="\/Anabelka\/quick-order"\]/);
    assert.match(script,/addEventListener\('invalid',[\s\S]*?, true\)/);
    assert.match(script, /event\.preventDefault\(\)/);
    assert.doesNotMatch(script, /form\.noValidate|novalidate/);
    assert.match(quick,/name="customer_name"[\s\S]*?required/);
    assert.match(quick,/name="customer_phone"[\s\S]*?required/);
    assert.doesNotMatch(quick,/\bnovalidate\b/);
    assert.match(quick,/checkout-validation\.js\?v=3/);
    assert.match(checkout,/checkout-validation\.js\?v=3/);
    assert.match(quick,/checkout-validation\.css\?v=3/);
    assert.match(quick,/JSON_HEX_TAG/);
    assert.ok(quick.includes('<body class="checkout-validation-page">'));
    assert.ok(checkout.includes('<body class="checkout-validation-page">'));
    const css = read('css/checkout-validation.css');
    assert.ok(css.includes('body.checkout-validation-page #site-message.anabelka-notify.is-warning'));
    assert.ok(css.includes('background: #8A2BE2;'));
    assert.ok(css.includes('color: #fff;'));
});

test('quick order empty phone displays a branded inline error and one toast', () => {
    const script=read('js/checkout-validation.js');
    const events={},timers=[],toasts=[],children=[];
    const classes={add(){},remove(){}};
    const attrs=new Map();
    const parent={classList:classes,appendChild(node){children.push(node)}};
    const phone={name:'customer_phone',type:'tel',willValidate:true,
        validity:{valueMissing:true,typeMismatch:false,valid:false},
        parentElement:parent,classList:classes,closest(){return null;},
        getAttribute(k){return attrs.get(k)||null},
        setAttribute(k,v){attrs.set(k,v)},
        removeAttribute(k){attrs.delete(k)}
    };
    const form={addEventListener(name,listener){events[name]=listener}};
    const document={
        querySelector(selector){
            return selector==='form[action="/Anabelka/quick-order"]' ? form : null;
        },
        getElementById(id){
            if(id==='checkout-validation-messages')
                return {textContent:JSON.stringify({required:'Заповніть поле «{field}».',
                    labels:{customer_phone:'Номер телефону'}})};
            return null;
        },
        createElement(){
            return {id:'',className:'',textContent:'',setAttribute(){},
                remove(){this.removed=true}};
        }
    };
    const window={setTimeout(callback){timers.push(callback)},
        AnabelkaNotify:{warning(text){toasts.push(text)}}};
    let focused = 0;
    phone.focus=function () {focused++;};
    vm.runInNewContext(script,{document,window});
    let prevented = false;
    events.invalid({target:phone,preventDefault(){prevented=true;}});
    assert.equal(prevented,true);
    assert.equal(children.length,1);
    assert.equal(children[0].textContent,'Заповніть поле «Номер телефону».');
    assert.equal(phone.getAttribute('aria-invalid'),'true');
    assert.equal(toasts.length,0);
    timers.shift()();
    assert.equal(focused,1);
    assert.deepEqual(toasts,['Заповніть поле «Номер телефону».']);
    phone.validity.valid=true;
    events.input({target:phone});
    assert.equal(children[0].removed,true);
    assert.equal(phone.getAttribute('aria-invalid'),null);
});

test('quick order server validation and order creation are untouched', () => {
    const controller=read('app/Controllers/QuickOrderController.php');
    assert.match(controller,/\$customerName === '' \|\| \$customerPhone === ''/);
    assert.match(controller,/QuickOrder::create\(/);
    assert.match(controller,/Cart::clearByUserId\(/);
});
