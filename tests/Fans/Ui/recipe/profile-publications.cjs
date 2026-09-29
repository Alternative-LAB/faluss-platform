const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const launchBrowser = require('./browser-engine.cjs');
const base = process.env.FANS_UI_BASE || 'http://127.0.0.1:8765';
const output = process.env.FANS_UI_OUTPUT || path.resolve(__dirname, '../../../../docs/evidence/fans-48h/lot-9');
fs.mkdirSync(output, { recursive: true });
const first = '123e4567-e89b-42d3-a456-426614174000';
const second = '123e4567-e89b-42d3-a456-426614174001';
(async () => {
  const browser = await launchBrowser();
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const page = await context.newPage();
    const cookie = (name, value) => context.addCookies([{ name, value, url: base }]);
    const loaded = () => page.getByText('Publications chargées.', { exact: false }).waitFor();
    const requests = [];
    page.on('request', request => { if (new URL(request.url()).pathname.endsWith('/text-publications')) requests.push(new URL(request.url())); });
    for (const role of ['guest', 'fan', 'creator', 'admin']) {
      await cookie('fans_ui_role', role);
      await page.goto(`${base}/faluss-fans/fan/explorer`);
      await page.getByRole('link', { name: 'Consulter la fiche publique, catégorie Musique' }).waitFor();
      const start = requests.length;
      await page.getByRole('link', { name: 'Consulter la fiche publique, catégorie Musique' }).click();
      await loaded();
      await page.getByText('Fixture de recette — texte du second profil.', { exact: true }).waitFor();
      assert.equal(await page.locator('[data-fans-publications]').getAttribute('data-creator-id'), second);
      assert.equal(await page.locator('.fu-text-card a').count(), 0);
      assert.doesNotMatch(await page.locator('main').innerText(), /123e4567|Guest_|wallet|€|\bPC\b/);
      await page.getByRole('button', { name: 'Page suivante' }).click();
      await page.getByText('Fixture de recette — seconde page.', { exact: true }).waitFor();
      assert.equal(await page.getByRole('button', { name: 'Page suivante' }).count(), 0);
      assert.equal(requests.slice(start).length, 2);
      for (const url of requests.slice(start)) assert.equal(url.searchParams.get('creator_id'), second);
      assert.equal(requests.at(-1).searchParams.get('cursor'), 'fixture-next-' + second);
      await page.getByRole('button', { name: 'Recommencer la lecture' }).click(); await loaded();
      if (role === 'creator') {
        assert.equal(await page.locator('.fu-nav__item').count(), 8);
        await page.evaluate(() => scrollTo(0, 0));
        await page.screenshot({ path: path.join(output, 'profil-public-textes-desktop.png'), fullPage: true });
      }
    }
    await cookie('fans_ui_role', 'guest');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.reload(); await loaded();
    await page.locator('[data-fans-publications]').evaluate(element => element.scrollIntoView({ block: 'start' }));
    await page.screenshot({ path: path.join(output, 'profil-public-textes-mobile.png') });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true);
    for (const mode of ['empty', 'closed', 'error']) {
      await cookie('fans_ui_texts', mode); await page.reload();
      await page.getByText(mode === 'empty' ? 'Aucune publication publique disponible.' : 'Publications indisponibles.', { exact: false }).waitFor();
      assert.equal(await page.locator('.fu-text-card').count(), 0);
      assert.equal(await page.getByRole('button', { name: 'Page suivante' }).count(), 0);
    }
    await cookie('fans_ui_texts', 'available');
    const api = '**/text-publications?*';
    await page.route(api, route => route.fulfill({ json: { items: [{ publication_id: first, creator_id: first, revision: 1, body: 'Wrong creator fixture', updated_at: 'fixture' }], next_cursor: null } }));
    await page.reload();
    await page.getByText('Publications indisponibles.', { exact: false }).waitFor();
    assert.equal(await page.locator('.fu-text-card').count(), 0);
    await page.unroute(api);
    await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
    await loaded();
    await page.getByText('Fixture de recette — texte du second profil.', { exact: true }).waitFor();
    await page.evaluate(() => window.dispatchEvent(new Event('pagehide')));
    assert.equal(await page.locator('.fu-text-card').count(), 0);
    for (const state of ['missing', 'suspended', 'withdrawn']) {
      await cookie('fans_ui_public_state', state);
      const before = requests.length;
      const id = state === 'missing' ? '123e4567-e89b-42d3-a456-426614174099' : second;
      assert.equal((await page.goto(`${base}/faluss-fans/creators/${id}`)).status(), 404);
      assert.equal(await page.locator('[data-fans-publications]').count(), 0);
      assert.equal(requests.length, before);
    }
    await cookie('fans_ui_public_state', 'active');
    await cookie('fans_ui_images', 'open');
    await page.route('**/text-publications/*/image/*', route => {
      const url = new URL(route.request().url());
      assert.equal(url.search, '');
      assert.equal(url.pathname, `/wp-json/faluss-fans/v1/text-publications/${second}/image/1`);
      return route.fulfill({ status: 503, json: {} });
    });
    await page.goto(`${base}/faluss-fans/creators/${second}`); await loaded();
    await page.getByRole('button', { name: 'Vérifier l’image associée' }).click();
    await page.getByText('Image indisponible.', { exact: false }).waitFor();
    console.log('Public profile publications: four roles, Explorer path, scoped pagination, foreign response refusal, closed/empty/error, document 404, BFcache and image URL passed.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
