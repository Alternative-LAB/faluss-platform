'use strict';
const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');

// Reads only the private disposable fixture; no accounts, nonces or message bodies in the report.
(async () => {
  const { BASE:base, ROOT:root, OUT:out } = process.env;
  assert.match(base,/^http:\/\/127\.0\.0\.1:[0-9]+$/);
  assert.match(root,/^\/var\/tmp\/fans-admission-wp-[a-z0-9_]+$/);
  const fixture=JSON.parse(execFileSync('wsl.exe',['-u','root','--','cat',root+'/automatic-capture.json']));
  assert.equal(fixture.base,base);
  fs.mkdirSync(out,{recursive:true});
  const checks=[],errors=[],nativePosts=[];
  const browser=await chromium.launch({channel:'chrome',headless:true});
  const local=async page=>page.locator('a[href],form[action]').evaluateAll((elements,base)=>elements.forEach(el=>{
    const attr=el.tagName==='FORM'?'action':'href',u=new URL(el.getAttribute(attr),location.href);
    if(u.origin==='https://fans.example.test')el.setAttribute(attr,base+u.pathname+u.search+u.hash);
  }),base);
  const contextFor=async(width,who,js=true)=>{
    const context=await browser.newContext({viewport:{width,height:width<700?844:1000},hasTouch:width<700,javaScriptEnabled:js});
    await context.route('**/*',async route=>{
      if(new URL(route.request().url()).origin!==base){return route.abort();}
      if(route.request().resourceType()!=='document'){return route.continue();}
      let response;
      try {response=await route.fetch({maxRedirects:0});}
      catch {throw new Error('Loopback fixture transport failed; request details withheld.');}
      const headers=response.headers();
      if(route.request().method()==='POST'&&new URL(route.request().url()).pathname.startsWith('/app/creators/')){
        nativePosts.push({status:response.status()});
      }
      if(headers.location){const u=new URL(headers.location,base);if(u.origin==='https://fans.example.test')headers.location=base+u.pathname+u.search+u.hash;}
      const document=(headers['content-type']??'').includes('text/html')
        ?(await response.text()).replaceAll('https://fans.example.test',base):undefined;
      await route.fulfill({response,headers,...(document===undefined?{}:{body:document})});
    });
    if(who)await context.addCookies(Object.entries(fixture.sessions[who].cookies).map(([name,value])=>({name,value,url:base})));
    return context;
  };
  const shot=async(page,name)=>{
    assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'horizontal overflow');
    await page.screenshot({path:path.join(out,name+'.png')});
  };
  try {
    for(const width of [1440,390,320]){
      const owner=await contextFor(width,'other',false);const config=await owner.newPage();
      config.on('pageerror',e=>errors.push(e.message));
      assert.equal((await config.goto(base+'/app/creator/messages')).status(),200);
      await local(config);
      await Promise.all([config.waitForNavigation(),config.getByRole('link',{name:'Messages automatiques',exact:true}).click()]);
      await local(config);
      const welcome=config.locator('.fu-auto-setting').filter({has:config.getByRole('heading',{name:'Bienvenue',exact:true})});
      const thanks=config.locator('.fu-auto-setting').filter({has:config.getByRole('heading',{name:'Remerciement',exact:true})});
      assert.equal(await config.locator('.fu-auto-setting').count(),2);
      assert.equal(await config.locator('.fu-auto-setting input[type=checkbox],.fu-auto-setting input[type=file]').count(),0);
      assert.ok(await thanks.getByRole('option',{name:'Activé',exact:true}).isDisabled());
      const body='Bienvenue, aperçu synthétique de recette '+width+'.';
      const revision=await welcome.locator('[name=revision]').inputValue();
      await welcome.getByLabel('Votre texte').fill(body);
      await welcome.getByLabel('Envoi automatique').selectOption('yes');
      await Promise.all([config.waitForNavigation(),welcome.getByRole('button',{name:'Aperçu',exact:true}).click()]);
      assert.equal(await welcome.getByLabel('Votre texte').inputValue(),body);
      assert.equal(await welcome.getByLabel('Envoi automatique').inputValue(),'yes');
      assert.equal(await welcome.locator('[name=revision]').inputValue(),revision);
      await local(config);
      await Promise.all([config.waitForNavigation(),welcome.getByRole('button',{name:'Enregistrer bienvenue',exact:true}).click()]);
      assert.equal(await welcome.getByLabel('Envoi automatique').inputValue(),'yes');
      await welcome.getByLabel('Votre texte').focus();
      assert.notEqual(await welcome.getByLabel('Votre texte').evaluate(el=>getComputedStyle(el).outlineStyle),'none');
      await shot(config,'automatic-settings-'+width);
      const thanksBody='Remerciement préparé, recette '+width+'.';
      await thanks.getByLabel('Votre texte').fill(thanksBody);await local(config);
      await Promise.all([config.waitForNavigation(),thanks.getByRole('button',{name:'Aperçu',exact:true}).click()]);
      assert.equal(await thanks.getByLabel('Votre texte').inputValue(),thanksBody);
      await local(config);
      await Promise.all([config.waitForNavigation(),thanks.getByRole('button',{name:'Enregistrer remerciement',exact:true}).click()]);
      assert.equal(await thanks.getByLabel('Votre texte').inputValue(),thanksBody);
      assert.equal(await thanks.getByLabel('Envoi automatique').inputValue(),'no');
      assert.equal(await welcome.getByLabel('Votre texte').inputValue(),body);
      assert.equal(await welcome.getByLabel('Envoi automatique').inputValue(),'yes');
      await thanks.getByRole('heading',{name:'Remerciement',exact:true}).scrollIntoViewIfNeeded();
      await shot(config,'automatic-thanks-unavailable-'+width);

      const fan=await contextFor(width,'auto-a');const profile=await fan.newPage();
      profile.on('pageerror',e=>errors.push(e.message));
      const profileUrl=base+'/app/creators/'+fixture.creator;
      assert.equal((await profile.goto(profileUrl)).status(),200);
      await profile.locator('#fu-follow button').waitFor();await local(profile);
      await profile.locator('[data-fans-profile] [data-fans-results] h2').waitFor();
      assert.equal(await profile.locator('#fu-follow input[type=checkbox]').count(),0);
      if(await profile.getByRole('button',{name:'Suivre',exact:true}).count()){
        await Promise.all([profile.waitForNavigation(),profile.getByRole('button',{name:'Suivre',exact:true}).click()]);
        await profile.goto(profileUrl);await local(profile);
        await profile.locator('[data-fans-profile] [data-fans-results] h2').waitFor();
      }
      const postsBefore=nativePosts.length;
      await Promise.all([profile.waitForNavigation(),profile.getByRole('button',{name:'Ne plus suivre',exact:true}).click()]);
      assert.equal(nativePosts.length,postsBefore+1);assert.equal(nativePosts.at(-1).status,303);
      // Playwright does not reroute a redirected GET. Repeat only this read to normalize fixture API URLs.
      await profile.goto(profileUrl);
      assert.ok((await profile.locator('[data-fans-profile]').getAttribute('data-api')).startsWith(base));
      await profile.locator('[data-fans-profile] [data-fans-results] h2').waitFor();
      await local(profile);await profile.getByRole('button',{name:'Suivre',exact:true}).focus();
      await shot(profile,'automatic-follow-'+width);
      await Promise.all([profile.waitForNavigation(),profile.getByRole('button',{name:'Suivre',exact:true}).click()]);
      await profile.getByRole('button',{name:'Ne plus suivre',exact:true}).waitFor();
      const denied=await fan.request.post(profileUrl,{form:{follow_action:'follow',fans_follow_nonce:'invalid'}});
      assert.equal(denied.status(),403);

      const recipient=await contextFor(width,'auto-b');const conversation=await recipient.newPage();
      assert.equal((await conversation.goto(base+'/app/fan/messages?thread='+fixture.automatic_only_thread)).status(),200);
      await conversation.locator('#fu-conversation').getByText('Message automatique',{exact:true}).waitFor();
      assert.equal(await conversation.locator('.fu-message-composer textarea').count(),0);
      await conversation.getByRole('link',{name:'Écrire au créateur →',exact:true}).waitFor();
      await shot(conversation,'automatic-received-'+width);
      await local(config);await welcome.getByLabel('Envoi automatique').selectOption('no');
      await Promise.all([config.waitForNavigation(),welcome.getByRole('button',{name:'Enregistrer bienvenue',exact:true}).click()]);
      checks.push({width,independentSettings:true,nativePreviewAndSave:true,draftPreserved:true,noExtraFanConsent:true,
        freeFollowAndUnfollow:true,nonceRejection:true,automaticNotGeneralContact:true,visibleFocus:true,noHorizontalOverflow:true});
      for(const context of [owner,fan,recipient]){await context.unrouteAll({behavior:'wait'});await context.close();}
    }
    const guest=await contextFor(390,null);const page=await guest.newPage();
    assert.equal((await page.goto(base+'/app/creators/'+fixture.creator)).status(),200);
    assert.equal(await page.locator('#fu-follow').count(),0);await guest.unrouteAll({behavior:'wait'});await guest.close();
    assert.deepEqual(errors,[]);
    fs.writeFileSync(path.join(out,'browser.json'),JSON.stringify({browser:browser.version(),checks,errors,
      limits:'Disposable WordPress/MariaDB, synthetic Identity links/texts, actual WP cookies/nonces. Canonical fixture HTML and 303 locations mapped to loopback only; an extra GET after the verified native 303 normalizes API URLs for visual capture. No central SSO, target theme, TLS or physical-device proof.'},null,2));
    console.log('PASS independent automatic settings, native free follow, read-only automatic thread, 3 widths and 12 screenshots');
  } finally {
    for(const context of browser.contexts()){await context.unrouteAll({behavior:'wait'});await context.close();}
    await browser.close();
  }
})().catch(e=>{console.error(e.stack);process.exitCode=1;});
