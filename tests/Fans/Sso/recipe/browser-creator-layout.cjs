const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
(async () => {
  assert.equal(new URL(process.env.BASE).hostname, '127.0.0.1');
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const results = [];
  try {
    const page = await browser.newPage();
    await page.route('**/*', r => new URL(r.request().url()).origin === process.env.BASE ? r.continue() : r.abort());
    for (const width of [1440, 390, 320]) {
      await page.setViewportSize({ width, height: 900 });
      let reference;
      for (const view of ['accueil', 'explorer', 'hof', 'creer', 'boutique', 'progression', 'mon-profil']) {
        await page.goto(`${process.env.BASE}/?button_fixture=1&fans_ui=1&creator_view=${view}`);
        await page.evaluate(() => document.fonts.ready);
        assert.equal(await page.locator('.fu-nav__item').count(), 8);
        const box = await page.locator('.fu-main').boundingBox(), geometry = [box.x, box.y, box.width];
        if (reference) assert.deepEqual(geometry, reference);
        reference = geometry;
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
        assert.equal(await page.locator('.is-active .fu-nav__label').evaluate(e => getComputedStyle(e).visibility), 'visible');
        for (const item of await page.locator('.fu-nav__item:not(.is-active)').all()) {
          await item.hover();
          assert.equal(await item.locator('.fu-nav__label').evaluate(e => getComputedStyle(e).visibility), 'visible');
          await page.mouse.move(width - 1, 1);
          await page.keyboard.press('Tab');
          await item.focus();
          assert.equal(await item.locator('.fu-nav__label').evaluate(e => getComputedStyle(e).visibility), 'visible');
        }
        if (width !== 320 && ['accueil', 'creer'].includes(view)) await page.screenshot({ path: `${process.env.OUT}/creator-${view}-${width}.png`, fullPage: true });
        results.push({ width, view, geometry, links: 8, hoverFocus: true });
      }
    }
    fs.writeFileSync(`${process.env.OUT}/creator-results.json`, JSON.stringify(results, null, 2));
    console.log('PASS 21 creator layout combinations (rendering fixture only)');
  } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exitCode = 1; });
