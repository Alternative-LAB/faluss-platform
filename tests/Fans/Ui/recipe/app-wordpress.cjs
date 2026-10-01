const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
(async()=>{
  const fixture=JSON.parse(fs.readFileSync(process.env.SESSION)),base=process.env.BASE,out=process.env.OUT;
  assert.equal(new URL(base).hostname,'127.0.0.1');
  const browser=await chromium.launch({channel:'chrome',headless:true}),contexts={},checks=[];
  try {
    for(const role of ['guest','fan','member','admin']){
      const ctx=contexts[role]=await browser.newContext();
      await ctx.route('**/*',r=>new URL(r.request().url()).origin===base?r.continue():r.abort());
      // Fixture transport mapping only: real REST handlers, no response doubles.
      await ctx.addInitScript(origin=>{
        const native=window.fetch;
        window.fetch=(input,options)=>{const url=new URL(input instanceof Request?input.url:input,location.href);if(url.origin==='https://fans.example.test')input=origin+url.pathname+url.search;return native(input,options);};
      },base);
      if(role!=='guest')await ctx.addCookies(Object.entries(fixture.sessions[role].cookies).map(([name,value])=>({name,value,url:base})));
    }
    const get=(role,path)=>contexts[role].request.get(base+path,{maxRedirects:0});
    for(const [role,path] of [['guest','/app/fan/explorer'],['fan','/app/fan/accueil'],['member','/app/creator/accueil']]){
      const r=await get(role,'/app');assert.equal(r.status(),302);assert.equal(new URL(r.headers().location).pathname,path);
    }
    const old=await get('member','/faluss-fans/creator/mon-profil');assert.equal(old.status(),302);assert.equal(new URL(old.headers().location).pathname,'/app/creator/mon-profil');
    assert.equal((await get('fan','/app/creator/creer')).status(),404);
    assert.equal((await get('guest','/app/creator/creer')).status(),403);
    assert.equal((await get('guest','/app/fan/hof')).status(),200);
    const callback=await get('guest','/faluss-fans/sso/callback');assert.ok(!callback.headers().location?.includes('/app/sso'));
    const landing=await get('guest','/?page_id='+fixture.landing);assert.match(await landing.text(),/name="faluss_fans_return_to" value="\/app"/);
    assert.match(await (await get('fan','/?page_id='+fixture.landing)).text(),/Accéder à mon espace Fans/);
    checks.push('Real app role resolution, legacy redirects, denied roles, public HoF, stable callback and landing shortcode app destination');
    const page=await contexts.member.newPage();
    for(const width of [1440,390]){
      await page.setViewportSize({width,height:900});let box=null;
      for(const view of ['explorer','hof','mon-profil']){
        const response=await page.goto(base+'/app/creator/'+view);assert.equal(response.status(),200);
        assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
        const current=await page.locator('.fu-main').boundingBox();
        if(box)assert.deepEqual([current.x,current.width],[box.x,box.width]);box=current;
        assert.equal(await page.locator(view==='mon-profil'?'.fu-heading':'.fu-banner').count(),1);
        await page.screenshot({path:`${out}/${view}-${width}.png`});
      }
      const create=page.locator('[data-create-open]');await create.focus();await page.keyboard.press('Enter');
      assert.ok(await page.locator('#fu-create').isVisible());
      assert.equal(await page.locator('.fu-main').evaluate(x=>x.inert),true);
      const rail=await page.locator('.fu-rail').boundingBox(),shade=await page.locator('.fu-create__shade').boundingBox();
      assert.ok(width>700?shade.x>=rail.x+rail.width:shade.y+shade.height<=rail.y+1);
      await page.screenshot({path:`${out}/selector-${width}.png`});
      await page.locator('[data-create-type=publication]').click();
      await page.locator('#fu-compose-text').fill('Publication réelle de recette');
      await page.locator('[data-create-emoji]').click();await page.locator('[data-emoji="✨"]').click();
      assert.match(await page.locator('#fu-compose-text').inputValue(),/✨/);
      await page.screenshot({path:`${out}/publication-${width}.png`});
      await page.locator('[data-create-type=produit]').click();
      assert.equal(await page.locator('[data-create-panel=produit] input[type=file]').isDisabled(),true);
      assert.equal(await page.locator('[data-create-panel=produit] button').isDisabled(),true);
      assert.equal(await page.locator('[data-create-panel=produit]').isVisible(),true);
      await page.screenshot({path:`${out}/product-${width}.png`,animations:'disabled'});
      await page.keyboard.press('Escape');await page.waitForFunction(()=>document.querySelector('#fu-create').hidden);
      assert.equal(await create.evaluate(x=>x===document.activeElement),true);
      await page.waitForTimeout(100);
      await create.click();await page.goBack();assert.equal(await page.locator('#fu-create').isVisible(),false);
    }
    await page.goto(base+'/app/creator/explorer');await page.locator('[data-create-open]').click();await page.locator('[data-create-type=publication]').click();
    await page.locator('#fu-compose-text').fill('Publication envoyée par le vrai formulaire WordPress ✨');
    await page.locator('#fu-create form').evaluate((form,origin)=>{const url=new URL(form.action);form.action=origin+url.pathname+url.search;},base);
    const [submitted]=await Promise.all([page.waitForNavigation(),page.locator('#fu-create button[type=submit]').click()]);
    assert.equal(submitted.status(),200);assert.match(await page.locator('.fu-author__notice').innerText(),/En attente de modération/);
    const api=await contexts.member.request.get(base+'/wp-json/faluss-fans/v1/text-publications/mine',{headers:{'X-WP-Nonce':fixture.sessions.member.nonce}});
    const own=await api.json();assert.equal(own.items[0].state,'pending');assert.match(own.items[0].body,/vrai formulaire WordPress/);
    const pub=await contexts.guest.request.get(base+'/wp-json/faluss-fans/v1/text-publications');assert.equal((await pub.json()).items.length,0);
    checks.push('Same container x/width on Explorer HoF profile, compact header, undimmed sidebar, keyboard Escape/focus/back, emoji, disabled commerce; real publication pending only');
    fs.writeFileSync(out+'/browser.json',JSON.stringify({browser:browser.version(),checks},null,2));
    console.log('PASS WordPress app routes, forms, responsive create and 12 screenshots');
  }finally{await browser.close();}
})().catch(e=>{console.error(e.stack);process.exitCode=1;});
