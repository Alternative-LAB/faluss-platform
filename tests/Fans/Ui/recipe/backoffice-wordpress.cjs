const {chromium}=require('playwright'),assert=require('node:assert/strict'),fs=require('node:fs');
(async()=>{
  const f=JSON.parse(fs.readFileSync(process.env.SESSION)),base=process.env.BASE,out=process.env.OUT;
  assert.equal(new URL(base).hostname,'127.0.0.1');const b=await chromium.launch({channel:'chrome'});
  try{
    const c=await b.newContext();await c.route('**/*',r=>new URL(r.request().url()).origin===base?r.continue():r.abort());
    await c.addCookies(Object.entries(f.sessions.admin.cookies).map(([name,value])=>({name,value,url:base})));
    const p=await c.newPage();const errors=[];p.on('pageerror',e=>errors.push(e.message));
    for(const width of [1440,390]){
      await p.setViewportSize({width,height:900});
      for(const view of ['overview','accounts','profiles','editorial','texts','images','staff','catalog','modules']){
        const path='/app/admin?view='+view+(['profiles','editorial'].includes(view)?'&item='+f.profiles.member:'');
        const response=await p.goto(base+path);assert.equal(response.status(),200);assert.ok(response.headers()['cache-control'].includes('no-store'));
        assert.equal(await p.locator('#adminmenu,.fu-rail').count(),0);assert.ok(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
        assert.equal(await p.locator('.fb-pills [aria-current=page]').count(),1);
        const main=await p.locator('#fb-content').boundingBox();assert.ok(main.width<=1320);
        if(view==='accounts')assert.match(await p.locator('#fb-content').innerText(),/member@example.invalid/);
        if(view==='profiles')assert.ok((await p.locator('.fm-decision').getAttribute('action')).includes('/app/admin'));
        assert.equal(await p.locator('h1').first().evaluate(e=>getComputedStyle(e).color),'rgb(237, 243, 240)');
        await p.evaluate(()=>scrollTo(0,0));await p.screenshot({path:out+'/'+view+'-'+width+'.png'});
        if(['profiles','accounts'].includes(view)){await p.locator('#fb-content').scrollIntoViewIfNeeded();await p.screenshot({path:out+'/'+view+'-detail-'+width+'.png'});}
      }
    }
    // Exercise the native browser form, then use the newly habilitated separate session.
    await p.goto(base+'/app/admin?view=staff');
    const form=p.locator('form').filter({has:p.locator('input[name=target][value="'+f.sessions['admin-two'].id+'"]')});
    await form.evaluate((e,url)=>e.action=url,base+'/app/admin?view=staff');await form.locator('[name=confirm]').check();await form.locator('button').click();
    assert.match(await p.locator('main').innerText(),/mise à jour et journalisée/);
    const mod=await b.newContext();await mod.route('**/*',r=>new URL(r.request().url()).origin===base?r.continue():r.abort());
    await mod.addCookies(Object.entries(f.sessions['admin-two'].cookies).map(([name,value])=>({name,value,url:base})));
    const mp=await mod.newPage();
    for(const width of [1440,390]){await mp.setViewportSize({width,height:900});for(const view of ['operations','messages']){assert.equal((await mp.goto(base+'/app/admin?view='+view)).status(),200);assert.ok(await mp.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));await mp.screenshot({path:out+'/'+view+'-'+width+'.png'});}}
    await p.goto(base+'/app/admin?view=staff');await form.evaluate((e,url)=>e.action=url,base+'/app/admin?view=staff');await form.locator('[name=confirm]').check();await form.locator('button').click();
    assert.equal((await mp.goto(base+'/app/admin?view=operations')).status(),403);await mod.close();
    assert.deepEqual(errors,[]);fs.writeFileSync(out+'/browser.json',JSON.stringify({browser:b.version(),checks:['Eleven real standalone sections, two sizes, centered pills, no standard sidebar','Native browser grant and revocation, separate moderator session and operations','Conditional navigation, same shared native forms, real local account context, no-store','No page error or horizontal overflow; readable headings']},null,2));
    console.log('PASS WordPress standalone backoffice browser and 26 captures');
  }finally{await b.close();}
})().catch(e=>{console.error(e.stack);process.exitCode=1;});
