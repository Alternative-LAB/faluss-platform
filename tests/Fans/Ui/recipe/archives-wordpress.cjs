const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
(async () => {
  const fixture = JSON.parse(fs.readFileSync(process.env.SESSION)), base = process.env.BASE, out = process.env.OUT;
  assert.equal(new URL(base).hostname, '127.0.0.1');
  const browser = await chromium.launch({channel:'chrome',headless:true}), contexts = {};
  try {
    for (const role of ['guest','fan','member','admin']) {
      const ctx = contexts[role] = await browser.newContext();
      await ctx.route('**/*', r => new URL(r.request().url()).origin === base ? r.continue() : r.abort());
      await ctx.addInitScript(origin => {
        const native = window.fetch;
        window.fetch = (input, options) => {
          const url = new URL(input instanceof Request ? input.url : input, location.href);
          if (url.origin === 'https://fans.example.test') input = origin + url.pathname + url.search;
          return native(input, options);
        };
      }, base);
      if (role !== 'guest') await ctx.addCookies(Object.entries(fixture.sessions[role].cookies).map(([name,value])=>({name,value,url:base})));
    }
    const request = async(role,path,data,key) => {
      const headers = role === 'guest' ? {} : {'X-WP-Nonce':fixture.sessions[role].nonce};
      if (key) headers['Idempotency-Key'] = key;
      const response = await contexts[role].request[data ? 'post' : 'get'](base+'/wp-json/faluss-fans/v1/'+path,{headers,...(data?{data}:{})});
      return {status:response.status(),data:await response.json()};
    };
    const archived = [];
    for (let i=0;i<8;i++) {
      const create = await request('member','text-publications',{text:'Publication de recette à archiver '+i,category:'hosted_allowed_content'},require('node:crypto').randomUUID());
      assert.equal(create.status,201);const item=create.data;
      const decision = await request(i%2 ? 'member' : 'admin','text-publications/'+item.publication_id+(i%2?'/withdraw':'/moderate'),
        i%2 ? {revision:item.revision} : {revision:item.revision,decision:'reject',reason:'needs_revision'});
      assert.equal(decision.status,200);assert.equal(decision.data.body,'');archived.push(item.publication_id);
    }
    const long = 'Publication de recette approuvée. '.repeat(30);
    const created = await request('member','text-publications',{text:long,category:'hosted_allowed_content'},require('node:crypto').randomUUID());
    assert.equal(created.status,201);
    assert.equal((await request('admin','text-publications/'+created.data.publication_id+'/moderate',{revision:created.data.revision,decision:'approve',reason:'allowed_text'})).status,200);
    const history = await request('member','text-publications/mine?bucket=archive&per_page=3');
    assert.equal(history.status,200);assert.equal(history.data.items.length,3);assert.ok(history.data.next_cursor);
    assert.equal((await request('member','text-publications/mine?bucket=current&cursor='+encodeURIComponent(history.data.next_cursor))).status,400);
    assert.ok((await request('guest','text-publications/mine?bucket=archive')).status>=400);
    assert.equal((await request('fan','text-publications/mine?bucket=archive')).status,403);
    const found=[];let cursor=null;
    do {
      const page=await request('member','text-publications/mine?bucket=archive&per_page=3'+(cursor?'&cursor='+encodeURIComponent(cursor):''));
      assert.equal(page.status,200);for(const row of page.data.items){assert.ok(['rejected','withdrawn'].includes(row.state));assert.equal(row.body,'');found.push(row.publication_id);}
      cursor=page.data.next_cursor;
    }while(cursor);
    assert.equal(new Set(found).size,found.length);for(const id of archived)assert.ok(found.includes(id));
    const page=await contexts.member.newPage();
    for(const width of [1440,390]){
      await page.setViewportSize({width,height:900});await page.goto(base+'/app/creator/creer');
      assert.equal(await page.locator('.fu-author__item').filter({hasText:/^(Refusé|Retiré)/}).count(),0);
      await page.goto(base+'/app/creator/creer?archive=1');
      assert.equal(await page.locator('.fu-author__item').count(),6);
      assert.equal(await page.locator('.fu-author__item .fu-text-body').count(),0);
      assert.equal(await page.locator('.fu-author__item').filter({has:page.locator('h3', {hasText:'Retiré'})}).locator('a').count(),0);
      assert.ok((await page.getByRole('link',{name:'Page suivante des publications'}).getAttribute('href')).includes('archive=1'));
      assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
      await page.screenshot({path:out+'/archive-'+width+'.png'});
      await page.goto(base+'/app/creator/explorer');await page.locator('.fu-text-card').first().waitFor();
      const card=page.locator('.fu-text-card').filter({hasText:'Publication de recette approuvée.'}).first();
      const expand=card.getByRole('button',{name:/Lire la suite|Réduire la publication/});await expand.focus();await page.keyboard.press('Enter');
      assert.equal(await expand.getAttribute('aria-expanded'),'true');assert.equal(await card.locator('.fu-text-body').textContent(),long);
      await page.keyboard.press('Enter');assert.equal(await expand.getAttribute('aria-expanded'),'false');
      await page.screenshot({path:out+'/publications-'+width+'.png'});
    }
    fs.writeFileSync(out+'/browser.json',JSON.stringify({browser:browser.version(),archived:archived.length,checks:['Real WordPress creates, rejects, withdraws and approves','Current/archive SQL pagination, cursor isolation, guest and foreign member denial','No terminal bodies or withdrawn links, accessible excerpts and desktop/mobile captures']},null,2));
    console.log('PASS real WordPress publication archive and reading browser checks');
  }finally{await browser.close();}
})().catch(e=>{console.error(e.stack);process.exitCode=1;});
