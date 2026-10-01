// Real WordPress fixture produced by admission-wordpress.py --test --keep. No target site.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
(async () => {
  const fixture = JSON.parse(fs.readFileSync(process.env.SESSION));
  const base = process.env.BASE, out = process.env.OUT;
  assert.equal(new URL(base).hostname, '127.0.0.1');
  const id = fixture.profiles.member;
  const browser = await chromium.launch({ channel:'chrome', headless:true });
  const contexts = {}, checks = [];
  try {
    for (const role of ['member','other','admin','guest']) {
      const ctx = contexts[role] = await browser.newContext();
      await ctx.route('**/*', r => new URL(r.request().url()).origin === base ? r.continue() : r.abort());
      if (role !== 'guest') await ctx.addCookies(Object.entries(fixture.sessions[role].cookies).map(([name,value]) => ({name,value,url:base})));
    }
    async function api(role, route, data) {
      const options = {headers:role === 'guest' ? {} : {'X-WP-Nonce':fixture.sessions[role].nonce},maxRedirects:0};
      const response = data === undefined ? await contexts[role].request.get(base+'/wp-json/faluss-fans/v1/'+route,options)
        : await contexts[role].request.post(base+'/wp-json/faluss-fans/v1/'+route,{...options,data});
      return {status:response.status(),data:await response.json()};
    }
    const page = await contexts.member.newPage(), admin = await contexts.admin.newPage();
    // The fixture keeps its HTTPS SSO configuration; only native form origins are mapped to loopback.
    const localForms = p => p.locator('form[action]').evaluateAll((forms, origin) => {
      for (const form of forms) { const url=new URL(form.action); form.action=origin+url.pathname+url.search; }
    },base);
    const own = '/faluss-fans/creator/mon-profil';
    const panel = '/wp-admin/admin.php?page=faluss-fans-moderation&view=editorial&item='+id;
    const initial = (await api('guest','creators/'+id)).data.editorial;
    assert.ok(initial);
    await page.goto(base+own);
    await page.locator('#fu-public-name').fill('Nouvelle présentation · recette locale');
    await page.locator('#fu-bio').fill('Cette proposition reste privée jusqu’à décision.');
    await localForms(page);
    await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Soumettre à la modération'}).click()]);
    assert.deepEqual((await api('guest','creators/'+id)).data.editorial,initial);
    let draft = (await api('member','creators/me/editorial')).data;
    assert.equal(draft.state,'pending');
    assert.equal((await api('other',`editorial/${id}/withdraw`,{revision:Number(draft.revision)})).status,403);
    assert.equal((await api('member',`editorial/${id}/moderate`,{revision:Number(draft.revision),decision:'revoke',reason:'prohibited_content'})).status,403);
    checks.push('Real native owner submission preserves approved public projection; other owner and self-moderation denied');
    await admin.goto(base+panel);
    for (const width of [1440,390]) {
      for (const [name,p] of [['owner',page],['moderation',admin]]) {
        await p.setViewportSize({width,height:900});
        assert.ok(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
        await p.screenshot({path:`${out}/${name}-${width}.png`,fullPage:true});
      }
    }
    await admin.locator('select[name="reason"]').selectOption('needs_revision');
    await Promise.all([admin.waitForNavigation(),admin.getByRole('button',{name:'Confirmer cette décision'}).click()]);
    assert.deepEqual((await api('guest','creators/'+id)).data.editorial,initial);
    draft=(await api('member','creators/me/editorial')).data;
    assert.equal(draft.state,'rejected'); assert.equal(draft.bio,'');
    await page.goto(base+own);
    await localForms(page);
    await page.getByLabel('Effacer mon nom public').check();
    await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Retirer ma présentation'}).click()]);
    assert.equal((await api('guest','creators/'+id)).data.editorial,null);
    checks.push('Native rejection purges draft only; native owner withdrawal after rejection removes prior public version');
    draft=(await api('member','creators/me/editorial')).data;
    let result=await api('member','creators/me/editorial',{revision:Number(draft.revision),public_name:'Version approuvée de recette',bio:'Texte de recette.',portrait_id:'',portrait_revision:0});
    result=await api('admin',`editorial/${id}/moderate`,{revision:Number(result.data.revision),decision:'approve',reason:'allowed_editorial'});
    result=await api('member','creators/me/editorial',{revision:Number(result.data.revision),public_name:'Proposition distincte',bio:'À examiner.',portrait_id:'',portrait_revision:0});
    const revision=result.data.revision;
    const races=await Promise.all(['admin','admin'].map(role=>api(role,`editorial/${id}/moderate`,{revision,decision:'revoke',reason:'prohibited_content'})));
    assert.deepEqual(races.map(x=>x.status).sort(),[200,409]);
    assert.equal((await api('guest','creators/'+id)).data.editorial,null);
    draft=(await api('member','creators/me/editorial')).data;
    assert.equal(draft.published,null);assert.equal(draft.public_name,'');
    checks.push('Concurrent real HTTP revocations: exactly one 200 and one 409; draft and published version removed atomically');
    fs.writeFileSync(out+'/browser.json',JSON.stringify({browser:browser.version(),checks},null,2));
    console.log('PASS real WordPress editorial forms, permissions, preserved approval, rejection, withdrawal, concurrent revocation and four captures');
  } finally { await browser.close(); }
})().catch(error=>{console.error(error.message);process.exitCode=1;});
