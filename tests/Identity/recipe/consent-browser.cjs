// Real disposable WordPress/MariaDB, actual authorization/admin/token handlers. Never follows a client redirect.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const crypto = require('node:crypto');
const { execFileSync } = require('node:child_process');
(async () => {
  const base = process.env.BASE, root = process.env.RECIPE_ROOT, out = process.env.OUT;
  assert.equal(new URL(base).hostname, '127.0.0.1');
  assert.match(root, /^\/var\/tmp\/identity-consent-wp-[a-z0-9_]+$/);
  const seed = JSON.parse(fs.readFileSync(process.env.SESSION));
  let secret = seed.secret;
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const contexts = {}, results = [];
  const callback = 'https://fans.example.test/faluss-fans/sso/callback';
  const verifier = 'v'.repeat(64), challenge = crypto.createHash('sha256').update(verifier).digest('base64url');
  const nonce = html => html.match(/name="faluss_identity_authorization_nonce" value="([^"]+)"/)[1];
  const sql = query => execFileSync('wsl.exe', ['-u','root','--','mariadb','--no-defaults',`--socket=${root}/sql.sock`,'-uroot','--batch','--skip-column-names','button_recipe','-e',query], { encoding:'utf8' }).trim();
  const startUrl = (scope = 'identity.basic') => base + '/oauth/authorize?' + new URLSearchParams({ client_id:'consent-fixture', redirect_uri:callback, response_type:'code', scope, code_challenge:challenge, code_challenge_method:'S256', state:crypto.randomBytes(24).toString('hex') });
  const get = (ctx, url) => ctx.request.get(url, { maxRedirects:0 });
  const post = (ctx, url, form) => {
    const fields = new URLSearchParams();
    for (const [key,value] of Object.entries(form)) for (const item of Array.isArray(value) ? value : [value]) fields.append(key,item);
    return ctx.request.post(url, { data:fields.toString(), headers:{'content-type':'application/x-www-form-urlencoded'}, maxRedirects:0 });
  };
  const code = r => { assert.equal(r.status(),302); assert.equal(new URL(r.headers().location).origin,'https://fans.example.test'); return new URL(r.headers().location).searchParams.get('code'); };
  const approve = async (ctx, response) => { assert.equal(response.status(),200); return post(ctx, base+'/oauth/authorize', { faluss_identity_authorization_nonce:nonce(await response.text()), decision:'approve' }); };
  const exchange = (ctx, value, extra={}) => post(ctx, base+'/oauth/token', { grant_type:'authorization_code', client_id:'consent-fixture', client_secret:secret, redirect_uri:callback, code:value, code_verifier:verifier, ...extra });
  try {
    for (const role of ['guest', ...Object.keys(seed.sessions)]) {
      contexts[role] = await browser.newContext({ ignoreHTTPSErrors:true });
      await contexts[role].route('**/*', r => new URL(r.request().url()).origin === base ? r.continue() : r.abort());
      if (role !== 'guest') await contexts[role].addCookies(Object.entries(seed.sessions[role]).map(([name,value])=>({ name,value,domain:'127.0.0.1',path:'/' })));
    }
    const member=contexts.member, admin=contexts.admin, other=contexts.other;
    // Simulate an updater replacing an already active plugin: no activation or admin visit.
    sql("DROP TABLE wp_faluss_identity_consents, wp_faluss_identity_consents_clients; DELETE FROM wp_options WHERE option_name='faluss_identity_consent_schema'");
    let upgradeResponse = await get(member,startUrl());
    assert.equal(upgradeResponse.status(),200);
    assert.ok(code(await approve(member,upgradeResponse)));
    upgradeResponse = await get(member,startUrl());
    if (process.env.EXPECT_UPGRADE_BUG === '1') {
      assert.equal(upgradeResponse.status(),200);
      assert.ok(code(await approve(member,upgradeResponse)));
      assert.equal(sql("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='wp_faluss_identity_consents'"),'0');
      results.push('BASELINE: two successful authorizations both prompt; consent storage absent without admin visit');
      console.log(JSON.stringify(results));
      return;
    }
    assert.ok(code(upgradeResponse));
    assert.equal(sql('SELECT COUNT(*) FROM wp_faluss_identity_consents'),'1');
    assert.equal(sql("SELECT first_party FROM wp_faluss_identity_clients WHERE client_id='consent-fixture'"),'0');
    results.push('Updater without admin visit: member approval persisted, next authorization reuses it, Fans remains third party');
    // A damaged already installed store must fail closed, not issue another unremembered code.
    sql('DELETE FROM wp_faluss_identity_consents; ALTER TABLE wp_faluss_identity_consents ENGINE=MyISAM');
    const codesBefore=sql('SELECT COUNT(*) FROM wp_faluss_identity_auth_codes');
    upgradeResponse=await approve(member,await get(member,startUrl()));
    assert.equal(upgradeResponse.status(),503);
    assert.equal(sql('SELECT COUNT(*) FROM wp_faluss_identity_auth_codes'),codesBefore);
    assert.equal(sql('SELECT COUNT(*) FROM wp_faluss_identity_consents'),'0');
    sql('ALTER TABLE wp_faluss_identity_consents ENGINE=InnoDB');
    results.push('Corrupt installed consent storage: approval HTTP 503, no grant and no code issued');
    const page=await member.newPage();
    for (const width of [1440,390]) {
      await page.setViewportSize({ width,height:900 });
      await page.goto(startUrl());
      assert.equal(await page.getByRole('heading',{name:'Autoriser cette application ?'}).count(),1);
      assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
      await page.screenshot({ path:`${out}/consent-${width}.png`,fullPage:true });
    }
    let response=await get(member,startUrl());
    const issued=code(await approve(member,response));
    assert.equal(sql('SELECT COUNT(*) FROM wp_faluss_identity_consents'),'1');
    assert.equal(sql("SELECT first_party FROM wp_faluss_identity_clients WHERE client_id='consent-fixture'"),'0');
    assert.equal((await exchange(member,issued,{code_verifier:'x'.repeat(64)})).status(),400);
    let token=await exchange(member,issued);assert.equal(token.status(),200);
    assert.ok(!Object.hasOwn(await token.json(),'email'));
    assert.equal((await exchange(member,issued)).status(),400);
    results.push('First explicit grant; PKCE negative; usable code; one-use replay rejected; Fans first_party=0');
    assert.ok(code(await get(member,startUrl())));
    response=await get(contexts.guest,startUrl());assert.equal(response.status(),302);assert.ok(response.headers().location.includes('/login'));
    assert.equal((await get(contexts.suspended,startUrl())).status(),403);
    response=await get(other,startUrl());assert.equal(response.status(),200);
    const denied=await post(other,base+'/oauth/authorize',{faluss_identity_authorization_nonce:nonce(await response.text()),decision:'deny'});
    assert.ok(denied.headers().location.includes('access_denied'));
    assert.equal(sql('SELECT COUNT(*) FROM wp_faluss_identity_consents'),'1');
    results.push('Remembered member skips prompt; guest still passwordless login; suspended refused; other member needs own consent; denial stores nothing');
    response=await get(member,startUrl('identity.basic identity.email'));assert.equal(response.status(),200);
    assert.equal((await post(member,base+'/oauth/authorize',{faluss_identity_authorization_nonce:'invalid',decision:'approve'})).status(),400);
    response=await get(member,startUrl('identity.basic identity.email'));
    assert.ok(code(await approve(member,response)));
    assert.ok(code(await get(member,startUrl('identity.basic identity.email'))));
    assert.equal((await get(member,startUrl())).status(),200);
    results.push('Scope expansion and reduction prompt again; exact granted scopes only; invalid nonce refused');
    const adminPage=await admin.newPage();
    const listing=base+'/wp-admin/options-general.php?page=faluss-identity-sso-clients';
    async function clientForm(action) {
      await adminPage.goto(listing);
      return adminPage.locator(`form:has(input[name="client_id"][value="consent-fixture"]):has(input[name="action"][value="${action}"])`);
    }
    async function update(name,status='active') {
      const action='faluss_identity_save_sso_client',form=await clientForm(action);
      const r=await post(admin,base+'/wp-admin/admin-post.php',{ action,_wpnonce:await form.locator('[name="_wpnonce"]').inputValue(),client_id:'consent-fixture',client_name:name,status,redirect_uris:callback,'scopes[]':['identity.basic','identity.email'],first_party:'1' });
      assert.equal(r.status(),302);assert.ok(r.headers().location.includes('saved'));
      assert.equal(sql("SELECT first_party FROM wp_faluss_identity_clients WHERE client_id='consent-fixture'"),'0');
    }
    response=await get(member,startUrl());const stale=nonce(await response.text());
    await update('Fans · recette modifiée');
    assert.equal((await post(member,base+'/oauth/authorize',{faluss_identity_authorization_nonce:stale,decision:'approve'})).status(),400);
    assert.ok(code(await approve(member,await get(member,startUrl()))));
    const before=sql("SELECT revision FROM wp_faluss_identity_consents_clients WHERE client_id='consent-fixture'");
    await update('Fans · recette modifiée');
    assert.notEqual(sql("SELECT revision FROM wp_faluss_identity_consents_clients WHERE client_id='consent-fixture'"),before);
    assert.equal((await get(member,startUrl())).status(),200);
    await update('Fans · recette modifiée','inactive');
    assert.equal((await get(member,startUrl())).status(),400);
    await update('Fans · recette locale');
    assert.ok(code(await approve(member,await get(member,startUrl()))));
    results.push('Real admin save changes revision even with identical config; stale displayed consent refused; inactive client refused; reactivation requires approval');
    const rotate='faluss_identity_rotate_sso_client_secret',form=await clientForm(rotate);
    response=await post(admin,base+'/wp-admin/admin-post.php',{ action:rotate,_wpnonce:await form.locator('[name="_wpnonce"]').inputValue(),client_id:'consent-fixture' });
    assert.equal(response.status(),200);
    const oldSecret=secret;secret=(await response.text()).match(/data-faluss-client-secret>([A-Za-z0-9_-]{43})/)[1];
    const rotated=code(await approve(member,await get(member,startUrl())));
    assert.equal((await exchange(member,rotated,{client_secret:oldSecret})).status(),401);
    assert.equal((await exchange(member,rotated)).status(),200);
    results.push('Real secret rotation re-prompts; old secret rejected; new secret usable');
    const pendingCode=code(await get(member,startUrl()));
    const management=base+'/?faluss_identity_apps=1';
    for (const width of [1440,390]) {
      await page.setViewportSize({width,height:900});await page.goto(management);
      assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
      await page.screenshot({path:`${out}/authorizations-${width}.png`,fullPage:true});
    }
    const revokeNonce=await page.locator('[name="_wpnonce"]').inputValue();
    assert.equal((await post(member,management,{client_id:'consent-fixture',_wpnonce:'invalid'})).status(),403);
    assert.equal((await post(other,management,{client_id:'consent-fixture',_wpnonce:revokeNonce})).status(),403);
    assert.equal(sql('SELECT COUNT(*) FROM wp_faluss_identity_consents'),'1');
    assert.equal((await post(member,management,{client_id:'consent-fixture',_wpnonce:revokeNonce})).status(),200);
    assert.equal(sql('SELECT COUNT(*) FROM wp_faluss_identity_consents'),'0');
    assert.equal((await exchange(member,pendingCode)).status(),400);
    response=await get(member,startUrl());assert.equal(response.status(),200);
    results.push('Owner-only nonce-bound revocation removes grant and outstanding codes; next visit prompts');
    const count=sql('SELECT COUNT(*) FROM wp_faluss_identity_auth_codes');
    execFileSync('wsl.exe',['-u','root','--','touch',root+'/fail-grant']);
    try { assert.equal((await approve(member,response)).status(),400); }
    finally { execFileSync('wsl.exe',['-u','root','--','rm','--',root+'/fail-grant']); }
    assert.equal(sql('SELECT COUNT(*) FROM wp_faluss_identity_consents'),'0');
    assert.equal(sql('SELECT COUNT(*) FROM wp_faluss_identity_auth_codes'),count);
    results.push('Injected persistence failure rolls back code issuance and grant');
    fs.writeFileSync(`${out}/results.json`,JSON.stringify({browser:await browser.version(),results},null,2));
    console.log(`PASS ${results.length} end-to-end scenario groups; no external redirects followed`);
  } finally { await browser.close(); }
})().catch(e=>{console.error(e);process.exitCode=1;});
