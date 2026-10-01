const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const launch = require('../../Ui/recipe/browser-engine.cjs');
const base = 'http://127.0.0.1:8768';
const id = '11111111-1111-4111-8111-111111111111';
const output = process.env.FANS_UI_OUTPUT;
if (!output) throw new Error('FANS_UI_OUTPUT required');
fs.mkdirSync(output, { recursive: true });

(async () => {
  const browser = await launch();
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  // Every request stays inside the isolated adapter. No internet/WordPress sites.
  await context.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
  const page = await context.newPage(); const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  const role = async user => context.addCookies([{ name: 'fixture_user', value: String(user), url: base }]);
  const capture = async name => {
    await page.evaluate(() => document.fonts.ready);
    await page.evaluate(() => { document.activeElement?.blur(); window.scrollTo(0, 0); });
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, 'no horizontal overflow');
    await page.screenshot({ path: path.join(output, name + '.png'), fullPage: true });
  };
  const api = async (route, data) => {
    const response = await context.request.fetch(`${base}/wp-json/faluss-fans/v1/${route}`, {
      method: data ? 'POST' : 'GET', headers: { 'X-WP-Nonce': 'fixture-wp_rest' }, ...(data ? { data } : {})
    });
    return { status: response.status(), data: await response.json() };
  };
  try {
    await role(17);
    await page.goto(base + '/app/creator/mon-profil');
    await page.locator('#fu-public-name').fill('Atelier de démonstration · test');
    await page.locator('#fu-bio').fill('Présentation synthétique pour la recette isolée.\nAucune identité réelle ni donnée de production.');
    await page.getByLabel('Image approuvée 1', { exact: true }).check();
    await page.getByRole('button', { name: 'Examiner l’image 1', exact: true }).click();
    await page.locator('.fu-private-preview img').waitFor();
    await capture('owner-desktop');
    await page.setViewportSize({ width: 390, height: 844 }); await capture('owner-mobile');
    await page.getByRole('button', { name: 'Soumettre à la modération' }).click();
    await page.getByText('Action confirmée. L’état courant est affiché ci-dessous.').waitFor();
    let own = await api('creators/me/editorial'); assert.equal(own.data.state, 'pending');
    await role(0);
    assert.equal((await api('creators/' + id)).data.editorial, null);
    await page.goto(base + '/app/creators/' + id);
    await page.getByRole('heading', { name: 'Profil sans nom public' }).waitFor();
    assert.equal(await page.getByText('Atelier de démonstration · test').count(), 0);
    assert.equal((await page.goto(base + '/app/creator/mon-profil')).status(), 403);
    await role(1);
    await page.goto(base + '/admin.php?page=faluss-fans-moderation&view=editorial');
    await page.getByRole('heading', { name: 'Atelier de démonstration · test' }).waitFor();
    await page.getByRole('button', { name: 'Examiner l’image privée' }).click();
    await page.locator('.fm-preview img').waitFor();
    await capture('moderation-mobile');
    await page.setViewportSize({ width: 1440, height: 1000 }); await capture('moderation-desktop');
    await page.getByLabel('Décision et motif').selectOption('allowed_editorial');
    await page.getByRole('button', { name: 'Confirmer cette décision' }).click();
    await page.getByText('Décision confirmée et journalisée.').waitFor();
    await role(0);
    await page.goto(base + '/app/fan/explorer');
    await page.getByRole('heading', { name: 'Atelier de démonstration · test' }).waitFor();
    await page.locator('.fu-card__art img').waitFor();
    await capture('explorer-desktop');
    await page.setViewportSize({ width: 390, height: 844 }); await capture('explorer-mobile');
    await page.getByRole('link', { name: 'Consulter la fiche publique, catégorie Arts' }).click();
    await page.locator('.fu-profile__glyph img').waitFor();
    await capture('public-mobile');
    await page.setViewportSize({ width: 1440, height: 1000 }); await capture('public-desktop');
    const publicBefore = (await api('creators/' + id)).data.editorial;
    assert.equal(publicBefore.public_name, 'Atelier de démonstration · test');
    await role(18);
    assert.equal((await page.goto(base + '/app/creator/mon-profil')).status(), 404);
    assert.equal((await api('creators/me/editorial')).status, 403);
    assert.equal((await api('creators/' + id)).data.editorial.public_name, publicBefore.public_name);
    await role(1);
    assert.equal((await api(`creators/${id}/status`, { status: 'suspended' })).status, 200);
    await role(0);
    assert.equal((await page.goto(base + '/app/creators/' + id)).status(), 404);
    assert.equal((await api(`creators/${id}/portrait/${publicBefore.revision}`)).status, 404);
    await role(1);
    assert.equal((await api(`creators/${id}/status`, { status: 'active' })).status, 200);
    await role(17);
    await page.goto(base + '/app/creator/mon-profil');
    await page.locator('#fu-bio').fill('Modification privée qui exige une nouvelle revue.');
    await page.getByRole('button', { name: 'Soumettre à la modération' }).click();
    await page.getByText('Action confirmée. L’état courant est affiché ci-dessous.').waitFor();
    assert.deepEqual((await api('creators/' + id)).data.editorial, publicBefore);
    assert.equal((await api(`creators/${id}/portrait/${publicBefore.revision}`)).status, 200);
    await role(1);
    await page.goto(base + '/admin.php?page=faluss-fans-moderation&view=editorial');
    await page.getByLabel('Décision et motif').selectOption('needs_revision');
    await page.getByRole('button', { name: 'Confirmer cette décision' }).click();
    await page.getByText('Décision confirmée et journalisée.').waitFor();
    await role(17);
    own = await api('creators/me/editorial');
    assert.equal(own.data.state, 'rejected'); assert.equal(own.data.public_name, ''); assert.equal(own.data.bio, '');
    await page.goto(base + '/app/creator/mon-profil');
    await page.locator('#fu-public-name').fill('Présentation à retirer · test');
    await page.getByRole('button', { name: 'Soumettre à la modération' }).click();
    await page.getByText('Action confirmée. L’état courant est affiché ci-dessous.').waitFor();
    await page.getByLabel('Effacer mon nom public').check();
    await page.getByRole('button', { name: 'Retirer ma présentation' }).click();
    await page.getByText('Présentation retirée', { exact: true }).waitFor();
    assert.equal((await api('creators/me/editorial')).data.state, 'withdrawn');
    await page.evaluate(() => dispatchEvent(new PageTransitionEvent('pagehide')));
    assert.equal(await page.locator('[data-fans-private-reading]').evaluateAll(els => els.every(el => !el.textContent)), true);
    assert.deepEqual(errors, []);
    fs.writeFileSync(path.join(output, 'result.json'), JSON.stringify({ engine: await browser.version(),
      scope: 'Real services + SQL + GD, isolated WordPress adapters; no real WordPress or SSO',
      checks: ['owner form', 'private preview', 'no preapproval diffusion', 'guest denied private page', 'native moderation', 'Explorer → profile', 'old portrait revocation', 'edit pending', 'reject purge', 'owner withdraw', 'pagehide clear', 'no overflow', 'no JS errors'],
      screenshots: 8 }, null, 2));
    console.log('PASS editorial owner → moderation → public browser journey, 8 captures');
  } finally { await context.close(); await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
