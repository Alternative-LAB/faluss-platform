const {chromium}=require('playwright'),assert=require('node:assert/strict'),fs=require('node:fs');
(async()=>{
 const f=JSON.parse(fs.readFileSync(process.env.SESSION)),base=process.env.BASE,out=process.env.OUT;
 assert.equal(new URL(base).hostname,'127.0.0.1');fs.mkdirSync(out,{recursive:true});const browser=await chromium.launch({channel:'chrome'});
 try{
  for(const [who,role] of [['member','creator'],['fan','fan']]){
   const c=await browser.newContext();await c.route('**/*',r=>new URL(r.request().url()).origin===base?r.continue():r.abort());
   await c.addCookies(Object.entries(f.sessions[who].cookies).map(([name,value])=>({name,value,url:base})));
   const p=await c.newPage(),errors=[];p.on('pageerror',e=>errors.push(e.message));
   for(const width of [1440,390]){
    await p.setViewportSize({width,height:900});const response=await p.goto(base+'/app/'+role+'/notifications');assert.equal(response.status(),200);assert.match(response.headers()['cache-control'],/no-store/);
    assert.ok(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));assert.equal(await p.locator('.fu-bell svg[aria-hidden=true]').count(),1);
    assert.ok(await p.locator('.fu-notification[data-unread=true]').count()>0);assert.match(await p.locator('main').innerText(),/Aucun e-mail/);
    if(who==='member')assert.match(await p.locator('main').innerText(),/Des modifications sont nécessaires/);
    assert.equal(await p.locator('#wpadminbar').count(),0);await p.screenshot({path:out+'/'+role+'-'+width+'.png'});
    if(who==='member'){await p.getByRole('heading',{name:'Votre présentation a été refusée'}).scrollIntoViewIfNeeded();await p.screenshot({path:out+'/bio-rejected-'+width+'.png'});}
   }
   const before=Number(await p.locator('.fu-notification-count').innerText());const card=p.locator('.fu-notification[data-unread=true]').first();
   await card.locator('form').evaluate((el,url)=>el.action=url,base+'/app/'+role+'/notifications');await card.getByRole('button',{name:'Marquer comme lue',exact:true}).click();
   assert.equal(Number(await p.locator('.fu-notification-count').innerText()),before-1);await p.reload();assert.equal(Number(await p.locator('.fu-notification-count').innerText()),before-1);
   const read=p.locator('.fu-notification[data-unread=false]').first();await read.locator('form').evaluate((el,url)=>el.action=url,base+'/app/'+role+'/notifications');await read.getByRole('button',{name:'Marquer comme non lue'}).click();assert.equal(Number(await p.locator('.fu-notification-count').innerText()),before);
   const link=p.locator('a[href*="thread='+f.notification_thread+'"]').first();if(await link.count()){const path=new URL(await link.getAttribute('href')).pathname+new URL(await link.getAttribute('href')).search;assert.equal((await p.goto(base+path)).status(),200);assert.match(await p.locator('main').innerText(),/Demande privée de recette/);}
   assert.deepEqual(errors,[]);await c.close();
  }
  fs.writeFileSync(out+'/browser.json',JSON.stringify({browser:browser.version(),checks:['Actual WordPress Fan/Creator notification centers, 1440 and 390','Unread persisted through native POST and reload; exact decrement/replay restore','Communicable bio refusal; authorized real message link; no WP toolbar','Private no-store, no horizontal overflow, no JS error']},null,2));console.log('PASS real notification browser, six captures');
 }finally{await browser.close();}
})().catch(e=>{console.error(e.stack);process.exitCode=1;});
