const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const launch = require('../../Ui/recipe/browser-engine.cjs');
const base = 'http://127.0.0.1:8768';
const output = process.env.FANS_UI_OUTPUT;
if (!output) throw new Error('FANS_UI_OUTPUT required');
fs.mkdirSync(output, { recursive: true });
(async () => {
  const browser = await launch();
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  await context.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
  const page = await context.newPage(); const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  const role = async user => context.addCookies([{ name: 'fixture_user', value: String(user), url: base }]);
  const api = async route => {
    const response = await context.request.get(base + '/wp-json/faluss-fans/v1/' + route, { headers: { 'X-WP-Nonce': 'fixture-wp_rest' } });
    return { status: response.status(), data: await response.json() };
  };
  const capture = async (name, selector) => {
    await page.evaluate(() => document.fonts.ready); await page.evaluate(() => document.activeElement?.blur());
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
    await page.locator(selector).screenshot({ path: path.join(output, name + '.png') });
  };
  try {
    await role(17); await page.goto(base + '/app/creator/creer');
    await page.locator('#fu-author-text').fill('Publication de test isolée : image choisie après enregistrement du texte.');
    await page.getByRole('button', { name: 'Soumettre à la modération', exact: true }).click();
    await page.getByText('Demande traitée. État actuel : En attente de modération.').waitFor();
    const publication = (await api('text-publications/mine')).data.items[0].publication_id;
    await page.getByRole('link', { name: 'Ouvrir ce texte', exact: false }).click();
    await page.getByRole('button', { name: 'Examiner l’image 1', exact: true }).click();
    await page.locator('.fu-author-image img').waitFor();
    await page.locator('input[name="image_choice"]:not([value="none"])').check();
    await page.getByLabel('Je confirme ce choix et sa nouvelle modération.').check();
    assert.doesNotMatch(await page.locator('.fu-author-image').innerText(), /[0-9a-f]{8}-[0-9a-f]{4}-/);
    await capture('association-desktop', '.fu-author-image');
    await page.setViewportSize({ width: 390, height: 844 }); await capture('association-mobile', '.fu-author-image');
    await page.getByRole('button', { name: 'Soumettre le choix de l’image' }).click();
    await page.getByText('Demande traitée. État actuel : En attente de modération.').waitFor();
    const own = (await api('text-publications/' + publication + '/private')).data;
    assert.equal(Number(own.revision), 2); assert.ok(own.image_id);
    await role(0); assert.equal((await api('text-publications/' + publication)).status, 404);
    await role(1); await page.goto(base + '/admin.php?page=faluss-fans-moderation');
    await page.getByRole('button', { name: 'Examiner l’image privée' }).click();
    await page.locator('.fm-preview img').waitFor();
    await capture('text-image-moderation-mobile', '.fm-card');
    await page.setViewportSize({ width: 1440, height: 1000 }); await capture('text-image-moderation-desktop', '.fm-card');
    await page.getByLabel('Décision et motif').selectOption('allowed_text');
    await page.getByRole('button', { name: 'Confirmer cette décision' }).click();
    await page.getByText('Décision confirmée par le serveur et journalisée.').waitFor();
    await role(0); await page.goto(base + '/app/creators/11111111-1111-4111-8111-111111111111');
    await page.getByRole('button', { name: 'Vérifier l’image associée' }).click();
    await page.locator('.fu-publication-image img').waitFor();
    await capture('text-image-public-desktop', '[data-fans-publications]');
    await page.setViewportSize({ width: 390, height: 844 }); await capture('text-image-public-mobile', '[data-fans-publications]');
    // Association/detachment remains native with JS disabled.
    const native = await browser.newContext({ javaScriptEnabled: false });
    await native.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
    await native.addCookies([{ name: 'fixture_user', value: '17', url: base }]);
    const nojs = await native.newPage(); await nojs.goto(base + '/app/creator/creer?publication=' + publication);
    await nojs.getByLabel('Sans image — détacher la référence').check();
    await nojs.getByLabel('Je confirme ce choix et sa nouvelle modération.').check();
    await nojs.getByRole('button', { name: 'Soumettre le choix de l’image' }).click();
    await nojs.getByText('Demande traitée. État actuel : En attente de modération.').waitFor();
    assert.equal((await api('text-publications/' + publication)).status, 404);
    await native.close(); assert.deepEqual(errors, []);
    fs.writeFileSync(path.join(output, 'result.json'), JSON.stringify({ engine: await browser.version(), scope: 'Real native forms, services, SQL and GD; WordPress adapters, no site or SSO validation.', screenshots: 6,
      checks: ['native create', 'owner preview', 'association pending', 'public denied before approval', 'native contextual moderation', 'public JPEG reading', 'native no-JS detach', 'old public text revoked', 'no UUID presentation', 'no overflow or JS errors'] }, null, 2));
    console.log('PASS native text → approved image → moderation → public derivative → native detach; 6 captures');
  } finally { await context.close(); await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
