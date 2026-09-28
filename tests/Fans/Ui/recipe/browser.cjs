const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const base = 'http://127.0.0.1:8765';
const output = path.resolve(__dirname, '../../../../docs/evidence/fans-ui-v2');
fs.mkdirSync(output, { recursive: true });

(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    const desktop = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 });
    await desktop.addCookies([{ name: 'fans_ui_role', value: 'fan', url: base }]);
    const fan = await desktop.newPage();
    await fan.goto(`${base}/faluss-fans/fan/explorer`);
    await fan.getByText('2 fiches publiques structurées. Découverte en préparation.').waitFor();
    assert.equal(await fan.locator('.fu-card').count(), 2);
    assert.equal(await fan.locator('.fu-nav__item[aria-current="page"]').count(), 1);
    assert.equal(await fan.locator('.fu-nav__item').count(), 5);
    await fan.locator('.fu-nav__item').first().focus();
    assert.notEqual(await fan.locator('.fu-nav__item').first().evaluate((node) => getComputedStyle(node).outlineStyle), 'none');
    await fan.locator('#fu-main').focus();
    await fan.screenshot({ path: path.join(output, 'explorer-desktop.png'), fullPage: true });

    await fan.getByRole('button', { name: 'Arts' }).click();
    await fan.getByText('1 fiche publique structurée. Découverte en préparation.').waitFor();
    assert.equal(await fan.locator('.fu-card').count(), 1);
    await fan.locator('.fu-card__link').click();
    await fan.getByText('Profil public chargé.').waitFor();
    assert.equal(await fan.locator('.fu-profile').count(), 1);
    await fan.screenshot({ path: path.join(output, 'profil-public-desktop.png'), fullPage: true });

    await desktop.addCookies([{ name: 'fans_ui_role', value: 'guest', url: base }]);
    const publicExplorer = await fan.goto(`${base}/faluss-fans/fan/explorer`);
    assert.equal(publicExplorer.status(), 200);
    assert.equal(await fan.locator('.fu-nav__item').count(), 2);
    assert.equal(await fan.locator('.fu-app').getAttribute('data-fans-role'), 'visitor');
    assert.equal((await fan.goto(`${base}/faluss-fans/fan/hof`)).status(), 200);
    assert.equal(await fan.locator('.fu-nav__item[aria-current="page"]').getAttribute('aria-label'), 'HoF');
    assert.equal((await fan.goto(`${base}/faluss-fans/fan/hof/session`)).status(), 403);
    const denied = await fan.goto(`${base}/faluss-fans/fan/accueil`);
    assert.equal(denied.status(), 403);
    assert.equal(await fan.locator('.fu-app').count(), 0);
    assert.equal((await fan.goto(`${base}/faluss-fans/creators/123e4567-e89b-42d3-a456-426614174000`)).status(), 200);
    assert.equal((await fan.goto(`${base}/faluss-fans/creators/123e4567-e89b-42d3-a456-426614174099`)).status(), 404);
    await desktop.addCookies([{ name: 'fans_ui_role', value: 'fan', url: base }]);
    const creatorDenied = await fan.goto(`${base}/faluss-fans/creator/creer`);
    assert.equal(creatorDenied.status(), 404);
    assert.equal(await fan.locator('.fu-app').count(), 0);

    const mobile = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 1, isMobile: true, hasTouch: true });
    await mobile.addCookies([
      { name: 'fans_ui_role', value: 'fan', url: base },
      { name: 'fans_ui_data', value: 'empty', url: base }
    ]);
    const mobileFan = await mobile.newPage();
    await mobileFan.goto(`${base}/faluss-fans/fan/explorer`);
    await mobileFan.getByText('Aucun profil créateur publié dans cette catégorie.').waitFor();
    assert.equal(await mobileFan.locator('.fu-card').count(), 0);
    assert.equal(await mobileFan.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true);
    await mobileFan.screenshot({ path: path.join(output, 'explorer-mobile-empty.png') });
    await mobile.addCookies([{ name: 'fans_ui_data', value: 'error', url: base }]);
    await mobileFan.reload();
    await mobileFan.getByText('Les profils sont indisponibles pour le moment. Réessayez plus tard.').waitFor();
    assert.equal(await mobileFan.locator('.fu-card').count(), 0);
    assert.equal(await mobileFan.locator('.fu-empty').count(), 1);

    const creator = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 });
    await creator.addCookies([{ name: 'fans_ui_role', value: 'creator', url: base }]);
    const creatorPage = await creator.newPage();
    await creatorPage.goto(`${base}/faluss-fans/creator/creer`);
    assert.equal(await creatorPage.locator('.fu-nav__item').count(), 8);
    assert.equal(await creatorPage.locator('.fu-nav__item[aria-current="page"]').getAttribute('aria-label'), 'Créer');
    assert.equal(await creatorPage.locator('.fu-choice').count(), 4);
    assert.equal(await creatorPage.locator('form').count(), 0);
    await creatorPage.screenshot({ path: path.join(output, 'creer-desktop.png'), fullPage: true });
    for (const href of ['accueil', 'explorer', 'hof', 'hof/session', 'classements', 'messages', 'creer', 'boutique', 'progression', 'mon-profil']) {
      const response = await creatorPage.goto(`${base}/faluss-fans/creator/${href}`);
      assert.equal(response.status(), 200, href);
      assert.equal(await creatorPage.locator('.fu-nav__item').count(), 8, href);
      assert.equal(await creatorPage.locator('.fu-nav__item[aria-current="page"]').count(), 1, href);
    }
    await creatorPage.goto(`${base}/faluss-fans/creators/123e4567-e89b-42d3-a456-426614174000`);
    await creatorPage.getByText('Profil public chargé.').waitFor();
    assert.equal(await creatorPage.locator('.fu-back').getAttribute('href'), `${base}/faluss-fans/creator/explorer`);

    const creatorMobile = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 1, isMobile: true, hasTouch: true });
    await creatorMobile.addCookies([{ name: 'fans_ui_role', value: 'creator', url: base }]);
    const mobileCreator = await creatorMobile.newPage();
    await mobileCreator.goto(`${base}/faluss-fans/creator/creer`);
    assert.equal(await mobileCreator.locator('.fu-nav__item').count(), 8);
    assert.equal(await mobileCreator.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true);
    for (const label of ['Accueil', 'Explorer', 'HoF', 'Messages', 'Créer', 'Ma boutique', 'Progression', 'Mon profil']) {
      const item = mobileCreator.getByRole('link', { name: label, exact: true });
      assert.equal(await item.count(), 1, label);
      assert.equal(await item.locator('.fu-nav__label').isVisible(), true, label);
    }
    await mobileCreator.screenshot({ path: path.join(output, 'creer-mobile.png') });
    await mobileCreator.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
    assert.equal(await mobileCreator.evaluate(() => window.scrollY > 0), true);
    await mobileCreator.screenshot({ path: path.join(output, 'creer-mobile-bottom.png') });
    console.log('Synthetic browser recipe passed: public guest discovery, desktop/mobile, keyboard focus, private permissions, empty/error boundaries, eight creator links.');
  } finally {
    await browser.close();
  }
})().catch((error) => { console.error(error); process.exitCode = 1; });
