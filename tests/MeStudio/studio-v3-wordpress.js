// Real disposable WordPress fixture; captured local email, never a production mailbox.
'use strict';
const {chromium}=require('playwright'),fs=require('fs'),assert=require('assert/strict');
async function saveAndRead(page) {
 await page.locator('[data-v3-panel]').evaluate(e=>e.dataset.recipeRead='old');
 await page.locator('[data-v3-primary]').click();
 await page.waitForFunction(()=>!document.querySelector('[data-v3-panel]').hasAttribute('data-recipe-read'));
}
const fixture=JSON.parse(fs.readFileSync(process.env.FALUSS_LOCAL_FIXTURE,'utf8'));const base=new URL(fixture.studio).origin;if(new URL(base).hostname!=='127.0.0.1'){throw new Error('Local fixture only');}
(async()=>{const browser=await chromium.launch({executablePath:process.env.FALUSS_CHROME,headless:true});try{
const context=await browser.newContext({viewport:{width:390,height:844}});await context.route('**/*',route=>new URL(route.request().url()).hostname==='127.0.0.1'?route.continue():route.abort());const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push({route:new URL(page.url()).pathname,message:e.message}));
await page.goto(base+'/login/?redirect_to='+encodeURIComponent(fixture.studio));
await page.locator('input[name=email]').fill(fixture.email);await page.locator('form[data-faluss-login-stage=email] button[type=submit]').click();await page.locator('input[name=otp]').waitFor();
const mail=JSON.parse(fs.readFileSync(process.env.FALUSS_LOCAL_MAIL,'utf8'));const otp=mail.message.match(/\b[0-9]{6}\b/)[0];await page.locator('input[name=otp]').fill(otp);await page.locator('input[name=otp]').press('Enter');
await page.waitForURL(url=>!url.pathname.includes('login'));await page.goto(fixture.studio);await page.locator('[data-faluss-studio-v3]').waitFor();

console.log('Local HTTP login, captured OTP, session and Studio: OK');
fs.mkdirSync('docs/evidence/me-v3-052',{recursive:true});
for(const width of [320,390,768]){await page.setViewportSize({width,height:844});await page.screenshot({path:`docs/evidence/me-v3-052/studio-${width}.png`,fullPage:true});const metrics=await page.evaluate(()=>({width:innerWidth,scroll:document.documentElement.scrollWidth,bottom:document.querySelector('.faluss-studio-v3__bottom').getBoundingClientRect().bottom,position:getComputedStyle(document.querySelector('[data-v3-panel]')).position}));assert(metrics.scroll<=width);assert(Math.abs(metrics.bottom-844)<2);assert.equal(metrics.position,'static');}
await page.setViewportSize({width:390,height:844});await page.goto(fixture.studio+'?v3_section=v3_identity');
let dialogs=[];page.on('dialog',async d=>{dialogs.push(d.type());await d.dismiss();});
const initialName=await page.locator('[name=display_name]').inputValue(); await page.locator('[name=display_name]').fill('Camille modifiée');await page.locator('[name=display_name]').fill(initialName);await page.locator('.faluss-studio-v3__bottom a').filter({hasText:'Design'}).click();await page.waitForURL(/v3_colors/);assert.deepEqual(dialogs,[]);
await page.goto(fixture.studio+'?v3_section=v3_identity');await page.locator('[name=display_name]').fill('Camille enregistrée');
await saveAndRead(page);assert.equal(await page.locator('[name=display_name]').inputValue(),'Camille enregistrée');await page.locator('.faluss-studio-v3__bottom a').filter({hasText:'Design'}).click();await page.waitForURL(/v3_colors/);await page.reload();assert.deepEqual(dialogs,[]);

await page.locator('[data-studio-preview-open]').click();await page.locator('dialog').waitFor({state:'visible'});assert.equal(await page.locator('.faluss-onboarding-v3__phone').count(),0);await page.screenshot({path:'docs/evidence/me-v3-052/full-preview-390.png'});await page.locator('[data-studio-preview-close]').click();
async function centeredCard() {
 const card=page.locator('.faluss-link-card--canonical').first();
 const geometry=await card.evaluate(e=>{
  const name=e.querySelector('.faluss-link-card__name'),handle=e.querySelector('.faluss-link-card__handle'),social=e.querySelector('.faluss-link-card__social'),avatar=e.querySelector('.faluss-link-card__avatar');
  const body=e.querySelector('.faluss-link-card__body'),b=body.getBoundingClientRect(),a=avatar?.getBoundingClientRect();
  return {name:getComputedStyle(name).textAlign,handle:getComputedStyle(handle).textAlign,social:social?getComputedStyle(social).justifyContent:null,avatarOffset:a?.width?Math.abs(a.x+a.width/2-b.x-b.width/2):null,padding:parseFloat(getComputedStyle(body).paddingTop)};
 });
 assert.equal(geometry.name,'center');assert.equal(geometry.handle,'center');if(geometry.social)assert.equal(geometry.social,'center');assert(geometry.avatarOffset===null||geometry.avatarOffset<1);assert(geometry.padding>=64);
 return geometry;
}
const composition=[];
await page.locator('[data-studio-preview-open]').click();composition.push({host:'preview',...await centeredCard()});await page.locator('[data-studio-preview-close]').click();
for(const [host,url] of [['public',base+'/'+fixture.slug+'/'],['shortcode',fixture.shortcode],['elementor',fixture.elementor]]){await page.goto(url);assert.match(await page.locator('.faluss-link-card').innerText(),/Camille enregistrée/);composition.push({host,...await centeredCard()});}
assert(composition.every(c=>c.padding===composition[0].padding),'Same canonical spacing in every host');
fs.writeFileSync('docs/evidence/me-v3-052/composition-local.json',JSON.stringify(composition,null,2));
await page.goto(fixture.studio+'?v3_section=v3_identity');
const concurrent=await context.newPage();await concurrent.goto(fixture.studio+'?v3_section=v3_identity');await concurrent.locator('[name=display_name]').fill('Camille autre session');await saveAndRead(concurrent);
await page.locator('[name=display_name]').fill('Valeur conservée après conflit');const responsePromise=page.waitForResponse(r=>r.url().includes('admin-ajax.php')&&r.request().postData()?.includes('faluss_studio_v3_save'));await page.locator('[data-v3-primary]').click();const conflict=await responsePromise;assert.equal(conflict.status(),409);const conflictBody=await conflict.json();assert.equal(conflictBody.data.code,'stale_version');await page.locator('[data-v3-error]').waitFor({state:'visible'});assert.equal(await page.locator('[name=display_name]').inputValue(),'Valeur conservée après conflit');
await page.locator('.faluss-studio-v3__bottom a').filter({hasText:'Design'}).click();assert.equal(dialogs.length,1);assert.match(page.url(),/v3_identity/);
fs.writeFileSync('docs/evidence/me-v3-052/conflict.json',JSON.stringify({at:new Date().toISOString(),http:conflict.status(),body:conflictBody},null,2));
fs.writeFileSync('docs/evidence/me-v3-052/browser-notices.json',JSON.stringify(errors,null,2)); assert.deepEqual(errors.filter(e=>e.route===new URL(fixture.studio).pathname),[]);console.log('320/390/768 native layout; reverted fields; confirmed save -> tab -> reload; public/shortcode/real Elementor persisted name: OK');
}catch(e){console.error(e);process.exitCode=1;}finally{await browser.close();}})();
