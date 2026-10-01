const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const launch = require('../../Ui/recipe/browser-engine.cjs');
const base = 'http://127.0.0.1:8768';
const creator = '11111111-1111-4111-8111-111111111111';
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
  const api = async (route, data) => {
    const response = await context.request.fetch(`${base}/wp-json/faluss-fans/v1/${route}`, {
      method: data ? 'POST' : 'GET', headers: { 'X-WP-Nonce': 'fixture-wp_rest' }, ...(data ? { data } : {})
    });
    return { status: response.status(), data: await response.json() };
  };
  const capture = async (name, selector) => {
    await page.evaluate(() => document.fonts.ready);
    await page.evaluate(() => document.activeElement?.blur());
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, 'no overflow');
    if (selector) await page.locator(selector).screenshot({ path: path.join(output, name + '.png') });
    else { await page.evaluate(() => scrollTo(0, 0)); await page.screenshot({ path: path.join(output, name + '.png'), fullPage: true }); }
  };
  try {
    await role(17); await page.goto(base + '/app/creator/images');
    const previousIds = (await api('images?scope=live')).data.items.map(x => x.image_id);
    // A synthetic abstract raster, sent through PHP multipart, never seeded as an image row.
    const data = await page.evaluate(() => {
      const c = document.createElement('canvas'); c.width = c.height = 400;
      const g = c.getContext('2d'); g.fillStyle = '#0e3029'; g.fillRect(0, 0, 400, 400);
      g.fillStyle = '#30c29a'; g.fillRect(90, 70, 220, 220);
      g.fillStyle = '#f3ede2'; g.font = '18px sans-serif'; g.fillText('IMAGE SYNTHÉTIQUE · TEST', 55, 345);
      return c.toDataURL('image/png').split(',')[1];
    });
    const upload = { name: 'not-a-public-name.png', mimeType: 'image/png', buffer: Buffer.from(data, 'base64') };
    await page.locator('#fu-image-file').setInputFiles(upload);
    await page.getByRole('button', { name: 'Déposer mon image en privé' }).click();
    await page.getByText('Action confirmée. Consultez l’état actuel dans la galerie.').waitFor();
    const items = (await api('images?scope=live')).data.items;
    const image = items.find(x => !previousIds.includes(x.image_id));
    assert.ok(image);
    const card = page.locator('.fu-image-card').filter({ has: page.locator(`input[name="image_id"][value="${image.image_id}"]`) });
    await card.getByRole('button', { name: 'Examiner cette image' }).click();
    await card.locator('img').waitFor();
    assert.doesNotMatch(await page.locator('#fu-images').innerText(), /[0-9a-f]{8}-[0-9a-f]{4}-|not-a-public-name/);
    await capture('images-desktop', '#fu-images');
    await page.setViewportSize({ width: 390, height: 844 }); await capture('images-mobile', '#fu-images');
    await capture('create-mobile');
    await page.locator('#fu-image-file').setInputFiles(upload);
    await page.getByRole('button', { name: 'Déposer mon image en privé' }).click();
    await page.getByText('Cette image est déjà dans votre galerie. Aucun nouveau dépôt n’a été créé.').waitFor();
    assert.equal((await api('images?scope=live')).data.items.length, items.length);
    assert.equal((await api('images/portraits')).data.items.some(x => x.image_id === image.image_id), false);
    await role(1); await page.goto(base + '/admin.php?page=faluss-fans-moderation&view=images');
    const moderation = page.locator('.fm-card').filter({ has: page.locator(`input[name="item_id"][value="${image.image_id}"]`) });
    await moderation.getByRole('button', { name: 'Examiner l’image privée' }).click();
    await moderation.locator('img').waitFor();
    await capture('image-moderation-mobile');
    await page.setViewportSize({ width: 1440, height: 1000 }); await capture('image-moderation-desktop');
    await moderation.getByLabel('Décision et motif').selectOption('allowed_image');
    await moderation.getByRole('button', { name: 'Confirmer cette décision' }).click();
    await page.getByText('Décision confirmée par le serveur et journalisée.').waitFor();
    await role(17); await page.goto(base + '/app/creator/mon-profil');
    await page.locator('#fu-public-name').fill('Atelier synthétique · recette Images');
    await page.locator('#fu-bio').fill('Données de test isolées. Portrait déposé depuis le formulaire Créer.');
    await page.locator(`input[type="radio"][value^="${image.image_id}"]`).check();
    await page.getByRole('button', { name: 'Soumettre à la modération' }).click();
    await page.getByText('Action confirmée. L’état courant est affiché ci-dessous.').waitFor();
    assert.equal((await api('creators/' + creator)).data.editorial, null);
    await role(1); await page.goto(base + '/admin.php?page=faluss-fans-moderation&view=editorial');
    await page.getByLabel('Décision et motif').selectOption('allowed_editorial');
    await page.getByRole('button', { name: 'Confirmer cette décision' }).click();
    await page.getByText('Décision confirmée et journalisée.').waitFor();
    await role(0); await page.goto(base + '/app/fan/explorer');
    await page.getByRole('heading', { name: 'Atelier synthétique · recette Images' }).waitFor();
    await page.locator(`.fu-card__link[href$="/creators/${creator}"]`).click();
    await page.locator('.fu-profile__glyph img').waitFor();
    await capture('portrait-from-upload-desktop');
    await page.setViewportSize({ width: 390, height: 844 }); await capture('portrait-from-upload-mobile');
    await role(17); await page.goto(base + '/app/creator/images');
    await card.getByRole('checkbox').check(); await card.getByRole('button', { name: 'Retirer cette image', exact: true }).click();
    await page.getByText('Action confirmée. Consultez l’état actuel dans la galerie.').waitFor();
    assert.equal((await api('creators/' + creator)).data.editorial.portrait, false);
    await page.getByRole('link', { name: 'Retraits et refus', exact: true }).click();
    await page.getByText('Image retirée', { exact: true }).first().waitFor();
    await capture('withdrawn-mobile', '#fu-images');
    await page.evaluate(() => dispatchEvent(new PageTransitionEvent('pagehide')));
    assert.equal(await page.locator('[data-fans-private-reading]').evaluateAll(els => els.every(el => !el.textContent)), true);
    // Native upload and withdrawal must also work with JavaScript disabled.
    const native = await browser.newContext({ javaScriptEnabled: false });
    await native.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
    await native.addCookies([{ name: 'fixture_user', value: '17', url: base }]);
    const nojs = await native.newPage(); await nojs.goto(base + '/app/creator/images');
    const beforeNative = (await api('images?scope=live')).data.items.map(x => x.image_id);
    await nojs.locator('#fu-image-file').setInputFiles(upload);
    await nojs.getByRole('button', { name: 'Déposer mon image en privé' }).click();
    await nojs.getByText('Action confirmée. Consultez l’état actuel dans la galerie.').waitFor();
    const last = (await api('images?scope=live')).data.items.find(x => !beforeNative.includes(x.image_id));
    const nativeCard = nojs.locator('.fu-image-card').filter({ has: nojs.locator(`input[name="image_id"][value="${last.image_id}"]`) });
    await nativeCard.getByRole('checkbox').check(); await nativeCard.getByRole('button', { name: 'Retirer cette image', exact: true }).click();
    await nojs.getByText('Action confirmée. Consultez l’état actuel dans la galerie.').waitFor(); await native.close();
    assert.deepEqual(errors, []);
    fs.writeFileSync(path.join(output, 'result.json'), JSON.stringify({ engine: await browser.version(), scope: 'Actual native multipart, services, InnoDB and GD. WordPress/session adapters; no WordPress site or real SSO.', screenshots: 8,
      checks: ['native upload', 'private owner preview', 'retry no duplicate', 'no preapproval portrait', 'native image moderation', 'portrait selection', 'editorial moderation', 'Explorer → profile', 'withdraw revokes portrait', 'closed gallery', 'upload and withdraw without JS', 'pagehide clears private content', 'no overflow or JS errors'] }, null, 2));
    console.log('PASS private image → moderation → portrait → withdrawal; native no-JS forms; 8 captures');
  } finally { await context.close(); await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
