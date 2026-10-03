const {chromium}=require('playwright'),assert=require('node:assert/strict'),fs=require('node:fs'),{execFileSync}=require('node:child_process');
(async()=>{
 const {BASE:base,ROOT:root,OUT:out}=process.env;assert.equal(new URL(base).hostname,'127.0.0.1');assert.match(root,/^\/var\/tmp\/fans-admission-wp-[a-z0-9_]+$/);
 const s=JSON.parse(execFileSync('wsl.exe',['-u','root','--','cat',root+'/session.json']));
 const browser=await chromium.launch({channel:'chrome',headless:true}),checks=[];
 try{
  const c=await browser.newContext({viewport:{width:390,height:844},isMobile:true,hasTouch:true});await c.route('**/*',r=>new URL(r.request().url()).origin===base?r.continue():r.abort());
  const p=await c.newPage();await p.goto(base+'/app/fan/explorer');await p.locator('.fu-discovery-row__track').first().waitFor();
  await p.evaluate(()=>{const t=document.querySelector('.fu-discovery-row__track');scrollTo(0,t.getBoundingClientRect().top+scrollY-180);});
  const cd=await c.newCDPSession(p);
  await cd.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x:320,y:300}]});
  for(const x of [280,230,180,120,60])await cd.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{x,y:300}]});
  await cd.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});
  await p.waitForFunction(()=>document.querySelector('.fu-discovery-row__track').scrollLeft>0);
  checks.push('Real Chromium touch gesture scrolls the creator row horizontally');
  for(const width of [320,600,700,701,1024]){
   await p.setViewportSize({width,height:900});
   for(const path of ['/app/fan/explorer','/app/creators/'+s.profiles.member]){
    await p.goto(base+path);await p.waitForFunction(()=>!document.querySelector('[data-fans-status]')?.textContent.includes('Chargement'));await p.evaluate(()=>document.fonts.ready);
    assert.ok(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
   }
  }
  checks.push('Explorer/profile overflow absent at 320,600,700,701,1024 pixels');
  await p.goto(base+'/app/fan/explorer');await p.locator('.fu-discovery-hero__link:visible').waitFor();
  await p.locator('.fu-discovery-hero__link:visible').evaluate((el,base)=>{const u=new URL(el.href);el.href=base+u.pathname;},base);
  await p.locator('.fu-discovery-hero__link:visible').focus();await p.keyboard.press('Enter');await p.waitForURL('**/app/creators/'+s.profiles.member);await p.locator('.fu-public-creator__copy h2').waitFor();
  await p.goBack();await p.locator('.fu-discovery-hero__slide:visible').waitFor();
  checks.push('Keyboard Explorer-to-profile navigation and browser back reload the real public projections (fixture host rewritten to loopback only)');
  for(const id of [s.profiles.waiting,'00000000-0000-4000-8000-000000000000'])assert.equal((await c.request.get(base+'/app/creators/'+id)).status(),404);
  checks.push('Pending and missing profile pages return HTTP404 themselves');
  fs.writeFileSync(out+'/interaction.json',JSON.stringify({checks},null,2));console.log('PASS '+checks.length+' interaction groups');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1});
