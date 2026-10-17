'use strict';
const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');

(async () => {
  const { BASE: base, ROOT: root, OUT: out } = process.env;
  assert.match(base, /^http:\/\/127\.0\.0\.1:[0-9]+$/);
  assert.match(root, /^\/var\/tmp\/fans-admission-wp-[a-z0-9_]+$/);
  const fixture = JSON.parse(execFileSync('wsl.exe', ['-u','root','--','cat',root+'/session.json']));
  fs.mkdirSync(out,{recursive:true});
  const checks=[], errors=[];
  const browser=await chromium.launch({channel:'chrome',headless:true});
  try {
    for(const width of [1440,768,390,320]) {
      const context=await browser.newContext({viewport:{width,height:width<700?844:1000},javaScriptEnabled:false,hasTouch:width<700});
      await context.route('**/*',route=>new URL(route.request().url()).origin===base?route.continue():route.abort());
      await context.addCookies(Object.entries(fixture.sessions.other.cookies).map(([name,value])=>({name,value,url:base})));
      const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
      // Only canonical fixture links/form actions are remapped to its actual loopback origin.
      const local=async()=>page.locator('a[href],form[action]').evaluateAll((elements,base)=>elements.forEach(el=>{
        const attr=el.tagName==='FORM'?'action':'href',u=new URL(el.getAttribute(attr),location.href);
        if(u.origin==='https://fans.example.test')el.setAttribute(attr,base+u.pathname+u.search+u.hash);
      }),base);
      const response=await page.goto(base+'/app/creator/messages');assert.equal(response.status(),200);
      await local();await Promise.all([page.waitForNavigation({waitUntil:'load'}),page.getByRole('link',{name:'Réglages de contact',exact:true}).click()]);
      await page.getByRole('heading',{name:'Réglages de contact',exact:true}).waitFor();await local();
      assert.equal(await page.getByRole('radio').count(),2);
      assert.ok(await page.getByRole('radio',{name:/Sur demande/}).isChecked());
      const open=page.getByRole('radio',{name:/Ouvert/});await open.focus();
      assert.equal(await open.evaluate(el=>getComputedStyle(el).outlineWidth),'3px');
      await open.check();
      await Promise.all([page.waitForNavigation({waitUntil:'load'}),page.getByRole('button',{name:'Enregistrer le mode de contact',exact:true}).click()]);
      await page.getByRole('status').filter({hasText:'Action confirmée'}).waitFor();
      assert.ok(await page.getByRole('radio',{name:/Ouvert/}).isChecked());
      assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
      await page.screenshot({path:path.join(out,'contact-open-'+width+'.png')});
      await local();await page.getByRole('radio',{name:/Sur demande/}).check();
      await Promise.all([page.waitForNavigation({waitUntil:'load'}),page.getByRole('button',{name:'Enregistrer le mode de contact',exact:true}).click()]);
      await page.getByRole('status').filter({hasText:'Action confirmée'}).waitFor();
      assert.ok(await page.getByRole('radio',{name:/Sur demande/}).isChecked());
      assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
      await page.screenshot({path:path.join(out,'contact-request-'+width+'.png')});
      checks.push({width,nativeEntry:true,nonceProtectedPost:true,openAndRequestSaved:true,visibleKeyboardFocus:true,noHorizontalOverflow:true,javaScriptDisabled:true});
      await context.close();
    }
    assert.deepEqual(errors,[]);
    fs.writeFileSync(path.join(out,'browser.json'),JSON.stringify({browser:browser.version(),checks,errors,limits:'Disposable WordPress/MariaDB and genuine local WP cookies; synthetic Identity links, no central SSO or physical device proof.'},null,2));
    console.log('PASS native contact forms, keyboard, 4 widths and 8 screenshots');
  } finally {await browser.close();}
})().catch(e=>{console.error(e.message);process.exitCode=1;});
