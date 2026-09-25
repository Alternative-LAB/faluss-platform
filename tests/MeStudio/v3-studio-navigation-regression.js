'use strict';
// Browser integration of the real PHP views; HTTP/persistence are simulated, no WordPress access.
const fs=require('fs'),path=require('path'),cp=require('child_process'),assert=require('assert/strict');
const {chromium,webkit}=require('playwright');
const root=path.resolve(__dirname,'../..'),out=path.join(root,'docs/evidence/me-v3-054');
const views=JSON.parse(cp.execFileSync(process.env.FALUSS_PHP,[path.join(__dirname,'v3-composition-regression.php')],{encoding:'utf8',env:{...process.env,FALUSS_V3_VIEWS:'1'}}));
const css=fs.readFileSync(path.join(root,'assets/me-studio/css/studio-v3.css'),'utf8');
const js=fs.readFileSync(path.join(root,'assets/me-studio/js/studio-v3.js'),'utf8');
(async()=>{
 fs.mkdirSync(out,{recursive:true});const results=[];
 for(const [engine,type,options] of [['chromium',chromium,{executablePath:process.env.FALUSS_CHROME}],['webkit',webkit,{}]]){
  const browser=await type.launch({headless:true,...options});
  try{
   for(const width of [320,390,768]){
    const context=await browser.newContext({viewport:{width,height:844}}),page=await context.newPage();
    let documentRequests=0,confirmationCount=0,allow=false,failRead=false,failSave=false,delayRead=false,canonicalName='',posts=[],holdPost=null,holdRead=null;
    const errors=[];page.on('pageerror',e=>errors.push(e.message));page.on('dialog',async d=>{confirmationCount++;await(allow?d.accept():d.dismiss());});
    const html=section=>{
     let view=views[section].replaceAll('https://faluss.test','https://studio.test');
     if(canonicalName)view=view.replace(/(name="display_name"[^>]*value=")[^"]*/g,'$1'+canonicalName);
     return `<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}</style>${view}<script>window.documentMarker=crypto.randomUUID();window.falussStudioV3={ajaxUrl:'https://studio.test/wp-admin/admin-ajax.php',studioNonce:'nonce-faluss_studio_v3_save'};</script><script>${js}</script>`;
    };
    await context.route('**/*',async route=>{
     const req=route.request(),url=new URL(req.url());
     if(url.hostname!=='studio.test')return route.abort();
     if(url.pathname==='/wp-admin/admin-ajax.php'){
      const data=new URLSearchParams(req.postData());posts.push(data);if(holdPost)await holdPost;
      if(data.get('action')==='faluss_studio_v3_save'&&!failSave)canonicalName='Nom canonique';
      return route.fulfill({status:failSave?409:200,contentType:'application/json',body:JSON.stringify(failSave?{success:false,data:{code:'stale_version'}}:{success:true,data:{id:78}})});
     }
     if(url.pathname==='/studio/'){
      if(req.isNavigationRequest())documentRequests++;
      const section=url.searchParams.get('v3_section')||'v3_links';
      if(holdRead&&!req.isNavigationRequest())await holdRead;
      if(failRead&&!req.isNavigationRequest())return route.fulfill({status:503,body:'Unavailable'});
      if(delayRead&&section==='v3_colors')await new Promise(r=>setTimeout(r,150));
      return route.fulfill({contentType:'text/html',body:html(section)});
     }
     return route.abort();
    });
    await page.goto('https://studio.test/studio/?v3_section=v3_identity');
    const marker=await page.evaluate(()=>documentMarker);
    const step=async value=>page.waitForFunction(s=>document.querySelector('[data-faluss-studio-v3]').dataset.step===s,value);
    const bottom=label=>page.locator('.faluss-studio-v3__bottom a').filter({hasText:label});
    const tab=label=>page.locator('.faluss-studio-v3__tabs a').filter({hasText:label});
    const name=page.locator('[name=display_name]'),initial=await name.inputValue();
    await page.evaluate(()=>window.bottomPill=document.querySelector('.faluss-studio-v3__bottom [data-studio-pill]'));
    await name.fill('Non enregistré');await bottom('Design').click();assert.equal(confirmationCount,1);assert.equal(await name.inputValue(),'Non enregistré');
    await name.fill(initial);await bottom('Design').click();await step('v3_colors');assert.equal(confirmationCount,1);
    assert.equal(await page.evaluate(()=>window.bottomPill===document.querySelector('.faluss-studio-v3__bottom [data-studio-pill]')),true);
    assert(await page.locator('.faluss-studio-v3__bottom [data-studio-pill]').evaluate(e=>e.getAnimations().some(a=>a.transitionProperty==='transform')),'Bottom pill actually transitions');
    assert.equal(await page.locator('.faluss-studio-v3__bottom [data-studio-pill]').evaluate(e=>getComputedStyle(e).backgroundColor),'rgb(237, 67, 67)');
    const transform=await page.locator('.faluss-studio-v3__bottom [data-studio-pill]').evaluate(e=>e.style.transform);assert.notEqual(transform,'translateX(0px)');
    assert.match(await page.locator('.faluss-studio-v3__bottom [data-studio-pill]').evaluate(e=>getComputedStyle(e).transitionProperty),/transform/);
    await tab('Nom').click();await step('v3_name');assert.match(page.url(),/v3_name/);
    assert(await page.locator('.faluss-studio-v3__tabs [data-studio-pill]').evaluate(e=>e.getAnimations().some(a=>a.transitionProperty==='transform')),'Context pill actually transitions');
    await page.goBack();await step('v3_colors');await page.goForward();await step('v3_name');
    await bottom('Profil').click();await step('v3_identity');await name.fill('Brouillon conservé');
    const beforeBack=page.url();await page.goBack();await page.waitForURL(beforeBack);assert.equal(await name.inputValue(),'Brouillon conservé');
    await name.fill(initial);await name.fill('Soumis');await page.locator('[data-v3-primary]').click();
    await page.waitForFunction(()=>document.querySelector('[name=display_name]').value==='Nom canonique');
    assert.equal(posts.at(-1).get('nonce'),'nonce-faluss_studio_v3_save');
    if(width===390){
     let release;holdPost=new Promise(r=>release=r);
     await name.fill('Sauvegarde en cours');const sent=page.waitForRequest(r=>r.method()==='POST');await page.locator('[data-v3-primary]').click();await sent;
     await bottom('Design').click();assert.match(page.url(),/v3_identity/);assert.equal(await name.inputValue(),'Sauvegarde en cours');
     let releaseRead;holdRead=new Promise(r=>releaseRead=r);const reading=page.waitForRequest(r=>r.method()==='GET'&&r.url().includes('/studio/'));
     failRead=true;release();holdPost=null;await reading;const saveUrl=page.url();await page.goBack();await page.waitForURL(saveUrl);releaseRead();holdRead=null;await page.locator('[data-studio-refresh]').waitFor();assert.equal(await page.locator('[data-v3-primary]').isDisabled(),true);
     const count=posts.length;failRead=false;await page.locator('[data-studio-refresh]').click();await page.waitForFunction(()=>document.querySelector('[name=display_name]').value==='Nom canonique');assert.equal(posts.length,count,'Read retry must not repeat mutation');
     holdPost=new Promise(r=>release=r);const upload=page.waitForRequest(r=>r.method()==='POST');
     await page.locator('[data-v3-upload]').setInputFiles({name:'client-only.png',mimeType:'image/png',buffer:Buffer.from('client fixture; server upload is mocked')});await upload;
     const priorDialog=confirmationCount;await bottom('Design').click();assert.match(page.url(),/v3_identity/);assert.equal(confirmationCount,priorDialog);
     release();holdPost=null;await page.waitForFunction(()=>document.querySelector('[name=avatar_attachment_id]').value==='78');
     // Discard this deliberately simulated upload through the explicit dirty confirmation.
     allow=true;await bottom('Design').click();await step('v3_colors');await bottom('Profil').click();await step('v3_identity');allow=false;
    }
    const prior=confirmationCount;await bottom('Liens').click();await step('v3_links');assert.equal(confirmationCount,prior);
    await tab('Collections').click();await step('v3_collections');
    const editor=page.locator('[data-mutation=create_collection]');await editor.locator('summary').click();await editor.locator('[data-field=name]').fill('Collection');
    failSave=true;await editor.locator('[data-v3-save-item]').click();await page.locator('[data-v3-error]').waitFor({state:'visible'});
    assert.equal(posts.at(-1).get('action'),'faluss_studio_v3_manage');assert.equal(await editor.locator('[data-field=name]').inputValue(),'Collection');
    allow=true;failRead=true;await bottom('Profil').click();await page.waitForFunction(()=>document.querySelector('[data-v3-error]').textContent.includes('503'));
    assert.equal(await editor.locator('[data-field=name]').inputValue(),'Collection');assert.match(page.url(),/v3_collections/);
    failRead=false;failSave=false;await bottom('Profil').click();await step('v3_identity');
    delayRead=true;await bottom('Design').click();await bottom('Liens').click();await step('v3_links');await page.waitForTimeout(200);await step('v3_links');delayRead=false;
    await bottom('Liens').focus();await page.keyboard.press('ArrowRight');assert.equal(await page.locator(':focus').innerText(),'Design');await page.keyboard.press('Enter');await step('v3_colors');
    assert.equal(await page.locator('.faluss-studio-v3__bottom [aria-current]').innerText(),'Design');
    assert.equal(await page.locator('.faluss-studio-v3__bottom [aria-disabled=true]').innerText(),'Shop');
    assert.equal(await page.evaluate(()=>documentMarker),marker);assert.equal(documentRequests,1);assert.deepEqual(errors,[]);
    await page.waitForTimeout(220);
    for(const selector of ['.faluss-studio-v3__bottom','.faluss-studio-v3__tabs']){
     const rects=await page.locator(selector).evaluate(n=>{const a=n.querySelector('[aria-current]').getBoundingClientRect(),p=n.querySelector('[data-studio-pill]').getBoundingClientRect();return {a:a.x,p:p.x,aw:a.width,pw:p.width};});
     assert(Math.abs(rects.a-rects.p)<1&&Math.abs(rects.aw-rects.pw)<1,'Pill aligns with selected label');
    }
    if(width===390)await page.screenshot({path:path.join(out,engine+'-studio-design-390.png')});
    await page.emulateMedia({reducedMotion:'reduce'});assert.equal(await page.locator('.faluss-studio-v3__bottom [data-studio-pill]').evaluate(e=>getComputedStyle(e).transitionDuration),'0s');
    results.push(`${engine} ${width}: ${width===390?'busy save/upload + failed canonical read retry verified; ':''}one document; direct URL, dynamic main/context navigation, persistent pills, keyboard, history + dirty cancel, restored values, canonical save/read, 409, GET 503, stale response, Shop and reduced motion OK`);
    await context.close();
   }
   const context=await browser.newContext({javaScriptEnabled:false});await context.route('**/*',r=>{
    const u=new URL(r.request().url()),s=u.searchParams.get('v3_section')||'v3_links';
    return u.hostname==='studio.test'&&u.pathname==='/studio/'?r.fulfill({contentType:'text/html',body:views[s].replaceAll('https://faluss.test','https://studio.test')}):r.abort();
   });const page=await context.newPage();await page.goto('https://studio.test/studio/');await page.locator('.faluss-studio-v3__bottom a').filter({hasText:'Design'}).click();assert.equal(await page.locator('[data-faluss-studio-v3]').getAttribute('data-step'),'v3_colors');await context.close();results.push(`${engine}: native link fallback without JavaScript OK`);
  }finally{await browser.close();}
 }
 fs.writeFileSync(path.join(out,'studio-results.txt'),results.join('\n')+'\n');console.log(results.join('\n'));
})().catch(e=>{console.error(e);process.exitCode=1});
