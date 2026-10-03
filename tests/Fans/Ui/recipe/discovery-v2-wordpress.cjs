// Real WordPress + private MariaDB fixture; no API mocks or external hosts.
const { chromium, request } = require('playwright');
const assert = require('node:assert/strict'), fs = require('node:fs'), { execFileSync } = require('node:child_process');
(async () => {
  const { BASE: base, ROOT: root, OUT: out, REPO_WSL: repo } = process.env;
  assert.equal(new URL(base).hostname, '127.0.0.1'); assert.match(root, /^\/var\/tmp\/fans-admission-wp-[a-z0-9_]+$/);
  const wsl = args => execFileSync('wsl.exe', ['-u', 'root', '--', ...args]);
  const fixture = JSON.parse(wsl(['cat', root + '/session.json']));
  const state = value => wsl(['php', '/var/tmp/faluss-v3-wp/wp-cli.phar', '--allow-root', '--path=' + root + '/wordpress', 'eval-file', repo + '/tests/Fans/Ui/recipe/discovery-state.php', root + '/session.json', value, '--use-include']);
  const sorted = [...fixture.discovery].sort((a,b) => b.created_at.localeCompare(a.created_at) || b.id.localeCompare(a.id));
  const checks = [], errors = [], shots = [], requests = [];
  fs.mkdirSync(out, { recursive:true });
  const api = await request.newContext({baseURL:base});
  const get = async (path, code=200) => { const r = await api.get(path); assert.equal(r.status(), code, path); if (code===200) assert.match(r.headers()['cache-control'], /no-store/); return r.json(); };
  const ns = '/wp-json/faluss-fans/v1/';
  state('many');
  assert.deepEqual((await get(ns+'creators/discovery?per_page=3')).items.map(p=>p.creator_id), sorted.slice(0,3).map(p=>p.id));
  for(const path of ['creators/discovery?per_page=21','creators/discovery?category=bad','creators/discovery?cursor=invalid','text-publications?public_image=1','text-publications?creator_id='+fixture.profiles.member+'&public_image=0']) await get(ns+path,400);
  await get(ns+'creators/me/editorial',401);
  await get(ns+'images/'+fixture.image+'/file',404);
  const publicPage=await get(ns+'text-publications?creator_id='+fixture.profiles.member+'&public_image=1');
  assert.equal(publicPage.items.filter(p=>p.has_public_image).length,1);
  checks.push('Real guest REST: newest3, no-store, input validation, private reads denied, minimal image eligibility');
  const browser=await chromium.launch({channel:'chrome',headless:true});
  try {
    for (const scenario of ['one','many','no-media','empty']) {
      state(scenario);
      for (const role of ['guest','fan']) for (const width of [1440,390]) {
        const context=await browser.newContext({viewport:{width,height:width===390?844:1000},hasTouch:width===390,isMobile:width===390});
        await context.route('**/*',route=>new URL(route.request().url()).origin===base ? route.continue():route.abort());
        if(role==='fan') await context.addCookies(Object.entries(fixture.sessions.fan.cookies).map(([name,value])=>({name,value,url:base})));
        const page=await context.newPage(); page.on('pageerror',error=>errors.push(error.message));
        const imageRequests=[]; page.on('request',r=>{if(/text-publications\/[^/]+\/image\//.test(r.url())) imageRequests.push(new URL(r.url()).pathname);});
        for (const view of ['explorer','profile']) {
          if(scenario==='empty'&&view==='profile')continue;
          imageRequests.length=0;
          const path=view==='explorer'?'/app/fan/explorer':'/app/creators/'+fixture.profiles.member;
          assert.equal((await page.goto(base+path)).status(),200);
          await page.waitForFunction(()=> !document.querySelector('[data-fans-status]')?.textContent.includes('Chargement'));
          await page.evaluate(()=>document.fonts.ready);
          if(view==='explorer') {
            assert.equal(await page.locator('[data-fans-publications]').count(),0);
            if(scenario!=='empty') {
              assert.equal(await page.locator('.fu-discovery-hero__slide').count(),3);
              assert.deepEqual(await page.locator('.fu-discovery-hero__slide h2').allTextContents(),scenario==='many'?sorted.slice(0,3).map(p=>p.name):Array(3).fill(fixture.discovery[0].name));
              const next=page.getByRole('button',{name:'Créateur suivant',exact:true});
              await next.focus();await page.keyboard.press('Enter');
              assert.equal(await page.locator('.fu-discovery-hero__slide:visible').getAttribute('id'),'fu-discovery-slide-1');
              assert.equal(await next.evaluate(el=>el===document.activeElement),true);
              await page.getByRole('button',{name:'Créateur précédent',exact:true}).click();
              if(scenario!=='no-media') await page.locator('.fu-discovery-hero__slide:visible img').waitFor();
              if(scenario==='many') {
                const track=page.locator('.fu-discovery-row__track').first();
                assert.equal(await track.locator('article').count(),10);
                await track.focus(); await page.keyboard.press('ArrowRight');
                await page.waitForFunction(()=>document.querySelector('.fu-discovery-row__track').scrollLeft>0);
                if(width===390){await track.evaluate(el=>el.scrollTo({left:el.scrollWidth,behavior:'instant'}));assert.ok(await track.evaluate(el=>el.scrollLeft>0));}
                await track.evaluate(el=>el.scrollTo({left:0,behavior:'instant'}));
              }
            } else assert.equal(await page.locator('.fu-discovery-hero__slide').count(),0);
          } else {
            await page.waitForFunction(()=>!document.querySelector('[data-text-status]')?.textContent.includes('Chargement'));
            assert.equal(await page.locator('.fu-text-card').count(),3);
            if(scenario==='no-media') {assert.equal(await page.locator('[data-image-load]').count(),0);assert.equal(imageRequests.length,0);}
            else {
              await page.locator('.fu-publication-image__output img').waitFor();
              assert.equal(await page.locator('[data-image-load]').count(),1);
              assert.equal(imageRequests.length,1);
              assert.ok(imageRequests[0].includes('/'+fixture.publications[0]+'/image/'));
              assert.equal(await page.locator('.fu-text-card').filter({hasNot:page.locator('[data-image-load]')}).count(),2);
              await page.locator('.fu-public-creator__portrait img').waitFor();
            }
            assert.equal(await page.locator('.fu-public-creator__copy h2').innerText(),fixture.discovery[0].name);
          }
          assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'document overflow');
          const visible=await page.locator('.fu-main').innerText();
          assert.ok(!/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/.test(visible));
          assert.ok(!/Retrouvez votre espace|flags|après approbation/.test(visible));
          if(scenario==='no-media') assert.equal(await page.locator('.fu-discovery-hero img,.fu-discovery-card img,.fu-public-creator__portrait img,.fu-publication-image img').count(),0);
          await page.evaluate(()=>scrollTo(0,0));
          const file=`${scenario}-${role}-${view}-${width}.png`;
          await page.screenshot({path:out+'/'+file,fullPage:true,animations:'disabled'}); shots.push(file);
          checks.push(`${scenario}/${role}/${view}/${width}: HTTP200, order, media permission, keyboard, no overflow/UUID`);
          if(scenario==='many'&&view==='explorer') {
            await page.getByRole('button',{name:'Voir tous les créateurs : Arts',exact:true}).click();
            await page.waitForFunction(()=>document.querySelector('.fu-discovery-pagination'));
            const names=[];
            for(let n=1;n<=4;n++) {
              await page.waitForFunction(n=>document.querySelector('.fu-discovery-pagination span')?.textContent===`Page ${n}`,n);
              names.push(...await page.locator('.fu-discovery-card h3').allTextContents());
              if(n<4){await page.getByRole('button',{name:'Suivants',exact:true}).focus();await page.keyboard.press('Enter');}
            }
            assert.deepEqual(names,sorted.filter(p=>p.category==='arts').map(p=>p.name));
            assert.equal(names.length,35);assert.equal(await page.getByRole('button',{name:'Suivants',exact:true}).isDisabled(),true);
            assert.equal(await page.locator('.fu-discovery-row h2').evaluate(el=>el===document.activeElement),true);
            await page.getByRole('button',{name:'Précédents',exact:true}).click();
            await page.waitForFunction(()=>document.querySelector('.fu-discovery-pagination span')?.textContent==='Page 3');
            assert.deepEqual(await page.locator('.fu-discovery-card h3').allTextContents(),names.slice(20,30));
            const pageFile=`pagination-${role}-${width}.png`; await page.screenshot({path:out+'/'+pageFile,fullPage:true,animations:'disabled'}); shots.push(pageFile);
            checks.push(`${role}/${width}: Voir tous traversed all35, keyboard next/previous, last-page boundary, stable focus`);
          }
        }
        requests.push({scenario,role,width,publicImageRequests:imageRequests.length});
        await context.close();
      }
    }
    state('many');
    for(const [role,path] of [['guest','/app/fan/hof'],['fan','/app/fan/accueil'],['member','/app/creator/progression'],['member','/app/creator/mon-profil']]) {
      const c=await browser.newContext();await c.route('**/*',route=>new URL(route.request().url()).origin===base?route.continue():route.abort());
      if(role!=='guest')await c.addCookies(Object.entries(fixture.sessions[role].cookies).map(([name,value])=>({name,value,url:base})));
      const p=await c.newPage();assert.equal((await p.goto(base+path)).status(),200);assert.equal(await p.locator('link[href*="fans-discovery.css"]').count(),0);
      checks.push(`${role}${path}: unrelated page HTTP200 and no discovery stylesheet`);await c.close();
    }
    assert.deepEqual(errors,[]);
    fs.writeFileSync(out+'/browser.json',JSON.stringify({environment:'Disposable WordPress 7.1.2 / MariaDB 11.8.6 / PHP 8.5.4 / TwentyTwentyFive; no target-site or external access',browser:browser.version(),checks,shots,requests,pageErrors:errors},null,2));
    console.log(JSON.stringify({checks:checks.length,screenshots:shots.length,pageErrors:errors.length}));
  } finally {await browser.close();await api.dispose();}
})().catch(error=>{console.error(error);process.exitCode=1});
