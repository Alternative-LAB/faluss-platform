// Real WordPress/MariaDB endpoints and UI; generated localhost identities only.
const { chromium } = require('playwright'), assert = require('node:assert/strict');
const fs = require('node:fs'), { execFileSync } = require('node:child_process');
(async () => {
  const { BASE: base, ROOT: root, OUT: out } = process.env;
  assert.equal(new URL(base).hostname, '127.0.0.1'); assert.match(root, /^\/var\/tmp\/fans-admission-wp-[a-z0-9_]+$/);
  const s = JSON.parse(execFileSync('wsl.exe', ['-u', 'root', '--', 'cat', root + '/session.json']));
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const checks = [], clients = {};
  try {
    for (const role of ['guest', 'fan', 'member', 'admin']) {
      const c = clients[role] = await browser.newContext();
      await c.route('**/*', r => new URL(r.request().url()).origin === base ? r.continue() : r.abort());
      if (role !== 'guest') await c.addCookies(Object.entries(s.sessions[role].cookies).map(([name, value]) => ({ name, value, url: base })));
    }
    const api = (role, path, data) => clients[role].request[data ? 'post' : 'get'](base + '/wp-json/faluss-fans/v1/' + path, { headers: role === 'guest' ? {} : { 'X-WP-Nonce': s.sessions[role].nonce }, ...(data ? { data } : {}) });
    const okay = async response => { assert.equal(response.status(), 200); return response.json(); };
    const id = s.profiles.member;
    const approved = (await okay(await api('guest', 'creators/' + id))).editorial;
    const own = await okay(await api('member', 'creators/me/editorial'));
    const proposal = { revision: Number(own.revision), public_name: 'PROPOSITION NON PUBLIQUE', bio: 'Texte de contrôle non approuvé.', portrait_id: '', portrait_revision: 0 };
    for (const role of ['guest', 'fan']) assert.ok((await api(role, 'creators/me/editorial', proposal)).status() >= 400);
    const forged = await clients.member.request.post(base + '/wp-json/faluss-fans/v1/creators/me/editorial', { headers: { 'X-WP-Nonce': 'forged' }, data: proposal }); assert.equal(forged.status(), 403);
    const pending = await okay(await api('member', 'creators/me/editorial', proposal));
    assert.deepEqual((await okay(await api('guest', 'creators/' + id))).editorial, approved);
    const page = await clients.guest.newPage(); await page.goto(base + '/app/creators/' + id); await page.locator('.fu-public-creator__copy h2').waitFor();
    assert.equal(await page.locator('.fu-public-creator__copy h2').innerText(), approved.public_name);
    assert.ok(!(await page.locator('.fu-main').innerText()).includes(proposal.public_name));
    await page.goto(base + '/app/fan/explorer'); await page.locator('.fu-discovery-hero__slide:visible').waitFor();
    assert.equal(await page.locator('.fu-discovery-hero__slide:visible h2').innerText(), approved.public_name);
    const refused = await okay(await api('admin', `editorial/${id}/moderate`, { revision: Number(pending.revision), decision: 'reject', reason: 'needs_revision' }));
    assert.deepEqual((await okay(await api('guest', 'creators/' + id))).editorial, approved);
    checks.push('Guest/Fan/forged nonce cannot submit; pending and rejected edits keep exactly the last approved public identity in API/Explorer/profile');
    for (const role of ['member', 'admin']) {
      const p = await clients[role].newPage();
      assert.equal((await p.goto(base + '/app/fan/explorer')).status(), 200); await p.locator('.fu-discovery-hero__slide:visible').waitFor();
      assert.equal((await p.goto(base + '/app/creators/' + id)).status(), 200); await p.locator('.fu-public-creator__copy h2').waitFor();
      assert.equal(await p.locator('.fu-nav__item').count(), role === 'member' ? 8 : 2); await p.close();
    }
    for (const role of ['guest', 'fan']) assert.equal((await clients[role].request.get(base + '/app/creator/mon-profil')).status(), role === 'guest' ? 403 : 404);
    const detail = await okay(await api('admin', 'creators/' + id + '/private'));
    const revision = Number(detail.status_revision ?? detail.revision);
    assert.ok(Number.isInteger(revision));
    const suspended = await okay(await api('admin', 'creators/' + id + '/status', { status: 'suspended', revision }));
    for (const role of ['guest', 'fan', 'admin']) {
      assert.equal((await clients[role].request.get(base + '/app/creators/' + id)).status(), 404);
      assert.equal((await api(role, 'creators/' + id)).status(), 404);
    }
    assert.equal((await api('guest', `creators/${id}/portrait/${approved.revision}`)).status(), 404);
    checks.push('Creator/admin retain public reading; unchanged private route denials; suspended profile returns page HTTP 404 and rejects portrait');
    const current = await okay(await api('admin', 'creators/' + id + '/private'));
    await okay(await api('admin', 'creators/' + id + '/status', { status: 'active', revision: Number(current.status_revision ?? current.revision) }));
    await okay(await api('member', `editorial/${id}/withdraw`, { revision: Number(refused.revision) }));
    assert.equal((await okay(await api('guest', 'creators/' + id))).editorial, null);
    assert.equal((await api('guest', `creators/${id}/portrait/${approved.revision}`)).status(), 404);
    await page.goto(base + '/app/fan/explorer'); await page.locator('.fu-empty').waitFor(); assert.equal(await page.locator('.fu-discovery-hero').count(), 0);
    // Presentation withdrawal does not delete the active profile; match the existing API contract.
    assert.equal((await page.goto(base + '/app/creators/' + id)).status(), 200); await page.locator('.fu-public-creator__copy h2').waitFor();
    assert.equal(await page.locator('.fu-public-creator__copy h2').innerText(), 'Créateur');
    assert.ok(!(await page.locator('.fu-main').innerText()).includes(approved.public_name));
    checks.push('Withdrawn presentation removes hero/name/bio/portrait immediately; active profile remains HTTP 200 as before, without inventing an identity');
    fs.writeFileSync(out + '/permissions.json', JSON.stringify({ checks }, null, 2));
    console.log('PASS real WordPress permissions: ' + checks.length + ' grouped checks');
  } finally { await browser.close(); }
})().catch(error => { console.error(error.stack); process.exitCode = 1; });
