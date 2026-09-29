const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');
const base = process.env.FANS_UI_BASE || 'http://127.0.0.1:8765';
const output = process.env.FANS_UI_OUTPUT || path.resolve(__dirname, '../../../../docs/evidence/fans-48h/lot-7');
fs.mkdirSync(output, { recursive: true });
const imageApi = '**/text-publications/*/image/*';
(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    await context.addInitScript(() => {
      window.fixtureBlobs = new Set();
      const create = URL.createObjectURL.bind(URL), revoke = URL.revokeObjectURL.bind(URL);
      URL.createObjectURL = (blob) => { const url = create(blob); window.fixtureBlobs.add(url); return url; };
      URL.revokeObjectURL = (url) => { window.fixtureBlobs.delete(url); revoke(url); };
    });
    const page = await context.newPage();
    const cookie = (name, value) => context.addCookies([{ name, value, url: base }]);
    const loaded = () => page.getByText('Publications chargées.', { exact: false }).waitFor();
    const button = () => page.getByRole('button', { name: 'Vérifier l’image associée' }).first();
    await page.goto(`${base}/faluss-fans/fan/explorer`);
    await loaded();
    assert.equal(await button().count(), 0);
    await page.getByText('Les images de publications sont indisponibles pour le moment.').waitFor();
    const jpeg = Buffer.from(await page.evaluate(() => {
      const canvas = document.createElement('canvas');
      canvas.width = 800; canvas.height = 400;
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = '#143d31'; ctx.fillRect(0, 0, 800, 400);
      ctx.fillStyle = '#41c295'; ctx.fillRect(40, 40, 120, 120);
      ctx.fillStyle = '#f2f4f1'; ctx.font = 'bold 36px sans-serif';
      ctx.fillText('FIXTURE DE RECETTE', 40, 270);
      ctx.font = '24px sans-serif'; ctx.fillText('Aucune image de personne', 40, 320);
      return canvas.toDataURL('image/jpeg').split(',')[1];
    }), 'base64');
    let count = 0;
    await page.route(imageApi, route => {
      count++;
      assert.match(route.request().url(), /\/123e4567-e89b-42d3-a456-426614174000\/image\/1$/);
      return route.fulfill({ contentType: 'image/jpeg', headers: { 'cache-control': 'private, no-store' }, body: jpeg });
    });
    await cookie('fans_ui_images', 'open');
    for (const role of ['guest', 'fan', 'creator', 'admin']) {
      await cookie('fans_ui_role', role);
      const before = count;
      await page.goto(`${base}/faluss-fans/fan/explorer`);
      await loaded();
      assert.equal(count, before);
      assert.equal(await page.locator('.fu-publication-image img').count(), 0);
      await button().focus(); await page.keyboard.press('Enter');
      await page.locator('.fu-publication-image img').waitFor();
      assert.equal(count, before + 1);
      assert.match(await page.locator('.fu-publication-image img').getAttribute('src'), /^blob:/);
      assert.equal(await page.evaluate(() => window.fixtureBlobs.size), 1);
      assert.doesNotMatch(await page.locator('main').innerText(), /123e4567/);
      if (role === 'guest') {
        await page.locator('[data-fans-publications]').screenshot({ path: path.join(output, 'publication-image-desktop.png') });
        await page.setViewportSize({ width: 390, height: 844 });
        await page.locator('.fu-text-card').evaluate(element => element.scrollIntoView({ block: 'start' }));
        await page.screenshot({ path: path.join(output, 'publication-image-mobile.png') });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true);
      }
      await page.getByRole('button', { name: 'Masquer l’image' }).click();
      assert.equal(await page.evaluate(() => window.fixtureBlobs.size), 0);
      assert.equal(await button().evaluate(element => document.activeElement === element), true);
      await button().click(); await page.locator('.fu-publication-image img').waitFor();
      await page.evaluate(() => window.dispatchEvent(new Event('pagehide')));
      assert.equal(await page.locator('.fu-publication-image img').count(), 0);
      assert.equal(await page.evaluate(() => window.fixtureBlobs.size), 0);
    }
    await page.unroute(imageApi);
    for (const mode of ['absent', 'busy', 'wrong-type', 'too-large', 'corrupt']) {
      await page.route(imageApi, route => route.fulfill({
        status: mode === 'absent' ? 404 : mode === 'busy' ? 503 : 200,
        contentType: mode === 'wrong-type' ? 'text/html' : 'image/jpeg',
        body: mode === 'too-large' ? Buffer.alloc(2097153) : Buffer.from('invalid JPEG'),
      }));
      await page.reload(); await loaded(); await button().click();
      await page.getByText(mode === 'absent' ? 'Aucune image publique disponible pour cette publication.' : 'Image indisponible. Vous pouvez réessayer ou continuer la lecture du texte.').waitFor();
      assert.equal(await page.locator('.fu-publication-image img').count(), 0);
      assert.equal(await page.evaluate(() => window.fixtureBlobs.size), 0);
      assert.equal(await button().isEnabled(), true);
      if (mode === 'absent') {
        await page.locator('[data-fans-publications]').evaluate(element => element.scrollIntoView({ block: 'start' }));
        await page.screenshot({ path: path.join(output, 'image-indisponible-mobile.png') });
      }
      await page.unroute(imageApi);
    }
    // All cards share a generation slot; reset aborts pending requests and ignores stale bytes.
    await page.route('**/text-publications?*', route => route.fulfill({ json: { items: [1, 2].map(() => ({
      publication_id: '123e4567-e89b-42d3-a456-426614174000', creator_id: '123e4567-e89b-42d3-a456-426614174000',
      revision: '1', body: 'Fixture de concurrence', updated_at: 'fixture',
    })), next_cursor: null } }));
    let release;
    await page.route(imageApi, async route => {
      await new Promise(resolve => { release = resolve; });
      await route.fulfill({ contentType: 'image/jpeg', body: jpeg });
    });
    await page.reload(); await loaded(); await button().click();
    while (!release) await new Promise(resolve => setTimeout(resolve, 10));
    assert.equal(await page.locator('[data-image-load]:disabled').count(), 2);
    await page.evaluate(() => {
      Object.defineProperty(document, 'hidden', { configurable: true, value: true });
      document.dispatchEvent(new Event('visibilitychange'));
    });
    release();
    await page.unrouteAll({ behavior: 'wait' });
    assert.equal(await page.locator('.fu-publication-image img').count(), 0);
    assert.equal(await page.evaluate(() => window.fixtureBlobs.size), 0);
    await page.getByText('Lecture suspendue.', { exact: true }).waitFor();
    console.log('Images UI: closed flag, four roles, keyboard, JPEG, 404/503/type/size/decode, serial requests, object URL cleanup and stale responses passed.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
