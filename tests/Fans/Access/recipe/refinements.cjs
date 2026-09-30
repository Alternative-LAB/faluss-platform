// Run against the disposable WordPress + Elementor access recipe only.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
(async () => {
  const base = process.env.BASE, out = process.env.OUT;
  assert.equal(new URL(base).hostname, '127.0.0.1');
  fs.mkdirSync(out, { recursive: true });
  const sessions = JSON.parse(fs.readFileSync(process.env.SESSION)).sessions;
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const results = [];
  try {
    for (const width of [1440, 390, 320]) {
      for (const role of ['guest', 'member-on']) {
        const context = await browser.newContext({ viewport: { width, height: 900 } });
        await context.route('**/*', r => new URL(r.request().url()).origin === base ? r.continue() : r.abort());
        if (role !== 'guest') await context.addCookies(Object.entries(sessions[role]).map(([name, value]) => ({ name, value, domain: '127.0.0.1', path: '/' })));
        const page = await context.newPage();
        let reference;
        for (const view of ['explorer', 'hof', 'accueil', 'messages', 'classement-fans', 'espace']) {
          await page.goto(base + '/faluss-fans/fan/' + view);
          await page.evaluate(() => document.fonts.ready);
          const box = await page.locator('.fu-main').boundingBox();
          const dimensions = [box.x, box.y, box.width];
          if (reference) assert.deepEqual(dimensions, reference, `${role}/${width}/${view} main jump`);
          reference = dimensions;
          assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
          const active = page.locator('.fu-nav__item.is-active .fu-nav__label');
          if (await active.count()) assert.equal(await active.evaluate(e => getComputedStyle(e).visibility), 'visible');
          const inactive = page.locator('.fu-nav__item:not(.is-active)').first();
          await page.mouse.move(width - 1, 1);
          if (width > 700) assert.equal(await inactive.locator('.fu-nav__label').evaluate(e => getComputedStyle(e).visibility), 'hidden');
          await inactive.hover();
          assert.equal(await inactive.locator('.fu-nav__label').evaluate(e => getComputedStyle(e).visibility), 'visible');
          await page.mouse.move(width - 1, 1);
          await page.keyboard.press('Tab');
          await inactive.focus();
          assert.equal(await inactive.locator('.fu-nav__label').evaluate(e => getComputedStyle(e).visibility), 'visible');
          assert.equal(await inactive.evaluate(e => getComputedStyle(e).outlineStyle), 'solid');
          const button = page.getByRole('button', { name: 'Rejoindre avec Faluss Identity', exact: true });
          if (await button.count()) {
            const b = await button.boundingBox();
            assert.ok(b.width <= 340 && b.height >= 44 && b.height <= 70);
          }
          if (width !== 320 && ['explorer', 'hof', 'accueil'].includes(view)) await page.screenshot({ path: path.join(out, `${role}-${view}-${width}.png`), fullPage: true });
          results.push({ width, role, view, main: dimensions, hoverFocus: true, overflow: false });
        }
        if (role === 'guest') {
          await page.goto(base);
          await page.evaluate(() => document.fonts.ready);
          assert.ok(await page.locator('[data-elementor-id]').count());
          const button = page.getByRole('button', { name: 'Rejoindre avec Faluss Identity', exact: true });
          const b = await button.boundingBox();
          assert.ok(b.width <= 340 && b.height >= 44 && b.height <= 70);
          await page.screenshot({ path: path.join(out, `elementor-${width}.png`), fullPage: true });
        }
        await context.close();
      }
    }
    fs.writeFileSync(path.join(out, 'results.json'), JSON.stringify({ browser: await browser.version(), results }, null, 2));
    console.log(`PASS ${results.length} route/role/viewport combinations`);
  } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exitCode = 1; });
