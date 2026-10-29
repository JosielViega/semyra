'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const createScreenShare = require('../../public/assets/js/room-screen-share.js');

global.CustomEvent = class CustomEvent { constructor(type, options) { this.type = type; this.detail = options?.detail; } };
const node = () => ({disabled: false, checked: false, hidden: false, value: '', src: '', textContent: '', children: [], listeners: new Map(), dataset: {},
    classList: {toggle() {}}, addEventListener(type, fn) { this.listeners.set(type, fn); },
    setAttribute(name, value) { this[name] = value; },
    replaceChildren() { this.children = []; }, append(...items) { this.children.push(...items); },
    querySelector(selector) { const all=[]; const walk=(item)=>{all.push(item);for(const child of item.children??[])walk(child);};for(const child of this.children)walk(child);
        return selector === 'input:checked' ? all.find(x => x.type === 'radio' && x.checked) ?? null
            : selector === 'input' ? all.find(x => x.type === 'radio') ?? null : null; }});

function harness({expiresAt = '2099-01-01T00:00:00Z'} = {}) {
    const shell = {dataset: {screenTransmissionUrl: '/screen', screenHeartbeatUrl: '/heartbeat', screenStopUrl: '/stop',
        desktopHostSessionUrl: '/host-session', csrfToken: 'csrf'}};
    const panel=node(), button=node(), targetList=node(), quality=node(), audio=node(), status=node();
    const previewFrame=node(), previewImage=node(), previewPlaceholder=node(), previewMeta=node(); quality.value='standard'; audio.checked=true;
    const documentListeners=new Map(), windowListeners=new Map(), calls=[];
    const document = {querySelector(s) { return ({'[data-room-shell]':shell,'[data-screen-source-panel]':panel,
        '[data-screen-share-start]':button,'[data-screen-targets]':targetList,'[data-screen-share-quality]':quality,
        '[data-screen-share-audio]':audio,'[data-screen-share-status]':status,'[data-screen-preview-frame]':previewFrame,
        '[data-screen-preview-image]':previewImage,'[data-screen-preview-placeholder]':previewPlaceholder,
        '[data-screen-preview-meta]':previewMeta})[s] ?? null; },
        createElement() { return node(); }, addEventListener(t,f){documentListeners.set(t,f);}, dispatchEvent(e){documentListeners.get(e.type)?.(e);} };
    const window = {location:{pathname:'/room/ROOM1234'}, SemyraDesktopBridge:{getHostSnapshot:()=>({state:'ready',capabilities:['screen.targets','screen.publish','screen.audio']})},
        addEventListener(t,f){windowListeners.set(t,f);}, removeEventListener(t,f){if(windowListeners.get(t)===f)windowListeners.delete(t);},
        dispatchEvent(e){calls.push({type:'event',event:e}); windowListeners.get(e.type)?.(e);},
        setTimeout(fn,delay){calls.push({type:'timer',fn,delay});return calls.length;}, clearTimeout(){}};
    const transmission={source:'screen',media_mode:'live',instance_id:'a'.repeat(32),revision:3,is_owner:true};
    const fetch=async(url,options)=>{calls.push({type:'fetch',url,options,body:new URLSearchParams(options.body)});
        if(url==='/screen') return {status:201,ok:true,json:async()=>({transmission,
            host_session_token:`${'b'.repeat(32)}.${'c'.repeat(64)}`,permission:'screen.publish',expires_at:expiresAt})};
        if(url==='/host-session') return {status:201,ok:true,json:async()=>({
            host_session_token:`${'d'.repeat(32)}.${'e'.repeat(64)}`,permission:'screen.publish',
            expires_at:new Date(Date.parse(expiresAt)+60000).toISOString(),
            transmission_instance_id:transmission.instance_id,transmission_revision:transmission.revision})};
        return {status:200,ok:true,json:async()=>({ok:true})};};
    const navigator={sendBeacon(){return true;}};
    const controller=createScreenShare({window,document,navigator,fetch});
    function answer(action, detail) { windowListeners.get('semyra:screen-result')?.(new CustomEvent('semyra:screen-result',{detail:{action,ok:true,...detail}})); }
    return {controller,window,windowListeners,documentListeners,calls,targetList,audio,status,previewFrame,previewImage,previewPlaceholder,previewMeta,answer};
}

function selectFirst(h) { const input=h.targetList.querySelector('input'); input.checked=true; return input; }

test('uses only native Desktop commands and never getDisplayMedia', async () => {
    const h=harness();
    const targetPromise=h.controller.loadTargets();
    h.answer('targets',{targets:[{id:'d'.repeat(32),type:'monitor',name:'Principal',width:1920,height:1080,available:true}]});
    await targetPromise;
    assert.equal(h.targetList.children.length,1);
    assert.equal(h.targetList.children[0].children[0].textContent,'Monitores');
    assert.equal(h.targetList.children[0].children[1].children[0].children[2].children[0].textContent,'Principal');
    selectFirst(h);
    const startPromise=h.controller.start();
    await new Promise(resolve=>setImmediate(resolve));
    h.windowListeners.get('semyra:host-authorized')?.(new CustomEvent('semyra:host-authorized',{detail:{permission:'screen.publish',transmissionInstanceId:'a'.repeat(32),transmissionRevision:3}}));
    await new Promise(resolve=>setImmediate(resolve));
    h.answer('start',{state:'streaming',audio:true,encoder:'nvh264enc'});
    await startPromise;
    assert.equal(h.controller.state().phase,'publishing');
    assert.equal(h.calls.some(x=>JSON.stringify(x).includes('getDisplayMedia')),false);
    const nativeStart=h.calls.find(x=>x.type==='event'&&x.event.type==='semyra:screen-request'&&x.event.detail.action==='start');
    assert.equal(nativeStart.event.detail.targetId,'d'.repeat(32));
    assert.equal(nativeStart.event.detail.quality,'standard');
    assert.equal(nativeStart.event.detail.audio,true);
});

test('screen owns one Host Session flow and renews the active fenced authorization', async () => {
    const expiresAt=new Date(Date.now()+120000).toISOString();
    const h=harness({expiresAt});
    const targetPromise=h.controller.loadTargets();
    h.answer('targets',{targets:[{id:'d'.repeat(32),type:'monitor',name:'Principal',width:1920,height:1080,available:true}]});
    await targetPromise; selectFirst(h);
    const startPromise=h.controller.start(); await new Promise(resolve=>setImmediate(resolve));
    h.windowListeners.get('semyra:host-authorized')?.(new CustomEvent('semyra:host-authorized',{detail:{
        permission:'screen.publish',transmissionInstanceId:'a'.repeat(32),transmissionRevision:3}}));
    await new Promise(resolve=>setImmediate(resolve));
    h.answer('start',{state:'streaming',audio:false,encoder:'x264enc'}); await startPromise;

    const renewal=h.calls.find(call=>call.type==='timer'&&call.delay>60000&&call.delay<110000);
    assert.ok(renewal,'renewal must be scheduled before expiry');
    renewal.fn(); await new Promise(resolve=>setImmediate(resolve)); await new Promise(resolve=>setImmediate(resolve));
    h.windowListeners.get('semyra:host-authorized')?.(new CustomEvent('semyra:host-authorized',{detail:{
        permission:'screen.publish',transmissionInstanceId:'a'.repeat(32),transmissionRevision:3}}));
    await new Promise(resolve=>setImmediate(resolve));

    const hostRequests=h.calls.filter(call=>call.type==='fetch'&&call.url==='/host-session');
    assert.equal(hostRequests.length,1);
    assert.equal(hostRequests[0].body.get('transmission_instance_id'),'a'.repeat(32));
    assert.equal(hostRequests[0].body.get('transmission_revision'),'3');
    assert.equal(h.calls.filter(call=>call.type==='event'&&call.event.type==='semyra:host-authorization-request').length,2);
});

test('shows connecting state and does not claim success before native readiness', async () => {
    const h=harness();
    const targetPromise=h.controller.loadTargets(); h.answer('targets',{targets:[{id:'d'.repeat(32),type:'monitor',name:'Principal',width:1920,height:1080,available:true}]}); await targetPromise;
    selectFirst(h);
    const startPromise=h.controller.start(); await new Promise(resolve=>setImmediate(resolve));
    h.windowListeners.get('semyra:host-authorized')?.(new CustomEvent('semyra:host-authorized',{detail:{permission:'screen.publish',transmissionInstanceId:'a'.repeat(32),transmissionRevision:3}}));
    await new Promise(resolve=>setImmediate(resolve));
    h.windowListeners.get('semyra:screen-state')?.(new CustomEvent('semyra:screen-state',{detail:{state:'connecting'}}));
    assert.equal(h.controller.state().phase,'connecting');
    assert.match(h.status.textContent,/Conectando/);
    h.answer('start',{state:'streaming',audio:false,encoder:'x264enc'}); await startPromise;
    assert.equal(h.controller.state().phase,'publishing');
});

test('keeps the canonical transmission alive while native publishing is still starting', async () => {
    const h=harness();
    const targetPromise=h.controller.loadTargets();
    h.answer('targets',{targets:[{id:'d'.repeat(32),type:'monitor',name:'Principal',width:1920,height:1080,available:true}]});
    await targetPromise; selectFirst(h);

    const startPromise=h.controller.start();
    await new Promise(resolve=>setImmediate(resolve));

    assert.equal(h.controller.state().phase,'authorizing');
    const heartbeat=h.calls.find(call=>call.type==='timer'&&call.delay===1500);
    assert.ok(heartbeat,'the owner lease must start before Host authorization and LiveKit readiness');
    heartbeat.fn();
    await new Promise(resolve=>setImmediate(resolve));
    const requests=h.calls.filter(call=>call.type==='fetch'&&call.url==='/heartbeat');
    assert.equal(requests.length,1);
    assert.equal(requests[0].body.get('transmission_instance_id'),'a'.repeat(32));
    assert.equal(requests[0].body.get('transmission_revision'),'3');

    h.windowListeners.get('semyra:host-authorized')?.(new CustomEvent('semyra:host-authorized',{detail:{
        permission:'screen.publish',transmissionInstanceId:'a'.repeat(32),transmissionRevision:3}}));
    await new Promise(resolve=>setImmediate(resolve));
    h.answer('start',{state:'streaming',audio:false,encoder:'x264enc'});
    await startPromise;
    assert.equal(h.controller.state().phase,'publishing');
});

test('ignores stale pre-start polling but fences a newer transmission replacement', async () => {
    const h=harness();
    const targetPromise=h.controller.loadTargets();
    h.answer('targets',{targets:[{id:'d'.repeat(32),type:'monitor',name:'Principal',width:1920,height:1080,available:true}]});
    await targetPromise; selectFirst(h);

    const staleRequestStartedAt=Date.now()-1000;
    const startPromise=h.controller.start();
    await new Promise(resolve=>setImmediate(resolve));
    h.documentListeners.get('semyra:transmission-updated')?.(new CustomEvent('semyra:transmission-updated',{detail:{
        transmission:null,requestStartedAt:staleRequestStartedAt}}));
    assert.equal(h.controller.state().active.instanceId,'a'.repeat(32));
    assert.equal(h.calls.some(call=>call.type==='event'&&call.event.type==='semyra:screen-request'
        &&call.event.detail.action==='stop'),false);

    h.documentListeners.get('semyra:transmission-updated')?.(new CustomEvent('semyra:transmission-updated',{detail:{
        transmission:{instanceId:'f'.repeat(32),revision:4},requestStartedAt:Date.now()+1000}}));
    await new Promise(resolve=>setImmediate(resolve));
    assert.equal(h.controller.state().active,null);
    const stopEvent=h.calls.find(call=>call.type==='event'&&call.event.type==='semyra:screen-request'
        &&call.event.detail.action==='stop');
    assert.ok(stopEvent,'a newer canonical replacement must still stop the stale native attempt');
    h.answer('stop',{state:'idle'});
    h.windowListeners.get('semyra:host-authorized')?.(new CustomEvent('semyra:host-authorized',{detail:{
        permission:'screen.publish',transmissionInstanceId:'a'.repeat(32),transmissionRevision:3}}));
    await startPromise;
});

test('browser without native capability cannot expose publishing', async () => {
    const h=harness(); h.window.SemyraDesktopBridge.getHostSnapshot=()=>({state:'ready',capabilities:[]});
    h.windowListeners.get('semyra:host-ready')?.(new CustomEvent('semyra:host-ready',{detail:{state:'ready',capabilities:[]}}));
    assert.match(h.status.textContent,/Desktop/i);
    assert.equal(h.controller.state().targets.length,0);
});

test('cleanup stops native generation and sends fenced server stop', async () => {
    const h=harness();
    const targetPromise=h.controller.loadTargets(); h.answer('targets',{targets:[{id:'d'.repeat(32),type:'monitor',name:'Principal',width:1920,height:1080,available:true}]}); await targetPromise;
    selectFirst(h);
    const startPromise=h.controller.start(); await new Promise(resolve=>setImmediate(resolve));
    h.windowListeners.get('semyra:host-authorized')?.(new CustomEvent('semyra:host-authorized',{detail:{permission:'screen.publish',transmissionInstanceId:'a'.repeat(32),transmissionRevision:3}}));
    await new Promise(resolve=>setImmediate(resolve)); h.answer('start',{state:'streaming',audio:false,encoder:'x264enc'}); await startPromise;
    const stopPromise=h.controller.cleanup(true); await new Promise(resolve=>setImmediate(resolve)); h.answer('stop',{state:'idle'}); await stopPromise;
    const stop=h.calls.find(x=>x.type==='fetch'&&x.url==='/stop');
    assert.equal(stop.body.get('transmission_instance_id'),'a'.repeat(32));
    assert.equal(stop.body.get('transmission_revision'),'3');
});

test('unexpected native pipeline failure fences and removes the active transmission', async () => {
    const h=harness();
    const targetPromise=h.controller.loadTargets(); h.answer('targets',{targets:[{id:'d'.repeat(32),type:'monitor',name:'Principal',width:1920,height:1080,available:true}]}); await targetPromise;
    selectFirst(h);
    const startPromise=h.controller.start(); await new Promise(resolve=>setImmediate(resolve));
    h.windowListeners.get('semyra:host-authorized')?.(new CustomEvent('semyra:host-authorized',{detail:{permission:'screen.publish',transmissionInstanceId:'a'.repeat(32),transmissionRevision:3}}));
    await new Promise(resolve=>setImmediate(resolve)); h.answer('start',{state:'streaming',audio:true,encoder:'mfh264enc'}); await startPromise;

    h.windowListeners.get('semyra:screen-state')?.(new CustomEvent('semyra:screen-state',{detail:{state:'failed',errorCode:'screen_pipeline_exited'}}));
    await new Promise(resolve=>setImmediate(resolve)); h.answer('stop',{state:'idle'});
    await new Promise(resolve=>setImmediate(resolve));

    assert.equal(h.controller.state().phase,'error');
    assert.ok(h.calls.some(x=>x.type==='fetch'&&x.url==='/stop'));
});

test('loads only the selected native preview and discards a stale response', async () => {
    const h=harness();
    const targetPromise=h.controller.loadTargets();
    h.answer('targets',{targets:[
        {id:'d'.repeat(32),type:'monitor',name:'Principal',width:1920,height:1080,available:true},
        {id:'e'.repeat(32),type:'window',name:'Janela completa',width:1280,height:720,available:true},
    ]});
    await targetPromise;
    const first=h.targetList.children[0].children[1].children[0].children[0];
    first.checked=true;
    const firstPreview=h.controller.loadPreview(h.controller.state().targets[0]);
    h.controller.cancelPreview(false);
    h.answer('preview',{state:'available',dataUrl:'data:image/jpeg;base64,ZmFrZQ==',width:400,height:225});
    await firstPreview;
    assert.notEqual(h.previewFrame.dataset.state,'available');

    const second=h.controller.state().targets[1];
    const secondPreview=h.controller.loadPreview(second);
    h.answer('preview',{state:'available',dataUrl:'data:image/jpeg;base64,ZmFrZQ==',width:400,height:225});
    await secondPreview;
    assert.notEqual(h.previewFrame.dataset.state,'available', 'a preview for an unselected target must not replace the UI');

    const secondInput=h.targetList.children[1].children[1].children[0].children[0];
    first.checked=false; secondInput.checked=true;
    const selectedPreview=h.controller.loadPreview(second);
    h.answer('preview',{state:'available',dataUrl:'data:image/jpeg;base64,ZmFrZQ==',width:400,height:225});
    await selectedPreview;
    assert.equal(h.previewFrame.dataset.state,'available');
    assert.equal(h.previewImage.src,'data:image/jpeg;base64,ZmFrZQ==');
    assert.match(h.previewMeta.textContent,/Janela completa/);
});
