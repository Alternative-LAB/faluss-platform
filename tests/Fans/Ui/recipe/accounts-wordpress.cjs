const {chromium}=require('playwright'),assert=require('node:assert/strict'),fs=require('node:fs');
(async()=>{
  const f=JSON.parse(fs.readFileSync(process.env.SESSION)),base=process.env.BASE,out=process.env.OUT;
  assert.equal(new URL(base).hostname,'127.0.0.1');const b=await chromium.launch({channel:'chrome'});
  try{
    const c=await b.newContext();await c.route('**/*',r=>new URL(r.request().url()).origin===base?r.continue():r.abort());
    await c.addCookies(Object.entries(f.sessions.admin.cookies).map(([name,value])=>({name,value,url:base})));
    const p=await c.newPage();
    for(const width of [1440,390]){
      await p.setViewportSize({width,height:900});
      for(const view of ['profiles','editorial']){
        const response=await p.goto(base+'/wp-admin/admin.php?page=faluss-fans-moderation&view='+view+'&item='+f.profiles.member);
        assert.equal(response.status(),200);assert.ok(response.headers()['cache-control'].includes('no-store'));
        const card=p.locator('.fm-account').first();await card.scrollIntoViewIfNeeded();assert.match(await card.innerText(),/member@example.invalid/);
        assert.match(await card.innerText(),/Faluss ID/);assert.match(await card.innerText(),/Non fourni par le contrat actuel/);
        assert.equal(await card.locator('img').count(),0);
        assert.ok(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
        await p.screenshot({path:out+'/'+view+'-'+width+'.png'});
      }
    }
    fs.writeFileSync(out+'/browser.json',JSON.stringify({browser:b.version(),checks:['Real native WordPress admission and editorial account context','No-store, local email, Faluss ID, absent handle and absent portrait honest','Desktop and mobile no horizontal overflow']},null,2));
    console.log('PASS WordPress private account cards and four screenshots');
  }finally{await b.close();}
})().catch(e=>{console.error(e.stack);process.exitCode=1;});
