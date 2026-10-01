const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

(async () => {
  const base = process.env.BASE;
  assert.equal(new URL(base).hostname, '127.0.0.1');
  const seed = JSON.parse(fs.readFileSync(process.env.SESSION, 'utf8'));
  const out = process.env.OUT;
  fs.mkdirSync(out, { recursive: true });
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const results = [];
  try {
    for (const role of ['guest', ...Object.keys(seed.sessions)]) {
      const admin = role.startsWith('admin-');
      const context = await browser.newContext();
      if (role !== 'guest') {
        await context.addCookies(Object.entries(seed.sessions[role]).map(([name, value]) => ({ name, value, domain: '127.0.0.1', path: '/', httpOnly: true, secure: false, sameSite: 'Lax' })));
      }
      const page = await context.newPage();
      await context.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
      for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: 900 });
        for (const [name, route] of [['elementor', '/'], ['fans-hof', '/app/fan/hof']]) {
          const response = await page.goto(base + route);
          assert.equal(response.status(), 200, `${role} ${route}`);
          await page.evaluate(() => document.fonts.ready);
          if (name === 'elementor') { assert.ok(await page.locator('[data-elementor-id]').count() > 0, 'Actual Elementor page missing'); }
          assert.equal(await page.locator('#wpadminbar').count(), admin ? 1 : 0, `${role} toolbar`);
          if (admin) { assert.ok(await page.locator('#wp-admin-bar-wp-logo').count() > 0); }
          else {
            assert.equal(await page.locator('#wp-admin-bar-wp-logo,#wp-admin-bar-my-account').count(), 0);
            assert.ok(!(await page.content()).includes('fans_fixture_' + role), 'Technical account identifier leaked');
            assert.equal(await page.locator('body').evaluate(el => el.classList.contains('admin-bar')), false);
          }
          if (role.startsWith('member-')) { assert.equal(await page.locator('meta[name="access-linked"]').getAttribute('content'), 'yes'); }
          if (['guest', 'member-on', 'admin-off'].includes(role)) {
            await page.screenshot({ path: path.join(out, `${name}-${role}-${width}.png`), fullPage: true });
          }
          results.push({ role, width, page: name, status: response.status(), toolbar: admin });
        }
      }
      const nonce = await page.locator('meta[name="access-probe-nonce"]').getAttribute('content');
      for (const screen of ['/wp-admin/', '/wp-admin/profile.php', '/wp-admin/edit.php']) {
        const response = await context.request.get(base + screen, { maxRedirects: 0 });
        if (admin) { assert.equal(response.status(), 200, role + screen); }
        else if (role === 'guest') { assert.equal(response.status(), 302); assert.ok(response.headers().location.includes('/wp-login.php')); }
        else { assert.equal(response.status(), 302); assert.equal(response.headers().location, 'https://fans.example.test/'); assert.ok(response.headers()['cache-control'].includes('no-store')); }
      }
      for (const endpoint of ['admin-post.php', 'admin-ajax.php']) {
        const accepted = await context.request.post(base + '/wp-admin/' + endpoint, { form: { action: 'access_probe', nonce }, maxRedirects: 0 });
        assert.equal(accepted.status(), 200, `${role} ${endpoint}: ${accepted.headers().location || 'no redirect'}`);
        assert.equal((await accepted.json()).success, true);
        const denied = await context.request.post(base + '/wp-admin/' + endpoint, { form: { action: 'access_probe', nonce: 'invalid' }, maxRedirects: 0 });
        assert.equal(denied.status(), 403);
      }
      const rejectedSso = await context.request.post(base + '/wp-admin/admin-post.php', { form: { action: 'faluss_fans_sso_start', faluss_fans_sso_nonce: 'invalid' }, maxRedirects: 0 });
      assert.equal(rejectedSso.status(), 302);
      assert.ok(rejectedSso.headers().location.includes('faluss_fans_sso=invalid'), 'SSO handler was bypassed');
      const callback = await context.request.get(base + '/faluss-fans/sso/callback?state=invalid&code=invalid', { maxRedirects: 0 });
      assert.equal(callback.status(), 302);
      assert.ok(callback.headers().location.includes('faluss_fans_sso=invalid'));
      const rest = await context.request.get(base + '/?rest_route=/', { maxRedirects: 0 });
      assert.equal(rest.status(), 200);
      assert.ok((await rest.json()).namespaces.includes('wp/v2'));
      if (role === 'guest') {
        await page.goto(base + '/');
        const ssoNonce = await page.locator('[name="faluss_fans_sso_nonce"]').inputValue();
        const started = await context.request.post(base + '/wp-admin/admin-post.php', { form: { action: 'faluss_fans_sso_start', faluss_fans_sso_nonce: ssoNonce, faluss_fans_return_to: '/app/fan/espace' }, maxRedirects: 0 });
        assert.equal(started.status(), 302);
        assert.ok(started.headers().location.startsWith('https://faluss.me/oauth/authorize?'));
        // The external authorization redirect is inspected only; never followed.
      }
      results.push({ role, adminScreens: admin ? '200' : role === 'guest' ? 'WordPress login' : '302 public home no-store', postAndAjax: '200 valid nonce / 403 invalid nonce', sso: 'handler reached; invalid callback rejected', rest: 200 });
      await context.close();
    }
    fs.writeFileSync(path.join(out, 'results.json'), JSON.stringify({ browser: await browser.version(), results }, null, 2) + '\n');
    console.log(`PASS ${results.length} role/page/transport assertions; captures saved; no external SSO followed.`);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
