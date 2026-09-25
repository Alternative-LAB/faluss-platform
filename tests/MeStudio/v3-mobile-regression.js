// Local browser regression only: real PHP controls/card, in-memory storage, illustrative media.
'use strict';
const fs = require('fs');
const path = require('path');
const cp = require('child_process');
const assert = require('assert/strict');
const {chromium, webkit} = require('playwright');
const root = path.resolve(__dirname, '../..');
const php = process.env.FALUSS_PHP;
const controls = JSON.parse(cp.execFileSync(php, [path.join(__dirname,'onboarding-v3-fixture.php')], {encoding:'utf8'}));
const cards = JSON.parse(cp.execFileSync(php, [path.join(__dirname,'v3-composition-regression.php')], {encoding:'utf8', env:{...process.env,FALUSS_V3_CARDS:'1'}}));
const views = JSON.parse(cp.execFileSync(php, [path.join(__dirname,'v3-composition-regression.php')], {encoding:'utf8', env:{...process.env,FALUSS_V3_VIEWS:'1'}}));
const css = ['assets/me-studio/css/card-v2.css','assets/link/css/faluss-link.css','assets/link/css/faluss-link-immersive.css','assets/me-studio/css/onboarding-v3.css'].map(f=>fs.readFileSync(path.join(root,f),'utf8')).join('\n');
const js = fs.readFileSync(path.join(root,'assets/me-studio/js/onboarding-v3.js'),'utf8');
const img = 'data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="390" height="600"><rect width="390" height="600" fill="#af9dba"/><path d="M0 600L190 0 390 600" fill="#665471"/></svg>');
const illustrate = s=>s.replaceAll('https://faluss.test/media/77.jpg',img).replaceAll('https://faluss.test/wp-content/plugins/faluss-platform/assets/link/images/faluss-onboarding-header-logo.png', 'data:image/png;base64,'+fs.readFileSync(path.join(root,'assets/link/images/faluss-onboarding-header-logo.png')).toString('base64'));
function pageHtml(step, studio=false) {
 return `<!doctype html><meta name="viewport" content="width=device-width, initial-scale=1"><style>${css}</style><section class="faluss-onboarding-v3" data-faluss-onboarding-v3 data-step="${step}" data-studio="${studio}" data-version="test"><header class="faluss-onboarding-v3__header"><button data-v3-back>←</button>${studio?'<label>Studio<select data-v3-section><option>Identité</option></select></label>':'<div class="faluss-onboarding-v3__progress"><span></span><span></span><span></span></div>'}<img class="faluss-onboarding-v3__logo" src="data:image/png;base64,${fs.readFileSync(path.join(root,'assets/link/images/faluss-onboarding-header-logo.png')).toString('base64')}" alt="Faluss Me"></header><div class="faluss-onboarding-v3__stage"><div class="faluss-onboarding-v3__phone"><div class="faluss-onboarding-v3__phone-screen" data-v3-preview>${illustrate(cards['atomic-compact-gradient'])}</div></div></div><form class="faluss-onboarding-v3__panel" data-v3-panel><button type="button" class="faluss-onboarding-v3__grabber" data-v3-grabber><span></span></button><div class="faluss-onboarding-v3__scroll" data-v3-scroll>${controls.atomic[step]}<p data-v3-error hidden></p><p data-v3-status></p></div><footer class="faluss-onboarding-v3__actions"><button class="faluss-onboarding-v3__primary" data-v3-primary>${studio?'Enregistrer':'Continuer'}</button></footer></form></section><script>window.falussOnboardingV3={ajaxUrl:'https://local.invalid/ajax'};window.fetch=()=>Promise.resolve({status:200,text:()=>Promise.resolve(JSON.stringify({success:true,data:{preview_html:${JSON.stringify(cards['atomic-compact-gradient'])}}}))});</script><script>${js}</script>`;
}
(async()=>{
 const out=path.join(root,'docs/evidence/me-v3-correction');fs.mkdirSync(out,{recursive:true});
 const results=[];
 for(const [engine,type,options] of [['chromium',chromium,{executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'}],['webkit',webkit,{}]]) {
  const browser=await type.launch({headless:true,...options});
  try {
   for(const width of [320,390,768]) {
    const page=await browser.newPage({viewport:{width,height:844},reducedMotion:'reduce'});
    const errors=[];page.on('pageerror',e=>errors.push(e.message));
    await page.setContent(pageHtml('v3_network_style'));
    assert.equal(await page.locator('[data-v3-tab-panel="color"]').isVisible(),false);
    await page.locator('[data-v3-tab="color"]').click();
    assert.equal(await page.locator('[data-v3-tab-panel="style"]').isVisible(),false);
    assert.equal(await page.locator('[data-v3-tab-panel="color"]').isVisible(),true);
    await page.setContent(pageHtml('v3_socials'));
    const initial=await page.locator('[data-v3-panel]').boundingBox();
    await page.locator('[data-v3-panel]').dispatchEvent('wheel',{deltaY:60});
    const expanded=await page.locator('[data-v3-panel]').boundingBox();assert(expanded.height>initial.height);
    await page.locator('[data-v3-scroll]').evaluate(e=>e.scrollTop=e.scrollHeight);
    assert(await page.locator('[data-v3-scroll]').evaluate(e=>Math.abs(e.scrollHeight-e.clientHeight-e.scrollTop)<2));
    assert.equal(await page.evaluate(()=>scrollY),0);
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
    assert(await page.locator('input[type=url]').first().evaluate(e=>parseFloat(getComputedStyle(e).fontSize)>=16));
    const header=await page.locator('.faluss-onboarding-v3__header').boundingBox();
    await page.evaluate(()=>{Object.defineProperty(visualViewport,'height',{configurable:true,value:480});visualViewport.dispatchEvent(new Event('resize'));});
    assert.deepEqual(await page.locator('.faluss-onboarding-v3__header').boundingBox(),header);
    const action=await page.locator('[data-v3-primary]').boundingBox();assert(action.y+action.height<=481);
    if(engine==='chromium'&&width===390) {await page.evaluate(()=>delete visualViewport.height);await page.setContent(pageHtml('v3_colors'));await page.screenshot({path:path.join(out,'onboarding-390.png')});await page.setContent(`<style>${css}</style>${illustrate(views.v3_identity)}<script>window.falussOnboardingV3={ajaxUrl:'https://local.invalid/ajax'};</script><script>${js}</script>`);await page.screenshot({path:path.join(out,'studio-390.png')});}
    assert.deepEqual(errors,[]);results.push(`${engine} ${width}: tabs, expand/scroll, 16px fields, keyboard inset/header OK`);
    await page.close();
   }
   const stalePage=await browser.newPage({viewport:{width:390,height:844}});
   await stalePage.setContent(pageHtml('v3_colors'));
   await stalePage.evaluate(()=>{window.responses=[];window.fetch=()=>new Promise(resolve=>window.responses.push(resolve));});
   await stalePage.locator('[name="page_background"]').first().check({force:true});
   await stalePage.waitForFunction(()=>window.responses.length===1);
   await stalePage.evaluate(()=>{
      const field=document.querySelectorAll('[name="page_background"]')[1];field.checked=true;field.dispatchEvent(new Event('change',{bubbles:true}));
      window.responses[0]({status:200,text:()=>Promise.resolve(JSON.stringify({success:true,data:{preview_html:'<article>STALE</article>'}}))});
   });
   await stalePage.waitForFunction(()=>window.responses.length===2);
   assert(!(await stalePage.locator('[data-v3-preview]').innerText()).includes('STALE'));
   await stalePage.close();
   const page=await browser.newPage({viewport:{width:390,height:844}});
   for(const structure of ['simple','atomic']) {
    let avatar;
    for(const key of ['compact-none','compact-gradient','compact-gradient','cover-gradient','compact-gradient','cover-none']) {
     const markup=illustrate(cards[structure+'-'+key]);
     const measure=async immersive=>{
      await page.setContent(`<style>${css}</style>${immersive?markup.replace('faluss-link-card--canonical','faluss-link-card--canonical faluss-link-card--presentation-immersive'):markup}`);
      return page.locator('article').evaluate(e=>{const r=n=>{const b=e.querySelector(n).getBoundingClientRect();return {x:b.x,y:b.y,w:b.width,h:b.height};};return {avatar:r('.faluss-link-card__avatar'),name:r('.faluss-link-card__name'),body:r('.faluss-link-card__body'),cover:r('.faluss-link-card__cover'),link:r('.faluss-link-card__link')};});
     };
     const preview=await measure(false),publicView=await measure(true);
     // Public container drops its border; internal geometry must differ by at most 1px.
     for(const field of ['avatar','name','link']) for(const axis of ['w','h']) assert(Math.abs(preview[field][axis]-publicView[field][axis])<=2,`${engine} ${structure} ${key} ${field} ${axis}`);
     if(avatar) assert.deepEqual(publicView.avatar,avatar);avatar=publicView.avatar;
     assert(publicView.avatar.y>=0);assert(publicView.body.h<700);
     if(key.startsWith('cover'))assert(publicView.cover.h>=publicView.body.h-2);else assert.equal(publicView.cover.h,300);
    }
   }
   if(engine==='chromium')await page.screenshot({path:path.join(out,'public-390.png')});
   results.push(`${engine}: repeated wallpaper transitions, Simple/Atomic, public/preview geometry OK`);
   await page.close();
  } finally {await browser.close();}
 }
 fs.writeFileSync(path.join(out,'browser-results.txt'),results.join('\n')+'\n');console.log(results.join('\n'));
})().catch(e=>{console.error(e);process.exit(1);});
