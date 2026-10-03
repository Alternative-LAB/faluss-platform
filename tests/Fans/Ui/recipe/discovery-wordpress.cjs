// Chromium against admission-wordpress.py only. No REST mocks, real disposable SQL/WordPress.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { execFileSync } = require('node:child_process');
const { createHash } = require('node:crypto');

(async () => {
  const { BASE: base, ROOT: root, OUT: out, STAGE: stage, REPO_WSL: repo } = process.env;
  assert.equal(new URL(base).hostname, '127.0.0.1');
  assert.match(root, /^\/var\/tmp\/fans-admission-wp-[a-z0-9_]+$/);
  assert.ok(['before', 'after'].includes(stage));
  const wsl = args => execFileSync('wsl.exe', ['-u', 'root', '--', ...args]);
  const fixture = JSON.parse(wsl(['cat', root + '/session.json']));
  const state = value => wsl(['php', '/var/tmp/faluss-v3-wp/wp-cli.phar', '--allow-root', '--path=' + root + '/wordpress', 'eval-file', repo + '/tests/Fans/Ui/recipe/discovery-state.php', root + '/session.json', value, '--use-include']);
  fs.mkdirSync(out + '/' + stage, { recursive: true });
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const checks = [], errors = [], sso = [], untouched = {};
  const contexts = {};
  const noOverflow = async page => assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'document overflow');
  const settle = async page => {
    await page.waitForFunction(() => !document.querySelector('[data-fans-status]')?.textContent.includes('Chargement'));
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(350);
  };
  const localLinks = page => page.locator('a[href]').evaluateAll((links, base) => links.forEach(link => {
    const url = new URL(link.href); if (url.origin === 'https://fans.example.test') link.href = base + url.pathname + url.search + url.hash;
  }), base);
  try {
    for (const role of ['guest', 'fan', 'member', 'admin']) {
      const context = contexts[role] = await browser.newContext();
      await context.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
      if (role !== 'guest') await context.addCookies(Object.entries(fixture.sessions[role].cookies).map(([name, value]) => ({ name, value, url: base })));
    }
    for (const scenario of ['one', 'many', 'no-media']) {
      state(scenario);
      for (const role of ['guest', 'fan']) for (const width of [1440, 390]) {
        const page = await contexts[role].newPage(); page.on('pageerror', error => errors.push(error.message));
        await page.setViewportSize({ width, height: width === 390 ? 844 : 1000 });
        for (const view of ['explorer', 'profile']) {
          const path = view === 'explorer' ? '/app/fan/explorer' : '/app/creators/' + fixture.profiles.member;
          const response = await page.goto(base + path); assert.equal(response.status(), 200); await settle(page); await noOverflow(page);
          const text = await page.locator('.fu-main').innerText();
          assert.ok(!/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/.test(text));
          if (role === 'guest') {
            const form = page.locator('.faluss-fans-sso-form');
            // Record only field names and the return path; never nonce/cookie/code values.
            sso.push(await page.locator('form').evaluateAll(forms => forms.filter(form => form.method === 'post').map(form => ({ method: form.method, action: new URL(form.getAttribute('action') || location.href, location.href).pathname, fields: [...form.elements].map(input => input.name).filter(Boolean) }))));
          }
          if (view === 'profile') {
            await page.waitForFunction(() => !document.querySelector('[data-text-status]')?.textContent.includes('Chargement'));
            if (scenario !== 'no-media') {
              for (const control of await page.locator('[data-image-load]').all()) {
                await control.click();
                await page.waitForFunction(() => ![...document.querySelectorAll('[data-image-load]')].some(button => button.getAttribute('aria-disabled') === 'true'));
                if (await page.locator('.fu-publication-image__output img').count()) break;
              }
              assert.equal(await page.locator('.fu-publication-image__output img').count(), 1);
            }
          }
          if (stage === 'after') {
            assert.ok(!text.includes('Retrouvez votre espace'));
            if (view === 'explorer') {
              assert.equal(await page.locator('[data-fans-publications]').count(), 0);
              assert.equal(await page.locator('.fu-discovery-hero__slide').count(), 3);
              assert.equal(await page.locator('.fu-discovery-hero__slide:visible h2').innerText(), fixture.discovery[0].name);
              assert.equal(await page.locator('.fu-discovery-row').count(), scenario === 'many' ? 3 : 1);
              assert.equal(await page.locator('.fu-discovery-row__track').first().locator('article').count(), scenario === 'many' ? 10 : 1);
              assert.equal(await page.locator('.fu-discovery-hero__slide:visible').count(), 1);
              const next = page.getByRole('button', { name: 'Créateur suivant', exact: true });
              await next.focus(); await page.keyboard.press('Enter');
              assert.equal(await page.locator('.fu-discovery-hero__slide:visible').getAttribute('id'), 'fu-discovery-slide-1');
              assert.equal(await next.evaluate(button => button === document.activeElement), true);
              await page.getByRole('button', { name: 'Créateur précédent', exact: true }).click();
              if (scenario === 'no-media') assert.equal(await page.locator('[data-fans-hero] img,.fu-discovery-card img').count(), 0);
            } else {
              assert.equal(await page.locator('.fu-public-creator__copy h2').innerText(), fixture.discovery[0].name);
              assert.equal(await page.locator('.fu-text-card').count(), 3);
              if (scenario === 'no-media') assert.equal(await page.locator('.fu-public-creator__portrait img').count(), 0);
            }
          }
          await page.evaluate(() => scrollTo(0, 0));
          await page.screenshot({ path: `${out}/${stage}/${scenario}-${role}-${view}-${width}.png`, fullPage: true, animations: 'disabled' });
          checks.push(`${scenario}/${role}/${view}/${width}: HTTP 200, no UUID, no overflow, real public projection`);
        }
        await page.close();
      }
    }
    state('many');
    // Untouched views: compare rendered content and exact pixels at identical dimensions.
    for (const [role, path] of [['guest', '/app/fan/hof'], ['fan', '/app/fan/accueil'], ['member', '/app/creator/progression'], ['member', '/app/creator/mon-profil']]) {
      const page = await contexts[role].newPage(); await page.setViewportSize({ width: 1440, height: 1000 });
      assert.equal((await page.goto(base + path)).status(), 200); await page.evaluate(() => document.fonts.ready); await page.waitForTimeout(500);
      assert.equal(await page.locator('link[href*="fans-discovery.css"]').count(), 0);
      const shot = await page.screenshot({ animations: 'disabled' });
      untouched[path] = createHash('sha256').update(shot).digest('hex'); await page.close();
    }
    if (stage === 'after') {
      assert.deepEqual(untouched, JSON.parse(fs.readFileSync(out + '/before/browser.json')).untouched);
      const page = await contexts.fan.newPage(); await page.setViewportSize({ width: 1440, height: 1000 });
      await page.goto(base + '/app/fan/explorer'); await settle(page);
      const track = page.locator('.fu-discovery-row__track').first(); await track.focus(); await page.keyboard.press('ArrowRight'); await page.waitForTimeout(500);
      assert.ok(await track.evaluate(element => element.scrollLeft > 0));
      await page.getByRole('button', { name: 'Voir tous les créateurs : Arts', exact: true }).click(); await settle(page);
      assert.equal(await page.locator('.fu-discovery-card').count(), 12);
      assert.equal(await page.locator('[data-category=arts]').getAttribute('aria-pressed'), 'true');
      await page.locator('[data-category=learning]').click(); await settle(page); assert.equal(await page.locator('.fu-discovery-card').count(), 0);
      assert.ok((await page.locator('.fu-empty').innerText()).includes('De nouveaux univers'));
      await page.locator('[data-category=""]').click(); await settle(page); await localLinks(page);
      await page.locator('.fu-discovery-hero__slide:visible a').click(); await page.waitForURL('**/app/creators/*'); await settle(page);
      await localLinks(page); await page.getByRole('link', { name: '← Explorer', exact: true }).click(); await page.waitForURL('**/explorer'); await settle(page);
      checks.push('Keyboard carousel, row ArrowRight, Voir tous/filter 12 profiles, empty category, real Explorer → profile → Explorer');
      for (const width of [320, 600, 700, 701, 1024]) { await page.setViewportSize({ width, height: 844 }); await noOverflow(page); }
      const touch = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
      await touch.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
      const tp = await touch.newPage(); await tp.goto(base + '/app/fan/explorer'); await settle(tp);
      const row = tp.locator('.fu-discovery-row__track').first(); await row.scrollIntoViewIfNeeded();
      const box = await row.boundingBox(), cdp = await touch.newCDPSession(tp);
      await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: box.x + box.width * .85, y: box.y + 70 }] });
      for (let step = 1; step <= 6; step++) await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: box.x + box.width * (.85 - step * .1), y: box.y + 70 }] });
      await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] }); await tp.waitForTimeout(400);
      assert.ok(await row.evaluate(element => element.scrollLeft > 0), 'native touch scroll'); await noOverflow(tp); await touch.close();
      checks.push('320/600/700/701/1024 overflow checks; native Chromium touch swipe at 390px');
      state('empty'); await page.goto(base + '/app/fan/explorer'); await settle(page); assert.equal(await page.locator('.fu-discovery-hero').count(), 0);
      for (const role of ['guest', 'fan', 'admin']) {
        assert.equal((await contexts[role].request.get(base + '/app/creators/' + fixture.profiles.member)).status(), 404);
        assert.equal((await contexts[role].request.get(base + '/app/creators/00000000-0000-4000-8000-000000000099')).status(), 404);
      }
      state('one');
      for (const role of ['guest', 'fan']) for (const path of ['editorial/' + fixture.profiles.member + '/private', 'images/' + fixture.image + '/bytes']) {
        const response = await contexts[role].request.get(base + '/wp-json/faluss-fans/v1/' + path, { headers: role === 'fan' ? { 'X-WP-Nonce': fixture.sessions.fan.nonce } : {} });
        assert.ok([401, 403].includes(response.status()), 'private endpoint denied');
      }
      checks.push('Empty catalogue; pending/nonexistent profile HTTP 404 for guest/Fan/admin; private editorial/image denied');
      await page.close();
    }
    assert.deepEqual(errors, []);
    if (stage === 'after') assert.deepEqual(sso, JSON.parse(fs.readFileSync(out + '/before/browser.json')).sso);
    fs.writeFileSync(out + '/' + stage + '/browser.json', JSON.stringify({ browser: browser.version(), stage, checks, sso, untouched }, null, 2));
    console.log(`PASS ${stage}: ${checks.length} grouped checks`);
  } finally { await browser.close(); }
})().catch(error => { console.error(error.stack); process.exitCode = 1; });
