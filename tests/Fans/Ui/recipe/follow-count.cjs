const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const launchBrowser = require('./browser-engine.cjs');
const base = process.env.FANS_UI_BASE || 'http://127.0.0.1:8765';
const output = process.env.FANS_UI_OUTPUT || path.resolve(__dirname, '../../../../docs/evidence/fans-48h/lot-13');
fs.mkdirSync(output, { recursive: true });
const id = '123e4567-e89b-42d3-a456-426614174000';
const endpoint = `**/creators/${id}/followers/count`;

(async () => {
  const browser = await launchBrowser();
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();
    let countCalls = 0;
    let status = 200;
    let data = { creator_id: id, count: 7 };
    let pending = null;
    let received = null;
    await page.route(endpoint, async route => {
      countCalls += 1;
      assert.equal(route.request().method(), 'GET');
      const reply = { status, json: data };
      if (received) received();
      if (pending) await pending;
      await route.fulfill(reply);
    });
    const counter = page.locator('[data-fans-follow-count]');
    const loaded = text => page.getByText(text, { exact: true }).waitFor();
    for (const width of [1440, 390]) {
      await page.setViewportSize({ width, height: width === 1440 ? 900 : 844 });
      for (const role of ['guest', 'fan', 'creator', 'admin']) {
        await context.addCookies([{ name: 'fans_ui_role', value: role, url: base }]);
        const before = countCalls;
        assert.equal((await page.goto(`${base}/faluss-fans/creators/${id}`)).status(), 200);
        await loaded('Suivis enregistrés : 7');
        assert.equal(countCalls, before + 1);
        assert.equal(await counter.getAttribute('role'), 'status');
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true);
        assert.equal(await page.getByRole('button', { name: /suivre|ne plus suivre/i }).count(), 0);
        assert.doesNotMatch(await page.locator('main').innerText(), /123e4567|€|\bPC\b|wallet|Guest_/);
        if (role === 'creator') {
          assert.equal(await page.locator('.fu-nav__item').count(), 8);
          await page.locator('[data-fans-profile]').scrollIntoViewIfNeeded();
          await page.screenshot({ path: path.join(output, `suivis-${width}.png`) });
        }
      }
    }
    data = { creator_id: id, count: 0 };
    await page.reload(); await loaded('Suivis enregistrés : 0');
    for (const invalid of [-1, 1.5, '7', null, Number.MAX_SAFE_INTEGER + 1]) {
      data = { creator_id: id, count: invalid };
      await page.reload(); await loaded('Nombre de suivis indisponible.');
      assert.equal(await page.locator('.fu-profile').count(), 1);
    }
    data = { creator_id: '123e4567-e89b-42d3-a456-426614174001', count: 7 };
    await page.reload(); await loaded('Nombre de suivis indisponible.');
    for (const code of [404, 503]) {
      status = code; data = {};
      await page.reload(); await loaded('Nombre de suivis indisponible.');
      assert.equal(await page.locator('.fu-profile').count(), 1);
    }
    status = 200; data = { creator_id: id, count: 8 };
    let release;
    pending = new Promise(resolve => { release = resolve; });
    const arrival = new Promise(resolve => { received = resolve; });
    await page.reload(); await arrival;
    await page.evaluate(() => window.dispatchEvent(new Event('pagehide')));
    assert.equal(await page.locator('.fu-profile').count(), 0);
    release(); pending = null; received = null;
    data = { creator_id: id, count: 9 };
    await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
    await loaded('Suivis enregistrés : 9');
    assert.equal(await counter.count(), 1);
    await page.evaluate(() => {
      Object.defineProperty(document, 'hidden', { configurable: true, value: true });
      document.dispatchEvent(new Event('visibilitychange'));
    });
    assert.equal(await counter.count(), 0);
    await page.evaluate(() => {
      delete document.hidden;
      document.dispatchEvent(new Event('visibilitychange'));
    });
    await loaded('Suivis enregistrés : 9');
    for (const state of ['missing', 'suspended', 'withdrawn']) {
      await context.addCookies([{ name: 'fans_ui_public_state', value: state, url: base }]);
      const before = countCalls;
      const target = state === 'missing' ? id.replace(/000$/, '099') : id;
      assert.equal((await page.goto(`${base}/faluss-fans/creators/${target}`)).status(), 404);
      assert.equal(await counter.count(), 0);
      assert.equal(countCalls, before);
    }
    console.log('Public follow count passed: four roles/two widths, exact zero, invalid/error/closed states, stale response, visibility/BFcache and non-public profiles.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
