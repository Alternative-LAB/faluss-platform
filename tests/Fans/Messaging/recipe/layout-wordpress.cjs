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
  const submit = async (page, name) => { await local(page); await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.getByRole('button', { name, exact: true }).click()]); await settle(page); await page.getByRole('status').filter({ hasText: 'Action confirmée' }).waitFor(); };
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
    for(const role of ['admin','unlinked']){const denied=await contexts[role].request.get(url('fan'));check(role+' private page denied',denied.status()===403);}
    const guest=await request.newContext();const denied=await guest.get(url('fan'));check('guest private page requires SSO',denied.status()===403);await guest.dispose();
    await capture('fan', 'empty'); await capture('other', 'empty');
    await capture('fan','request','?creator='+creator);
    const fan = await go('fan', '?creator=' + creator);
    await fan.getByLabel('Votre demande textuelle').fill('Bonjour ! J’aime votre travail sur les formes. Comment choisissez-vous vos couleurs ?');
    await submit(fan, 'Envoyer ma demande');
    const thread = (await api('fan', 'messages')).items[0].thread_id;
    await api('fan', `messages/${thread}/send`, { body: 'Pas avant acceptation', key: crypto.randomUUID() }, 403);
    check('pending cannot send bilaterally', true);
    await capture('fan', 'pending', '?thread=' + thread); await capture('other', 'pending', '?thread=' + thread);
    await api('member', 'messages/' + thread, null, 404);
    check('third party thread denied', true);
    const creatorPage = await go('other', '?thread=' + thread); await submit(creatorPage, 'Accepter la demande');
    await creatorPage.getByLabel('Votre message', { exact: true }).fill('Bonjour, merci ! Je pars souvent d’une couleur observée pendant une promenade.');
    await submit(creatorPage, 'Envoyer le message');
    await go('fan', '?thread=' + thread); await fan.getByLabel('Votre message', { exact: true }).fill('Je comprends mieux. Et ensuite, vous travaillez d’abord sur papier ?'); await submit(fan, 'Envoyer le message');
    await go('other', '?thread=' + thread); await creatorPage.getByLabel('Votre message', { exact: true }).fill('Oui, quelques croquis suffisent pour commencer. Je garde ensuite les associations qui me plaisent.'); await submit(creatorPage, 'Envoyer le message');
    await capture('fan', 'accepted', '?thread=' + thread); await capture('other', 'accepted', '?thread=' + thread);
    check('approved creator portrait actually loaded', await fan.locator('.fu-message-chat .fu-message-avatar img').evaluate(img => img.complete && img.naturalWidth > 0));
    check('Fan identity never fabricated', await creatorPage.locator('.fu-message-heading h2').last().innerText() === 'Membre Fans' && await creatorPage.locator('.fu-message-chat .fu-message-avatar img').count() === 0);
    for (const role of ['fan', 'other']) {
      await go(role); const p = pages[role];
      await p.setViewportSize({ width: 390, height: 844 });
      check(role + ' mobile list first', await p.locator('.fu-message-list').isVisible() && !await p.locator('.fu-message-chat').isVisible());
      await p.screenshot({ path: `${out}/${role === 'other' ? 'creator' : 'fan'}-list-390.png` });
      const link = p.locator('.fu-message-preview').first(); await link.focus(); await Promise.all([p.waitForNavigation({waitUntil:'load'}),p.keyboard.press('Enter')]); await settle(p);
      check(role + ' keyboard opens conversation', await p.locator('.fu-message-chat').isVisible());
      await Promise.all([p.waitForNavigation({waitUntil:'load'}),p.getByRole('link', { name: '← Conversations', exact: true }).click()]); await settle(p);
      check(role + ' clear list return', await p.locator('.fu-message-list').isVisible());
      await Promise.all([p.waitForNavigation({waitUntil:'load'}),p.locator('.fu-message-preview').first().tap()]);await settle(p);
      check(role+' touch opens conversation',await p.locator('.fu-message-chat').isVisible());
      await Promise.all([p.waitForNavigation({waitUntil:'load'}),p.getByRole('link',{name:'← Conversations',exact:true}).tap()]);await settle(p);
      await p.goBack(); await p.waitForLoadState('load'); await settle(p); check(role + ' history restores conversation', await p.locator('.fu-message-chat').isVisible());
      await p.getByLabel('Options de la conversation', { exact: true }).click();
      await p.locator('.fu-message-options a').first().focus(); await p.keyboard.press('Escape');
      check(role + ' Escape returns focus', await p.getByLabel('Options de la conversation', { exact: true }).evaluate(el => document.activeElement === el && !el.parentElement.open));
      check(role + ' private no-store', (await contexts[role].request.get(url(role, '?thread=' + thread))).headers()['cache-control'].includes('no-store'));
    }
    for (let i = 0; i < 12; i++) await api(i % 2 ? 'other' : 'fan', `messages/${thread}/send`, { body: `Échange de recette ${i + 1}. Ce texte vérifie le défilement indépendant de l’historique.`, key: crypto.randomUUID() });
    await go('fan', '?thread=' + thread); await fan.setViewportSize({ width: 390, height: 844 });
    const scroll = fan.getByLabel('Historique des messages', { exact: true });
    const composerBefore = await fan.locator('.fu-message-composer').boundingBox();
    await scroll.focus(); await fan.keyboard.press('End'); await fan.waitForFunction(() => document.querySelector('[aria-label="Historique des messages"]').scrollTop > 0);
    check('keyboard scrolls bounded history', await scroll.evaluate(el => el.scrollTop > 0));
    const composerAfter = await fan.locator('.fu-message-composer').boundingBox();
    await scroll.evaluate(el=>el.scrollTop=0);const box=await scroll.boundingBox();
    const cdp=await contexts.fan.newCDPSession(fan);await cdp.send('Input.synthesizeScrollGesture',{x:Math.round(box.x+box.width/2),y:Math.round(box.y+box.height/2),yDistance:-150,gestureSourceType:'touch'});
    await fan.waitForFunction(()=>document.querySelector('[aria-label="Historique des messages"]').scrollTop>0);check('touch scrolls message history',true);await cdp.detach();
    check('composer stays in place while history scrolls', Math.abs(composerAfter.y - composerBefore.y) < 2);
    await fan.getByLabel('Votre message', { exact: true }).fill('Brouillon conservé pendant le redimensionnement.');
    await fan.setViewportSize({ width: 390, height: 520 });
    check('reduced viewport retains composer text', await fan.getByLabel('Votre message', { exact: true }).inputValue() === 'Brouillon conservé pendant le redimensionnement.');
    await fan.getByRole('button', { name: 'Envoyer le message', exact: true }).scrollIntoViewIfNeeded();
    check('send accessible in reduced viewport', await fan.getByRole('button', { name: 'Envoyer le message', exact: true }).evaluate(el => {const r=el.getBoundingClientRect();return el.contains(document.elementFromPoint(r.x+r.width/2,r.y+r.height/2));}));
    await fan.setViewportSize({ width: 390, height: 844 }); await go('fan', '?thread=' + thread);
    await fan.locator('.fu-message-bubble:not(.is-mine)').first().getByText('Signaler ce message', { exact: true }).click();
    await fan.locator('.fu-message-bubble:not(.is-mine)').first().getByLabel('Motif du signalement').selectOption('other');
    await submit(fan, 'Confirmer le signalement');
    const report = (await api('fan', 'message-reports/mine')).items[0].case_id;
    await api('admin-two', `message-reports/${report}/decision`, { revision: 1, action: 'no_action', reason: 'Examen synthétique de recette.', recourse_complete: false, days: 0 });
    await go('fan', '?section=reports'); await fan.getByLabel('Motiver un recours').fill('Recours synthétique de recette.'); await submit(fan, 'Transmettre mon recours'); check('report and appeal native forms preserved', true);
    await go('fan', '?thread=' + thread); await fan.getByLabel('Options de la conversation', { exact: true }).click(); await submit(fan, 'Bloquer cet échange');
    await api('other', `messages/${thread}/send`, { body: 'Bloqué', key: crypto.randomUUID() }, 403);
    await go('fan', '?section=blocks'); await submit(fan, 'Lever mon blocage'); check('block/unblock native forms preserved', true);
    const res = await clients.fan.post(url('fan', '?thread=' + thread), { form: { message_action: 'send', fans_messages_nonce: 'invalid', thread_id: thread, body: 'Forged', key: crypto.randomUUID() } }); check('native bad nonce rejected', res.status() === 403);
    await go('fan', '?thread=' + thread); const inputs = await fan.locator('.fu-message-composer input').evaluateAll(items => Object.fromEntries(items.map(el => [el.name, el.value])));
    const forged = await clients.fan.post(url('fan', '?thread=' + thread), { form: { ...inputs, body: 'Forged', privileged: 'yes' } }); check('native forged extra field rejected', forged.status() === 400);
    const nojs = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 390, height: 844 } });
    await nojs.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
    await nojs.addCookies(Object.entries(fixture.sessions.fan.cookies).map(([name, value]) => ({ name, value, url: base })));
    const p = await nojs.newPage(); await p.goto(url('fan', '?thread=' + thread)); await local(p); await p.getByLabel('Votre message', { exact: true }).fill('Message envoyé sans JavaScript.'); await submit(p, 'Envoyer le message'); check('native no-JS send', (await p.locator('.fu-message-log').innerText()).includes('Message envoyé sans JavaScript.')); await nojs.close();
    for (const width of [320, 700, 701, 1024]) {
      await fan.setViewportSize({width,height:844}); await go('fan','?thread='+thread);
      check('no overflow at '+width,await fan.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
    }
    for (let i=0;i<40;i++) await api(i%2?'other':'fan',`messages/${thread}/send`,{body:`Pagination de recette ${i+1}.`,key:crypto.randomUUID()});
    await go('fan','?thread='+thread);
    check('bounded page of fifty messages',await fan.locator('.fu-message-bubble').count()===50);
    await Promise.all([fan.waitForNavigation({waitUntil:'load'}),fan.getByRole('link',{name:'Messages suivants →',exact:true}).click()]); await settle(fan);
    check('next page presents remaining messages',await fan.locator('.fu-message-bubble').count()===7);
    await Promise.all([fan.waitForNavigation({waitUntil:'load'}),fan.getByRole('link',{name:'← Début de l’échange',exact:true}).click()]); await settle(fan);
    check('return to first message page',await fan.locator('.fu-message-bubble').count()===50);
    // A revision under review cannot replace the approved correspondent identity.
    const own=await api('other','creators/me/editorial');
    const pending=await api('other','creators/me/editorial',{revision:Number(own.revision),public_name:'PRIVATE_UNAPPROVED_NAME',bio:'Private draft',portrait_id:'',portrait_revision:0});
    await go('fan','?thread='+thread);
    check('pending editorial hidden, last approval retained',!(await fan.locator('.fu-messages').innerText()).includes('PRIVATE_UNAPPROVED_NAME')&&await fan.locator('.fu-message-chat .fu-message-avatar img').count()===1);
    await api('admin',`editorial/${creator}/moderate`,{revision:Number(pending.revision),decision:'reject',reason:'needs_revision'});
    await go('fan','?thread='+thread);check('rejection preserves last approved portrait',await fan.locator('.fu-message-chat .fu-message-avatar img').count()===1);
    // Existing send closure preserves reading and recourse; the mutation affects only disposable wp-config.
    cli(`if(DB_NAME!=="admission_recipe")throw new RuntimeException("fixture only");$p=ABSPATH."wp-config.php";file_put_contents($p,str_replace('define("FALUSS_PLATFORM_FANS_MESSAGING",true);','define("FALUSS_PLATFORM_FANS_MESSAGING",false);',file_get_contents($p)));`);
    await go('fan','?thread='+thread); check('closed sending retains reading',await fan.locator('.fu-message-bubble').count()===50&&await fan.locator('.fu-message-composer').count()===0);
    await go('fan','?section=reports'); check('closed sending retains recourse view',await fan.locator('.fu-message-case').count()===1);
    cli(`if(DB_NAME!=="admission_recipe")throw new RuntimeException("fixture only");$p=ABSPATH."wp-config.php";file_put_contents($p,str_replace('define("FALUSS_PLATFORM_FANS_MESSAGING",false);','define("FALUSS_PLATFORM_FANS_MESSAGING",true);',file_get_contents($p)));`);
    await go('fan', '?thread=' + thread);
    check('no visible UUID or fake state', !/[0-9a-f]{8}-[0-9a-f]{4}-|en ligne|non lu|remercier/i.test(await fan.locator('.fu-messages').innerText()));
    await fan.evaluate(() => dispatchEvent(new PageTransitionEvent('pagehide'))); check('pagehide removes private content', await fan.locator('.fu-message-body').count() === 0);
    check('no browser errors', errors.length === 0);
    fs.writeFileSync(`${out}/browser.json`, JSON.stringify({ checks, count: checks.length, browser: browser.version(), limits: 'Disposable WordPress/MariaDB with synthetic local SSO links. No Identity network or target site. Viewport reduction is not a physical mobile keyboard test. Geometric portrait is a labelled test asset, approved through real services.', screenshots: fs.readdirSync(out).filter(x => x.endsWith('.png')) }, null, 2));
    console.log(`PASS ${checks.length} checks; desktop/mobile Fan and Creator captures`);
  } finally { for (const c of Object.values(clients)) await c.dispose(); await browser.close(); }
})().catch(error => { console.error(error.stack); process.exitCode = 1; });
