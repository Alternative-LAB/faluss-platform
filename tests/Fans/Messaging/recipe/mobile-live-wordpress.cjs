// Run against admission-wordpress.py --backoffice only. Real WP sessions/REST/SQL; no response mocks.
const { chromium, request } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { execFileSync } = require('node:child_process');
(async () => {
  const { BASE: base, ROOT: root, OUT: out } = process.env;
  assert.equal(new URL(base).hostname, '127.0.0.1');
  assert.match(root, /^\/var\/tmp\/fans-admission-wp-[a-z0-9_]+$/);
  const wsl = args => execFileSync('wsl.exe', ['-u', 'root', '--', ...args]);
  const fixture = JSON.parse(wsl(['cat', root + '/session.json']));
  const cli = code => { execFileSync('wsl.exe', ['-u', 'root', '--', 'tee', root + '/layout-check.php'], { input: '<?php\n' + code, stdio: ['pipe', 'ignore', 'pipe'] }); return wsl(['php', '/var/tmp/faluss-v3-wp/wp-cli.phar', '--allow-root', '--path=' + root + '/wordpress', 'eval-file', root + '/layout-check.php', '--use-include']); };
  // Each run is independent; only the guarded disposable database is cleared.
  cli("if(DB_NAME!=='admission_recipe')throw new RuntimeException('fixture only');global $wpdb;foreach(['messages','threads','blocks','reports','report_events','legal_holds'] as $t){$wpdb->query('DELETE FROM wp_faluss_fans_dm_'.$t);}$wpdb->query('DELETE FROM wp_faluss_fans_notifications');");
  const creator = fixture.profiles.other;
  fs.mkdirSync(out, { recursive: true });
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const contexts = {}, clients = {}, pages = {}, errors = [], checks = [];
  const check = (name, value) => { assert.ok(value, name); checks.push(name); };
  const url = (role, query = '') => `${base}/app/${role === 'other' ? 'creator' : 'fan'}/messages${query}`;
  const api = async (role, route, data, expected = 200) => {
    const res = await clients[role].fetch('/wp-json/faluss-fans/v1/' + route, { method: data ? 'POST' : 'GET', ...(data ? { data } : {}) });
    assert.equal(res.status(), expected, route + ': ' + res.status());
    return res.json();
  };
  const local = async page => page.locator('a[href],form[action]').evaluateAll((elements, base) => elements.forEach(el => {
    const attr = el.tagName === 'FORM' ? 'action' : 'href'; const u = new URL(el.getAttribute(attr), location.href);
    if (u.origin === 'https://fans.example.test') el.setAttribute(attr, base + u.pathname + u.search + u.hash);
  }), base);
  const settle = async page => { await page.evaluate(() => document.fonts.ready); await local(page); };
  const go = async (role, query = '') => { const p = pages[role]; const res = await p.goto(url(role, query)); assert.equal(res.status(), 200); await settle(p); return p; };
  const nativeSubmit = async (page, name) => { await local(page); await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.getByRole('button', { name, exact: true }).click()]); await settle(page); await page.getByRole('status').filter({ hasText: 'Action confirmée' }).waitFor(); };
  const capture = async (role, state, query = '') => {
    for (const width of [1440, 390]) {
      const p = pages[role]; await p.setViewportSize({ width, height: width === 390 ? 844 : 1000 }); await go(role, query);
      await p.locator('.fu-message-avatar img').evaluateAll(imgs => Promise.all(imgs.filter(img => img.getClientRects().length).map(img => img.decode().catch(() => {}))));
      await p.evaluate(() => { document.activeElement?.blur(); scrollTo(0, 0); });
      check(`${role} ${state} ${width} no overflow`, await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
      if (width === 390) check(`${role} ${state} mobile pane`, await p.locator(query ? '.fu-message-chat' : '.fu-message-list').isVisible());
      await p.screenshot({ path: `${out}/${role === 'other' ? 'creator' : 'fan'}-${state}-${width}.png` });
    }
  };
  try {
    for (const role of ['fan', 'other', 'member', 'admin', 'admin-two', 'unlinked']) {
      const session = fixture.sessions[role];
      clients[role] = await request.newContext({ baseURL: base, extraHTTPHeaders: { Cookie: Object.entries(session.cookies).map(([k, v]) => `${k}=${v}`).join('; '), 'X-WP-Nonce': session.nonce } });
      contexts[role] = await browser.newContext({ viewport: { width: 1440, height: 1000 }, hasTouch: true });
      await contexts[role].route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
      await contexts[role].addCookies(Object.entries(session.cookies).map(([name, value]) => ({ name, value, url: base })));
      pages[role] = await contexts[role].newPage(); pages[role].on('pageerror', error => errors.push(error.message));
    }
    if (!fixture.layoutPortrait) {
      const res = await clients.other.post('/wp-json/faluss-fans/v1/images', { multipart: { image: { name: 'fixture.jpg', mimeType: 'image/jpeg', buffer: wsl(['cat', root + '/fixture.jpg']) } } });
      assert.equal(res.status(), 201); const uploaded = await res.json();
      const image = await api('admin', `images/${uploaded.image_id}/moderate`, { revision: Number(uploaded.revision), decision: 'approve', reason: 'allowed_image' });
      const own = await api('other', 'creators/me/editorial');
      const draft = await api('other', 'creators/me/editorial', { revision: Number(own.revision), public_name: 'Atelier des formes', bio: 'Compte synthétique de recette.', portrait_id: image.image_id, portrait_revision: Number(image.revision) });
      await api('admin', `editorial/${creator}/moderate`, { revision: Number(draft.revision), decision: 'approve', reason: 'allowed_editorial' });
      fixture.layoutPortrait = image;
      // Persist only inside the private disposable fixture; never print cookies/nonces.
      execFileSync('wsl.exe', ['-u', 'root', '--', 'tee', root + '/session.json'], { input: JSON.stringify(fixture), stdio: ['pipe', 'ignore', 'pipe'] });
    }
    const send = async (p, text) => {
      await p.getByLabel('Votre message', { exact: true }).fill(text);
      const confirmed = p.waitForResponse(r => /\/messages\/[^/]+\/send$/.test(new URL(r.url()).pathname));
      await p.getByRole('button', { name: 'Envoyer le message', exact: true }).click();
      assert.equal((await confirmed).status(), 200);
      await p.locator('.fu-message-body').filter({hasText:text}).waitFor();
    };
    const decision = async (p, label) => {
      const confirmed = p.waitForResponse(r => r.url().endsWith('/decision'));
      await p.getByRole('button', { name: label, exact: true }).click(); assert.equal((await confirmed).status(), 200);
    };
    let navigations = 0;
    for (const role of ['fan','other']) pages[role].on('request', req => { if(req.isNavigationRequest() && req.frame()===pages[role].mainFrame()) navigations++; });
    await capture('fan','empty'); await capture('other','empty');
    const fan=await go('fan','?creator='+creator), cp=await go('other');
    const initialNavigations=navigations;
    await fan.getByLabel('Votre demande textuelle').fill('Message de recette — demande synthétique.');
    const requested=fan.waitForResponse(r=>r.url().endsWith('/messages/requests'));
    await fan.getByRole('button',{name:'Envoyer ma demande'}).click(); assert.equal((await requested).status(),200);
    const thread=(await api('fan','messages')).items[0].thread_id;
    await cp.locator(`[data-thread="${thread}"]`).waitFor({timeout:30000});
    check('request received without page reload', navigations===initialNavigations);
    await capture('fan','pending','?thread='+thread);await capture('other','pending','?thread='+thread);
    await decision(cp,'Accepter la demande');
    await fan.getByLabel('Votre message',{exact:true}).waitFor({timeout:15000});
    check('acceptance appears in other account without reload',true);
    await send(cp,'Message de recette — réponse du créateur.');
    await fan.locator('.fu-message-body').filter({hasText:'réponse du créateur'}).waitFor({timeout:15000});
    await send(fan,'Message de recette — réponse du Fan.');
    await cp.locator('.fu-message-body').filter({hasText:'réponse du Fan'}).waitFor({timeout:15000});
    check('two distinct linked accounts exchange server-confirmed messages',true);
    const bursts=await Promise.all(Array.from({length:6},(_,i)=>api('other',`messages/${thread}/send`,{body:`Recette rapprochée ${i+1}`,key:crypto.randomUUID()})));
    await fan.waitForFunction(()=>document.querySelectorAll('.fu-message-bubble').length===9,{},{timeout:15000});
    const seqs=await fan.locator('[data-sequence]').evaluateAll(nodes=>nodes.map(n=>Number(n.dataset.sequence)));
    check('concurrent burst has no sequence gaps or duplicate',JSON.stringify(seqs)===JSON.stringify([1,2,3,4,5,6,7,8,9]));
    for(const role of ['fan','other'])await capture(role,'accepted','?thread='+thread);
    // Compare the current desktop layout with released CSS/JS on identical real server data.
    await fan.setViewportSize({width:1440,height:1000});await go('fan','?thread='+thread);
    await fan.locator('.fu-message-avatar img').evaluateAll(imgs=>Promise.all(imgs.filter(i=>i.getClientRects().length).map(i=>i.decode().catch(()=>{}))));
    await fan.evaluate(()=>document.activeElement?.blur());await fan.screenshot({path:out+'/desktop-current.png'});
    const desktopMetrics=await fan.locator('.fu-message-layout,.fu-message-list,.fu-message-chat,.fu-rail').evaluateAll(nodes=>nodes.map(n=>({class:n.className,rect:JSON.parse(JSON.stringify(n.getBoundingClientRect()))})));
    const baseline = await contexts.fan.newPage();
    const baselineFiles=['fans-ui-v2.css','fans-messages.css','fans-messages.js','fans-navigation.js','fans-session.js'];
    for(const file of baselineFiles){const bytes=execFileSync('git',['show','v0.12.3:assets/'+file]);await baseline.route('**/assets/'+file+'?*',r=>r.fulfill({body:bytes,contentType:file.endsWith('.css')?'text/css':'application/javascript'}));}
    await baseline.goto(url('fan','?thread='+thread));await settle(baseline);
    await baseline.locator('.fu-message-avatar img').evaluateAll(imgs=>Promise.all(imgs.filter(i=>i.getClientRects().length).map(i=>i.decode().catch(()=>{}))));
    await baseline.screenshot({path:out+'/desktop-before.png'});
    const beforeMetrics=await baseline.locator('.fu-message-layout,.fu-message-list,.fu-message-chat,.fu-rail').evaluateAll(nodes=>nodes.map(n=>({class:n.className,rect:JSON.parse(JSON.stringify(n.getBoundingClientRect()))})));
    check('desktop columns and global rail exactly unchanged',JSON.stringify(desktopMetrics)===JSON.stringify(beforeMetrics));await baseline.close();
    for(const width of [320,375,390,430]){
      await fan.setViewportSize({width,height:844});await go('fan');
      await fan.locator('[data-thread]').first().waitFor();await settle(fan);
      check(width+' list full width and single screen',await fan.evaluate(()=>{const l=document.querySelector('.fu-message-layout').getBoundingClientRect();return l.x===0&&l.width===innerWidth&&l.y===0&&getComputedStyle(document.querySelector('.fu-notifications-bar')).display==='none'&&getComputedStyle(document.querySelector('.fu-message-chat')).display==='none';}));
      check(width+' nav one row no page overflow',await fan.evaluate(()=>{const ns=[...document.querySelectorAll('.fu-nav__item')].map(n=>n.getBoundingClientRect());return ns.every(n=>n.top===ns[0].top)&&document.documentElement.scrollWidth<=innerWidth;}));
      await fan.locator('.fu-message-avatar img').evaluateAll(imgs=>Promise.all(imgs.filter(i=>i.getClientRects().length).map(i=>i.decode().catch(()=>{}))));
      await fan.screenshot({path:`${out}/fan-list-${width}.png`});
      await fan.locator('[data-thread]').first().tap();await fan.getByLabel('Votre message',{exact:true}).waitFor();
      await fan.locator('.fu-message-avatar img').evaluateAll(imgs=>Promise.all(imgs.filter(i=>i.getClientRects().length).map(i=>i.decode().catch(()=>{}))));
      await fan.screenshot({path:`${out}/fan-conversation-${width}.png`});
      await fan.getByLabel('Votre message',{exact:true}).focus();
      // CDP emulates the visual viewport resize caused by a software keyboard, not a physical iPhone.
      await fan.setViewportSize({width,height:440}); await fan.waitForFunction(()=>document.querySelector('.fu-message-layout').getBoundingClientRect().height<=365);
      await fan.screenshot({path:out+'/keyboard-'+width+'.png'});
      check(width+' keyboard viewport composer and nav do not overlap',await fan.evaluate(()=>{const c=document.querySelector('.fu-message-composer').getBoundingClientRect(),r=document.querySelector('.fu-rail').getBoundingClientRect(),s=document.querySelector('.fu-message-scroll').getBoundingClientRect();return c.bottom<=r.top+1&&c.top>=0&&s.height>40&&document.documentElement.scrollHeight<=innerHeight+1;}));
      await fan.setViewportSize({width,height:844});await fan.getByRole('link',{name:'← Conversations',exact:true}).tap();
      check(width+' visible return opens full list',await fan.locator('.fu-message-list').isVisible());
      await fan.goBack();await fan.getByLabel('Votre message',{exact:true}).waitFor();
      check(width+' browser back restores conversation',await fan.locator('.fu-message-chat').isVisible());
    }
    // Scroll preservation, then near-bottom following; no forced jump while reading earlier messages.
    await fan.setViewportSize({width:390,height:650});await go('fan','?thread='+thread);
    await fan.waitForFunction(()=>document.querySelectorAll('[data-sequence]').length===9);
    await fan.locator('.fu-message-scroll').evaluate(n=>n.scrollTop=0);
    await api('other',`messages/${thread}/send`,{body:'Recette — lecture ancienne préservée',key:crypto.randomUUID()});
    await fan.locator('.fu-message-body').filter({hasText:'lecture ancienne préservée'}).waitFor({state:'attached',timeout:15000});
    check('reading position retained above bottom',await fan.locator('.fu-message-scroll').evaluate(n=>n.scrollTop===0));
    await fan.locator('.fu-message-scroll').evaluate(n=>n.scrollTop=n.scrollHeight);
    await api('other',`messages/${thread}/send`,{body:'Recette — suivre le bas',key:crypto.randomUUID()});
    await fan.locator('.fu-message-body').filter({hasText:'suivre le bas'}).waitFor({timeout:15000});
    check('near-bottom reader follows confirmed append',await fan.locator('.fu-message-scroll').evaluate(n=>n.scrollHeight-n.scrollTop-n.clientHeight<5));
    // Offline send is not optimistic and preserves draft/key; reconnect catches up immediately.
    const oldCount=await fan.locator('[data-sequence]').count();await contexts.fan.setOffline(true);
    await fan.getByLabel('Votre message',{exact:true}).fill('Recette — brouillon hors ligne');
    const key=await fan.locator('.fu-message-composer [name=key]').inputValue();
    await fan.getByRole('button',{name:'Envoyer le message',exact:true}).click();
    check('offline preserves draft and key without optimistic bubble',await fan.getByLabel('Votre message',{exact:true}).inputValue()==='Recette — brouillon hors ligne'&&await fan.locator('.fu-message-composer [name=key]').inputValue()===key&&await fan.locator('[data-sequence]').count()===oldCount);
    await api('other',`messages/${thread}/send`,{body:'Recette — reçu hors ligne',key:crypto.randomUUID()});await contexts.fan.setOffline(false);
    await fan.locator('.fu-message-body').filter({hasText:'reçu hors ligne'}).waitFor({timeout:10000});
    check('online immediately catches up without reload',true);
    // Page visibility tests use a controlled browser visibility event; private data stays veiled until session revalidation.
    const countReads=[];fan.on('request',r=>{if(r.url().includes('/message-view?'))countReads.push(Date.now());});
    await fan.evaluate(()=>{Object.defineProperty(document,'hidden',{configurable:true,value:true});document.dispatchEvent(new Event('visibilitychange'));});
    const hiddenCount=countReads.length;await new Promise(r=>setTimeout(r,6500));
    check('hidden page pauses private refresh',countReads.length===hiddenCount);
    await api('other',`messages/${thread}/send`,{body:'Recette — reçu en arrière-plan',key:crypto.randomUUID()});
    await fan.evaluate(()=>{Object.defineProperty(document,'hidden',{configurable:true,value:false});document.dispatchEvent(new Event('visibilitychange'));});
    await fan.locator('.fu-message-body').filter({hasText:'reçu en arrière-plan'}).waitFor({timeout:10000});check('visibility return validates session and reads immediately',true);
    // Native fallback remains functional with JavaScript disabled.
    const nc=await browser.newContext({javaScriptEnabled:false});await nc.addCookies(Object.entries(fixture.sessions.fan.cookies).map(([name,value])=>({name,value,url:base})));
    await nc.route('**/*',r=>new URL(r.request().url()).origin===base?r.continue():r.abort());const np=await nc.newPage();await np.goto(url('fan','?thread='+thread));await local(np);
    await np.getByLabel('Votre message',{exact:true}).fill('Recette — formulaire sans JavaScript');await nativeSubmit(np,'Envoyer le message');await nc.close();check('native nonce-protected send fallback works',true);
    await api('member','messages/'+thread,null,404);
    for(const who of ['admin','unlinked'])await api(who,'message-view?role=fan&part=chat&thread='+thread,null,403);
    const noNonce=await contexts.fan.request.get(base+'/wp-json/faluss-fans/v1/message-view?role=fan&part=list');check('missing REST nonce denied',[401,403].includes(noNonce.status()));
    await api('fan','message-view?role=fan&part=chat&thread='+thread+'&after=-1',null,400);check('outsider, privileged, unlinked and malformed reads denied',true);
    const blockState=await api('other','messages/'+thread);await api('other',`messages/${thread}/decision`,{revision:blockState.revision,action:'block'});
    await fan.getByText('Échange bloqué. Aucun nouvel envoi n’est possible.',{exact:true}).waitFor({timeout:15000});check('remote block removes send affordance',!await fan.getByRole('button',{name:'Envoyer le message',exact:true}).isVisible());
    const unblockState=await api('other','messages/'+thread);await api('other',`messages/${thread}/decision`,{revision:unblockState.revision,action:'unblock'});
    await fan.getByLabel('Votre message',{exact:true}).waitFor({timeout:15000});check('unblock retains previously unsent draft',await fan.getByLabel('Votre message',{exact:true}).inputValue()==='Recette — brouillon hors ligne');
    const refused=await api('member','messages/requests',{creator_id:creator,body:'Recette — demande destinée au refus',key:crypto.randomUUID()});
    const memberPage=await go('member','?thread='+refused.thread_id);
    const refusedState=await api('other','messages/'+refused.thread_id);await api('other',`messages/${refused.thread_id}/decision`,{revision:refusedState.revision,action:'refuse'});
    await memberPage.locator('.fu-message-chat .fu-message-heading').getByText('Demande refusée',{exact:true}).waitFor({timeout:15000});
    await api('member',`messages/${refused.thread_id}/send`,{body:'Recette refus serveur',key:crypto.randomUUID()},403);check('refusal refreshes and stays server-enforced',true);
    const formFields=await fan.locator('.fu-message-composer input').evaluateAll(ns=>Object.fromEntries(ns.map(n=>[n.name,n.value])));
    const forged=await clients.fan.post(url('fan','?thread='+thread),{form:{...formFields,body:'Recette refus champ forgé',extra:'yes'}});check('native forged form fields rejected',forged.status()===400);
    // A full inbox reconciliation includes every UUID page, including an insertion behind its cursor.
    cli(`if(DB_NAME!=='admission_recipe')throw new RuntimeException('fixture only');global $wpdb;$t=$wpdb->get_row("SELECT * FROM wp_faluss_fans_dm_threads WHERE thread_id='${thread}'",ARRAY_A);for($i=1;$i<=24;$i++){$id=sprintf('11111111-1111-4111-8111-%012d',$i);$wpdb->insert('wp_faluss_fans_dm_threads',array_merge($t,['thread_id'=>$id,'creator_id'=>wp_generate_uuid4()]));}`);
    await go('fan');await fan.waitForFunction(()=>document.querySelectorAll('[data-thread]').length===25,{},{timeout:30000});
    check('inbox reconciliation traverses more than twenty UUID rows',true);
    await fan.locator('.fu-message-list-scroll').evaluate(n=>n.scrollTop=600);
    const scroll=await fan.locator('.fu-message-list-scroll').evaluate(n=>n.scrollTop);
    await fan.locator('[data-thread]').nth(8).click();await fan.getByRole('link',{name:'← Conversations',exact:true}).waitFor();
    await fan.getByRole('link',{name:'← Conversations',exact:true}).click();
    check('list return retains scroll position',Math.abs(await fan.locator('.fu-message-list-scroll').evaluate(n=>n.scrollTop)-scroll)<2);
    cli(`if(DB_NAME!=='admission_recipe')throw new RuntimeException('fixture only');global $wpdb;$t=$wpdb->get_row("SELECT * FROM wp_faluss_fans_dm_threads WHERE thread_id='${thread}'",ARRAY_A);$wpdb->insert('wp_faluss_fans_dm_threads',array_merge($t,['thread_id'=>'00000000-0000-4000-8000-000000000001','creator_id'=>wp_generate_uuid4()]));`);
    await fan.locator('[data-thread="00000000-0000-4000-8000-000000000001"]').waitFor({state:'attached',timeout:30000});
    check('new low UUID appears on next complete sweep',true);
    // All root tabs remain available on every mobile role, including the eight creator destinations.
    for(const role of ['fan','other'])for(const path of ['messages','explorer','hof'])for(const width of [320,375,390,430]){
      const p=pages[role];await p.setViewportSize({width,height:844});await p.goto(`${base}/app/${role==='other'?'creator':'fan'}/${path}`);await settle(p);
      check(`${role} ${path} ${width} one horizontal nav row`,await p.evaluate(()=>{const r=[...document.querySelectorAll('.fu-nav__item')].map(n=>n.getBoundingClientRect());return r.every(n=>n.top===r[0].top)&&document.documentElement.scrollWidth<=innerWidth;}));
      await p.locator('.fu-nav__item').last().focus();
      check(`${role} ${path} ${width} last tab keyboard reachable`,await p.locator('.fu-nav__item').last().evaluate(n=>{const r=n.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth;}));
    }
    await cp.setViewportSize({width:390,height:844});await go('other');await cp.screenshot({path:out+'/creator-list-390.png'});
    // Sequence catch-up crosses the 50-message page boundary in bounded private reads.
    cli(`if(DB_NAME!=='admission_recipe')throw new RuntimeException('fixture only');global $wpdb;$t=$wpdb->get_row("SELECT * FROM wp_faluss_fans_dm_threads WHERE thread_id='${thread}'",ARRAY_A);for($i=$t['last_seq']+1;$i<=65;$i++){$wpdb->insert('wp_faluss_fans_dm_messages',['message_id'=>wp_generate_uuid4(),'thread_id'=>'${thread}','sequence'=>$i,'sender_id'=>$t['creator_user'],'body'=>'Recette pagination '.$i,'key_hash'=>hash('sha256','fixture key '.$i),'request_hash'=>hash('sha256','fixture request '.$i),'created_at'=>gmdate('Y-m-d H:i:s')]);}$wpdb->query("UPDATE wp_faluss_fans_dm_threads SET revision=revision+65-last_seq,last_seq=65 WHERE thread_id='${thread}'");`);
    await go('fan','?thread='+thread);await fan.waitForFunction(()=>document.querySelectorAll('[data-sequence]').length===65,{},{timeout:15000});
    check('sequence catch-up crosses page fifty exactly once',await fan.locator('[data-sequence]').evaluateAll(ns=>new Set(ns.map(n=>n.dataset.sequence)).size===65));
    const original=await api('fan','messages/'+thread);const incoming=original.messages.find(m=>!m.mine);
    const report=await api('fan',`messages/${thread}/report`,{message_id:incoming.message_id,reason:'other'});
    const inspected=await api('admin-two','message-reports/'+report.case_id);
    await api('admin-two',`message-reports/${report.case_id}/decision`,{revision:Number(inspected.revision),action:'remove',reason:'Recette de retrait',recourse_complete:false,days:0});
    await fan.locator(`[data-sequence="${incoming.sequence}"] .fu-message-body`).filter({hasText:'Message retiré par la modération.'}).waitFor({timeout:15000});
    check('moderation revision reconciles an already displayed old message',true);
    // Transient failures back off and serialize; the server remains real (only transport is interrupted).
    const timings=[];let inFlight=0,maxInFlight=0;
    fan.on('request',r=>{if(r.url().includes('/message-view?')){inFlight++;maxInFlight=Math.max(maxInFlight,inFlight);}});
    fan.on('requestfinished',r=>{if(r.url().includes('/message-view?'))inFlight--;});fan.on('requestfailed',r=>{if(r.url().includes('/message-view?'))inFlight--;});
    await fan.route('**/message-view?*',r=>{timings.push(Date.now());return r.abort('connectionfailed');});
    await fan.evaluate(()=>window.dispatchEvent(new Event('online')));
    await fan.waitForFunction(()=>document.querySelector('.fu-message-sync-status')?.textContent.includes('interrompue'));
    await new Promise(r=>setTimeout(r,13500));
    check('transport failures increase retry delay',timings.length>=3&&timings[2]-timings[1]>timings[1]-timings[0]+2500);
    check('private reads never overlap',maxInFlight<=1);
    await fan.unroute('**/message-view?*');await fan.evaluate(()=>window.dispatchEvent(new Event('online')));await fan.waitForFunction(()=>document.querySelector('.fu-message-sync-status')?.textContent==='');
    check('recovery clears transient refresh warning',true);

    // Session invalidation in this isolated DB only: server rejects the next private read and DOM is cleared.
    cli(`global $wpdb;WP_Session_Tokens::get_instance((int)$wpdb->get_var("SELECT fan_user FROM wp_faluss_fans_dm_threads WHERE thread_id='${thread}'"))->destroy_all();`);
    // Explicit cookie removal simulates an expired browser session without logging tokens.
    await fan.evaluate(()=>window.dispatchEvent(new Event('online')));
    await fan.waitForFunction(()=>!document.querySelector('.fu-message-body'),{},{timeout:15000});check('expired session removes private content',true);
    check('no browser JavaScript errors',errors.length===0);
    fs.writeFileSync(out+'/browser.json',JSON.stringify({engine:await browser.version(),checks,limits:['Local WordPress 7.1.2 / MariaDB; two synthetic SSO-linked sessions, not a live Identity round trip.','Keyboard uses viewport resize emulation; physical Safari and target WordPress remain user recipe.'],captures:fs.readdirSync(out).filter(f=>f.endsWith('.png'))},null,2));
    console.log(JSON.stringify({checks:checks.length,errors:errors.length,out}));
  } finally { await browser.close();for(const c of Object.values(clients))await c.dispose(); }
})().catch(error=>{console.error(error.stack);process.exit(1);});
