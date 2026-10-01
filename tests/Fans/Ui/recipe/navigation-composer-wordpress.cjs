// Real disposable WordPress transport only; never run against a deployed site.
const {chromium}=require('playwright'),assert=require('node:assert/strict'),fs=require('node:fs');
(async()=>{
 const base=process.env.BASE,out=process.env.OUT,s=JSON.parse(fs.readFileSync(process.env.SESSION));assert.equal(new URL(base).hostname,'127.0.0.1');
 const browser=await chromium.launch({channel:'chrome',headless:true}),checks=[],errors=[],contexts={};
 try{
  for(const role of ['member','fan','guest','admin']){const c=contexts[role]=await browser.newContext();await c.route('**/*',r=>new URL(r.request().url()).origin===base?r.continue():r.abort());if(role!=='guest')await c.addCookies(Object.entries(s.sessions[role].cookies).map(([name,value])=>({name,value,url:base})));}
  const api=(role,path,data)=>contexts[role].request[data?'post':'get'](base+'/wp-json/faluss-fans/v1/'+path,{headers:role==='guest'?{}:{'X-WP-Nonce':s.sessions[role].nonce},...(data?{data}:{})});
  const p=await contexts.member.newPage();p.on('pageerror',e=>errors.push(e.message));
  const localLinks=async()=>p.locator('a[href]').evaluateAll((xs,base)=>xs.forEach(x=>{const u=new URL(x.href);if(u.origin==='https://fans.example.test')x.href=base+u.pathname+u.search+u.hash;}),base);
  const navRects=()=>p.locator('.fu-nav__item').evaluateAll(xs=>xs.map(x=>({height:x.getBoundingClientRect().height,y:x.getBoundingClientRect().y,label:x.ariaLabel})));
  const noOverflow=async()=>assert.ok(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'no horizontal overflow');
  for(const role of ['creator','fan'])for(const width of [320,390,700,701,1024,1440]){
   const pageRole=role==='creator'?'member':'fan';await p.context().clearCookies();await p.context().addCookies(Object.entries(s.sessions[pageRole].cookies).map(([name,value])=>({name,value,url:base})));
   await p.setViewportSize({width,height:900});await p.goto(base+'/app/'+role+'/'+(role==='creator'?'mon-profil':'accueil'));await p.evaluate(()=>document.fonts.ready);await noOverflow();
   const initial=await navRects(),main=await p.locator('.fu-main').boundingBox();assert.ok(initial.every(x=>x.height===58));
   if(width>700)for(let i=1;i<initial.length;i++)assert.equal(initial[i].y-initial[i-1].y,66);
   await p.locator('.fu-main').focus();await p.mouse.move(width-2,2);
   assert.equal(await p.locator('.is-active .fu-nav__label').evaluate(x=>getComputedStyle(x).visibility),'hidden');
   if([390,1440].includes(width))await p.screenshot({animations:'disabled',path:`${out}/${role}-normal-${width}.png`});
   const links=p.locator('.fu-nav__item');for(let i=0;i<await links.count();i++){await links.nth(i).focus();assert.equal(await links.nth(i).locator('.fu-nav__label').evaluate(x=>getComputedStyle(x).visibility),'visible');assert.deepEqual(await navRects(),initial);}
   if([390,1440].includes(width))await p.screenshot({animations:'disabled',path:`${out}/${role}-focus-${width}.png`});
   for(let i=0;i<await links.count();i++){await links.nth(i).hover();assert.equal(await links.nth(i).locator('.fu-nav__label').evaluate(x=>getComputedStyle(x).visibility),'visible');assert.deepEqual(await navRects(),initial);}
   if([390,1440].includes(width))await p.screenshot({animations:'disabled',path:`${out}/${role}-hover-${width}.png`});
   assert.equal((await p.locator('.fu-main').boundingBox()).width,main.width);assert.equal(await p.locator('.fu-brand img').getAttribute('src').then(x=>x.endsWith('/FANS-SV-XS.svg')),true);
   checks.push(`${role} ${width}: all fixed slots, active label hidden, hover/focus without layout shift`);
  }
  await p.context().clearCookies();await p.context().addCookies(Object.entries(s.sessions.member.cookies).map(([name,value])=>({name,value,url:base})));
  for(const width of [1440,390]){
   await p.setViewportSize({width,height:900});await p.goto(base+'/app/creator/mon-profil');await localLinks();
   const text=await p.locator('body').innerText();for(const phrase of ['Cette version reste publique pendant','Votre compte Faluss Identity reste inchangé','La présentation ci-dessous est soumise'])assert.ok(!text.includes(phrase));
   await p.evaluate(()=>window.scrollTo(0,document.body.scrollHeight));const scroll=await p.evaluate(()=>scrollY);
   const create=p.locator('.fu-nav [data-create-open]');await create.click();
   const rail=await p.locator('.fu-rail').boundingBox();assert.ok(Math.abs(width>700?rail.y:rail.y+rail.height-900)<1);
   assert.ok(Math.abs(await p.locator('#fu-create').evaluate(x=>x.getBoundingClientRect().y))<1);
   const mainBox=await p.locator('.fu-main').boundingBox();await p.mouse.wheel(0,600);assert.equal(await p.evaluate(()=>scrollY),scroll);
   for(let i=0;i<16;i++){await p.keyboard.press(i%2?'Shift+Tab':'Tab');assert.equal(await p.evaluate(()=>Boolean(document.activeElement.closest('#fu-create,.fu-rail'))),true);}
   await p.locator('[data-create-type=publication]').click();const draft='Brouillon préservé '+width+' ✨';await p.locator('#fu-compose-text').fill(draft);
   await p.screenshot({animations:'disabled',path:`${out}/publication-${width}.png`});await p.locator('[data-create-images]').click();await p.waitForFunction(()=>!document.querySelector('[data-create-image-status]').textContent.includes('Chargement'));
   await p.screenshot({animations:'disabled',path:`${out}/images-${width}.png`});assert.ok(p.url().endsWith('/mon-profil'));
   await p.locator('[data-create-image-return]').click();assert.equal(await p.locator('#fu-compose-text').inputValue(),draft);await p.screenshot({animations:'disabled',path:`${out}/return-${width}.png`});
   for(let i=0;i<3;i++){await p.locator('[data-create-images]').click();await p.keyboard.press('Escape');assert.equal(await p.locator('#fu-compose-text').inputValue(),draft);await p.keyboard.press('Escape');await p.waitForTimeout(80);assert.equal(await p.evaluate(()=>scrollY),scroll);assert.equal(await create.evaluate(x=>x===document.activeElement),true);await create.click();await p.locator('[data-create-type=publication]').click();assert.equal(await p.locator('#fu-compose-text').inputValue(),draft);}
   assert.equal((await p.locator('.fu-main').boundingBox()).width,mainBox.width);await p.goBack();assert.equal(await p.locator('#fu-create').isVisible(),false);await p.goForward();assert.equal(await p.locator('#fu-create').isVisible(),true);
   await p.locator('[data-create-close]').last().click();await p.waitForTimeout(100);await create.click();await p.locator('.fu-nav__item[aria-label="Explorer"]').click();await p.waitForURL('**/explorer');assert.equal(await p.locator('#fu-create').isVisible(),false);assert.equal(await p.evaluate(()=>document.documentElement.style.overflow),'');await noOverflow();
   checks.push(`${width}: scrolled rail/overlay, wheel lock, image return draft, Escape, close/reopen x3, browser back/forward, sidebar navigation`);
  }
  // Touch names an icon on first tap and navigates on the second; AT click remains native.
  const touch=await browser.newContext({viewport:{width:390,height:844},isMobile:true,hasTouch:true});await touch.addCookies(Object.entries(s.sessions.fan.cookies).map(([name,value])=>({name,value,url:base})));await touch.route('**/*',r=>new URL(r.request().url()).origin===base?r.continue():r.abort());const tp=await touch.newPage();await tp.goto(base+'/app/fan/accueil');const explorer=tp.locator('.fu-nav__item[aria-label=Explorer]');await explorer.evaluate((x,base)=>x.href=base+'/app/fan/explorer',base);await explorer.tap();assert.ok(tp.url().endsWith('/accueil'));assert.equal(await explorer.getAttribute('data-touch-label'),'');await tp.screenshot({animations:'disabled',path:out+'/touch-label-390.png'});await explorer.tap();await tp.waitForURL('**/explorer');await touch.close();checks.push('Touch label then navigation; no active persistent caption');
  for(const [role,status] of [['guest',403],['fan',404]])assert.equal((await contexts[role].request.get(base+'/app/creator/images')).status(),status);
  const legacy=await contexts.member.request.get(base+'/faluss-fans/creator/creer',{maxRedirects:0});assert.equal(legacy.status(),302);assert.ok(legacy.headers().location.endsWith('/app/creator/creer'));
  const oldImage=await contexts.member.request.get(base+'/app/creator/creer?image_state=pending',{maxRedirects:0});assert.equal(oldImage.status(),302);assert.ok(oldImage.headers().location.endsWith('/app/creator/images?image_state=pending'));
  const oldPost=await contexts.member.request.post(base+'/app/creator/creer',{form:{image_action:'withdraw',fans_image_nonce:'forged'},maxRedirects:0});assert.equal(oldPost.status(),307);assert.ok(oldPost.headers().location.endsWith('/app/creator/images'));
  await p.goto(base+'/app/creator/creer#fu-images');await p.waitForURL('**/images#fu-images');assert.equal(await p.locator('#fu-images').count(),1);
  const upload=async()=>{await p.goto(base+'/app/creator/explorer');await p.locator('[data-create-open]').click();await p.locator('[data-create-type=publication]').click();await p.locator('#fu-compose-text').fill('Publication avec image privée '+Date.now());await p.locator('[data-create-images]').click();await p.locator('#fu-compose-file').setInputFiles(process.env.UPLOAD);await p.locator('[data-create-upload] button').click();await p.waitForFunction(()=>document.querySelector('[data-create-image-list]').children.length>0);};
  await upload();let items=await (await api('member','images?scope=live')).json();const image=items.items[0];assert.equal(image.state,'pending');assert.equal(await p.getByRole('button',{name:'Sélectionner cette image'}).count(),0);
  assert.ok((await api('guest',`images/${image.image_id}/preview/${image.revision}`)).status()>=400);
  assert.ok((await api('fan',`images/${image.image_id}/preview/${image.revision}`)).status()>=400);
  assert.equal((await api('admin',`images/${image.image_id}/moderate`,{revision:Number(image.revision),decision:'approve',reason:'allowed_image'})).status(),200);
  await p.locator('[data-create-image-refresh]').click();await p.getByRole('button',{name:'Sélectionner cette image'}).waitFor();await p.getByRole('button',{name:'Examiner en privé'}).click();await p.locator('.fu-create__image img').waitFor();await p.screenshot({animations:'disabled',path:out+'/private-selection-390.png'});
  await p.getByRole('button',{name:'Sélectionner cette image'}).click();assert.ok((await p.locator('#fu-compose-text').inputValue()).startsWith('Publication avec image privée'));await p.locator('#fu-create .fu-author__form button[type=submit]').click();await p.waitForFunction(()=>document.querySelector('[data-create-result]').textContent.includes('Publication et image soumises'));
  let own=await (await api('member','text-publications/mine')).json();const publication=own.items.find(x=>x.body.startsWith('Publication avec image privée'));assert.ok(publication);const stored=await (await api('member',`text-publications/${publication.publication_id}/private`)).json();assert.equal(stored.image_id,image.image_id);assert.equal(stored.state,'pending');assert.equal((await api('guest',`text-publications/${publication.publication_id}`)).status(),404);
  // Select a valid image, then withdraw it from another real session before submission.
  await p.goto(base+'/app/creator/explorer');await p.locator('[data-create-open]').click();await p.locator('[data-create-type=publication]').click();await p.locator('#fu-compose-text').fill('Association devenue périmée '+Date.now());await p.locator('[data-create-images]').click();await p.getByRole('button',{name:'Sélectionner cette image'}).click();
  items=await (await api('member','images?scope=live')).json();assert.equal((await api('member',`images/${image.image_id}/withdraw`,{revision:Number(items.items[0].revision)})).status(),200);
  await p.locator('#fu-create .fu-author__form button[type=submit]').click();await p.waitForFunction(()=>document.querySelector('[data-create-result]').textContent.includes('Texte enregistré. Association'));
  assert.equal(await p.locator('#fu-create .fu-author__form button[type=submit]').isDisabled(),true);assert.ok(await p.locator('[data-create-result] a').isVisible());
  checks.push('Real private upload/pending/approval/preview/selection/association, guest and nonowner denied, publication pending only, concurrent withdrawal yields honest partial outcome and no resend');
  assert.deepEqual(errors,[]);fs.writeFileSync(out+'/browser.json',JSON.stringify({browser:browser.version(),checks},null,2));console.log('PASS '+checks.length+' grouped real WordPress/browser scenarios');
 }finally{await browser.close();}
})().catch(e=>{console.error(e.stack);process.exitCode=1;});
