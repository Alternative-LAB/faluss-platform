const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const launchBrowser = require('./browser-engine.cjs');
const base = process.env.FANS_UI_BASE || 'http://127.0.0.1:8765';
const output = process.env.FANS_UI_OUTPUT || path.resolve(__dirname, '../../../../docs/evidence/fans-48h/lot-3');
fs.mkdirSync(output, { recursive: true });
const uuid = '123e4567-e89b-42d3-a456-426614174000';
(async () => {
  const browser = await launchBrowser();
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();
    const cookie = (name, value) => context.addCookies([{ name, value, url: base }]);
    const loaded = () => page.getByText('Publications chargées. Les textes restent soumis à la modération.').waitFor();
    await page.goto(`${base}/app/fan/explorer`);
    await loaded();
    assert.equal(await page.locator('.fu-text-card').count(), 1);
    await page.getByRole('button', { name: 'Page suivante' }).click();
    await page.getByText('Fixture de recette — seconde page.', { exact: true }).waitFor();
    assert.equal(await page.locator('.fu-text-card').count(), 1);
    assert.equal(await page.getByRole('button', { name: 'Page suivante' }).count(), 0);
    await page.getByRole('button', { name: 'Recommencer la lecture' }).click();
    await loaded();
    await page.locator('.fu-text-card a').click();
    await page.getByText('Profil public chargé.', { exact: true }).waitFor();
    for (const role of ['guest', 'admin', 'fan']) {
      await cookie('fans_ui_role', role);
      assert.equal((await page.goto(`${base}/app/creator/mon-profil`)).status(), role === 'fan' ? 404 : 403);
    }
    await page.goto(`${base}/app/fan/accueil`);
    await loaded();
    assert.equal(await page.locator('.fu-home-grid a').count(), 3);
    await page.screenshot({ path: path.join(output, 'accueil-fan-desktop.png'), fullPage: true });
    await cookie('fans_ui_role', 'creator');
    await page.goto(`${base}/app/creator/accueil`);
    await loaded();
    assert.equal(await page.locator('.fu-nav__item').count(), 8);
    assert.doesNotMatch(await page.locator('main').innerText(), /wallet|solde|\bPF\b|€|\bPC\b|123e4567/);
    await page.screenshot({ path: path.join(output, 'accueil-createur-desktop.png'), fullPage: true });
    await page.goto(`${base}/app/creator/mon-profil`);
    assert.equal(await page.getByRole('link', { name: 'Voir ma fiche publique' }).getAttribute('href'), `${base}/app/creators/${uuid}`);
    assert.doesNotMatch(await page.locator('main').innerText(), /123e4567/);
    await page.screenshot({ path: path.join(output, 'mon-profil-desktop.png'), fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({ path: path.join(output, 'mon-profil-mobile.png'), fullPage: true });
    for (const state of ['pending', 'suspended']) {
      await cookie('fans_ui_profile', state);
      await page.reload();
      assert.equal(await page.getByRole('link', { name: 'Voir ma fiche publique' }).count(), 0);
      await page.getByText('Votre fiche n’est pas publique.', { exact: false }).waitFor();
    }
    await page.screenshot({ path: path.join(output, 'profil-suspendu-mobile.png'), fullPage: true });
    await cookie('fans_ui_profile', 'active');
    for (const role of ['fan', 'creator']) {
      await cookie('fans_ui_role', role);
      await page.goto(`${base}/faluss-fans/${role}/accueil`);
      await loaded();
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true);
      await page.screenshot({ path: path.join(output, `accueil-${role}-mobile.png`), fullPage: true });
    }
    for (const mode of ['closed', 'error', 'empty']) {
      await cookie('fans_ui_texts', mode);
      await page.reload();
      await page.getByText(mode === 'empty' ? 'Aucune publication publique disponible.' : 'Publications indisponibles. Le service est fermé ou ne répond pas. Vous pouvez réessayer.', { exact: true }).waitFor();
      assert.equal(await page.locator('.fu-text-card').count(), 0);
      assert.equal(await page.getByRole('button', { name: 'Page suivante' }).count(), 0);
    }
    // Malformed/stale responses and text injection are boundary tests, never product fixtures.
    const api = '**/wp-json/faluss-fans/v1/text-publications?*';
    await page.route(api, route => route.fulfill({ json: { items: [{ publication_id: uuid, creator_id: uuid, revision: 1, body: '<img src=x onerror="window.injected=true">', updated_at: 'fixture' }], next_cursor: null } }));
    await page.getByRole('button', { name: 'Recommencer la lecture' }).click();
    await loaded();
    assert.equal(await page.locator('.fu-text-card img').count(), 0);
    assert.equal(await page.evaluate(() => window.injected), undefined);
    assert.match(await page.locator('.fu-text-body').innerText(), /<img/);
    await page.unroute(api);
    await page.route(api, route => route.fulfill({ json: { items: [{ body: 'invalid' }], next_cursor: null } }));
    await page.getByRole('button', { name: 'Recommencer la lecture' }).click();
    await page.getByText('Publications indisponibles.', { exact: false }).waitFor();
    assert.equal(await page.locator('.fu-text-card').count(), 0);
    await page.unroute(api);
    let release;
    await page.route(api, async route => {
      await new Promise(resolve => { release = resolve; });
      await route.fulfill({ json: { items: [{ publication_id: uuid, creator_id: uuid, revision: 1, body: 'obsolete', updated_at: 'fixture' }], next_cursor: null } });
    });
    await page.getByRole('button', { name: 'Recommencer la lecture' }).click();
    await page.waitForFunction(() => document.querySelector('[data-text-status]').textContent === 'Chargement des publications…');
    while (!release) await new Promise(resolve => setTimeout(resolve, 10));
    await page.evaluate(() => window.dispatchEvent(new Event('pagehide')));
    release();
    await page.unrouteAll({ behavior: 'wait' });
    assert.equal(await page.locator('.fu-text-card').count(), 0);
    await page.setViewportSize({ width: 320, height: 640 });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true);
    console.log('Reading UI: roles, profile states, pagination, closed/error/empty, text safety, stale responses, mobile passed.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
