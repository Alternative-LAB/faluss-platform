// BASE must be the disposable loopback WordPress from button-wordpress.py.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

(async () => {
  const base = process.env.BASE;
  assert.equal(new URL(base).hostname, '127.0.0.1');
  const out = process.env.OUT;
  fs.mkdirSync(out, { recursive: true });
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const results = [];
  try {
    for (const width of [1286, 390, 320]) {
      const context = await browser.newContext({ viewport: { width, height: width > 700 ? 744 : 844 }, javaScriptEnabled: false });
      const page = await context.newPage();
      const external = [];
      await context.route('**/*', route => {
        if (new URL(route.request().url()).origin !== base) { external.push(new URL(route.request().url()).origin); return route.abort(); }
        return route.continue();
      });
      await page.goto(base + '/?button_fixture=1');
      await page.evaluate(() => document.fonts.ready);
      const button = page.getByRole('button', { name: 'Rejoindre avec Faluss Identity', exact: true });
      assert.equal(await button.count(), 1);
      const style = await button.evaluate(el => ({ background: getComputedStyle(el).backgroundColor, font: getComputedStyle(el).fontFamily, width: el.getBoundingClientRect().width, height: el.getBoundingClientRect().height }));
      assert.equal(style.background, 'rgb(65, 194, 149)');
      assert.ok(style.font.includes('FalussSsoOutfit'));
      assert.equal(style.width, Math.min(340, width - 40));
      assert.ok(style.height >= 44 && style.height <= 60);
      assert.ok(await page.evaluate(() => document.fonts.check('22px FalussSsoOutfit')));
      assert.ok(await button.locator('img').evaluate(el => el.complete && el.naturalWidth > 0 && el.alt === '' && el.getAttribute('aria-hidden') === 'true'));
      assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
      const controlStyle = () => page.locator('#control').evaluate(el => { const s = getComputedStyle(el); return [s.backgroundColor, s.color, s.borderRadius, s.fontFamily, s.padding]; });
      const originalControl = await controlStyle();
      await page.locator('#faluss-fans-sso-button-css').evaluate(el => el.disabled = true);
      assert.deepEqual(await controlStyle(), originalControl);
      await page.locator('#faluss-fans-sso-button-css').evaluate(el => el.disabled = false);
      await new Promise(resolve => setTimeout(resolve, 250)); // Let stylesheet restoration/transition settle, even with page JS disabled.
      await page.evaluate(() => document.fonts.ready);
      assert.equal(await button.evaluate(el => getComputedStyle(el).backgroundColor), 'rgb(65, 194, 149)');
      await page.screenshot({ path: path.join(out, `button-${width}.png`), fullPage: true });
      await button.hover();
      await new Promise(resolve => setTimeout(resolve, 250));
      assert.equal(await button.evaluate(el => getComputedStyle(el).backgroundColor), 'rgb(50, 158, 121)');
      await page.mouse.move(0, 0);
      await page.keyboard.press('Tab');
      await button.focus();
      await new Promise(resolve => setTimeout(resolve, 250));
      assert.equal(await button.evaluate(el => getComputedStyle(el).outlineStyle), 'solid');
      await page.screenshot({ path: path.join(out, `focus-${width}.png`), fullPage: true });
      // Browser submits the actual native POST with JavaScript disabled. Never follow an SSO redirect.
      let submitted;
      await page.route('**/wp-admin/admin-post.php', async route => {
        submitted = route.request();
        await route.fulfill({ status: 200, contentType: 'text/plain', body: 'POST local intercepté pour assertion' });
      });
      await button.press('Enter');
      await page.waitForURL('**/wp-admin/admin-post.php');
      assert.equal(submitted.method(), 'POST');
      const fields = new URLSearchParams(submitted.postData());
      assert.deepEqual([...fields.keys()].sort(), ['action', 'faluss_fans_return_to', 'faluss_fans_sso_nonce']);
      assert.equal(fields.get('action'), 'faluss_fans_sso_start');
      assert.match(fields.get('faluss_fans_sso_nonce'), /^[a-z0-9]{10}$/);
      assert.equal(fields.get('faluss_fans_return_to'), '/faluss-fans/fan/espace');
      // Same production form inside the standalone Fans shell, including its older button styles.
      await page.goto(base + '/?button_fixture=1&fans_ui=1');
      await page.evaluate(() => document.fonts.ready);
      assert.equal(await button.evaluate(el => getComputedStyle(el).backgroundColor), 'rgb(65, 194, 149)');
      assert.equal(await button.evaluate(el => getComputedStyle(el).color), 'rgb(255, 255, 255)');
      assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
      await page.screenshot({ path: path.join(out, `fans-shell-${width}.png`), fullPage: true });
      assert.deepEqual(external, []);
      results.push({ viewportWidth: width, ...style, nativePost: true, unrelatedButtonUnchanged: true, shell: true, javascript: false, externalRequests: 0 });
      await context.close();
    }
    fs.writeFileSync(path.join(out, 'results.json'), JSON.stringify({ browser: await browser.version(), results }, null, 2) + '\n');
    console.log(JSON.stringify(results));
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
