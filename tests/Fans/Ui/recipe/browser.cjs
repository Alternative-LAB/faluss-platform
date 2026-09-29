const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const launchBrowser = require('./browser-engine.cjs');

const base = process.env.FANS_UI_BASE || 'http://127.0.0.1:8765';
const output = process.env.FANS_UI_OUTPUT || path.resolve(__dirname, '../../../../docs/evidence/fans-ui-v2');
fs.mkdirSync(output, { recursive: true });

(async () => {
  const browser = await launchBrowser();
  try {
    const desktop = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 });
    await desktop.addCookies([{ name: 'fans_ui_role', value: 'fan', url: base }]);
    const fan = await desktop.newPage();
    await fan.goto(`${base}/faluss-fans/fan/explorer`);
    await fan.getByText('2 fiches publiques structurées. Découverte en préparation.').waitFor();
    assert.equal(await fan.locator('.fu-card').count(), 2);
    assert.equal(await fan.locator('.fu-nav__item[aria-current="page"]').count(), 1);
    assert.equal(await fan.locator('.fu-nav__item').count(), 6);
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

    await fan.getByRole('link', { name: 'Classement Fans', exact: true }).click();
    await fan.getByRole('heading', { name: 'Classement indisponible', exact: true }).waitFor();
    assert.equal(await fan.locator('[aria-current="page"]').getAttribute('aria-label'), 'Classement Fans');
    assert.equal(await fan.locator('table, form, progress, [data-api]').count(), 0);
    assert.doesNotMatch(await fan.locator('main').innerText(), /\bPC\b|€|Guest_|#\d/);
    await fan.screenshot({ path: path.join(output, 'classement-fans-desktop.png'), fullPage: true });

    await desktop.addCookies([{ name: 'fans_ui_role', value: 'guest', url: base }]);
    const publicExplorer = await fan.goto(`${base}/faluss-fans/fan/explorer`);
    assert.equal(publicExplorer.status(), 200);
    assert.equal(await fan.locator('.fu-nav__item').count(), 2);
    assert.equal(await fan.locator('.fu-app').getAttribute('data-fans-role'), 'visitor');
    assert.equal(await fan.locator('input[name="faluss_fans_return_to"]').inputValue(), '/faluss-fans/fan/explorer');
    await fan.screenshot({ path: path.join(output, 'invitation-sso-desktop.png'), fullPage: true });
    assert.equal((await fan.goto(`${base}/faluss-fans/fan/hof`)).status(), 200);
    assert.equal(await fan.locator('.fu-nav__item[aria-current="page"]').getAttribute('aria-label'), 'HoF');
    assert.equal((await fan.goto(`${base}/faluss-fans/fan/hof/session`)).status(), 403);
    assert.equal((await fan.goto(`${base}/faluss-fans/fan/classement-fans`)).status(), 403);
    const denied = await fan.goto(`${base}/faluss-fans/fan/accueil`);
    assert.equal(denied.status(), 403);
    assert.equal(await fan.locator('.fu-app').getAttribute('data-fans-role'), 'visitor');
    assert.equal(await fan.locator('input[name="faluss_fans_return_to"]').inputValue(), '/faluss-fans/fan/accueil');
    await fan.screenshot({ path: path.join(output, 'connexion-requise-desktop.png'), fullPage: true });
    const selectedText = '/faluss-fans/creator/creer?publication=123e4567-e89b-42d3-a456-426614174000';
    for (const [destination, expected] of [
      [selectedText, selectedText],
      [selectedText + '&nonce=discard', '/faluss-fans/creator/creer'],
      [selectedText + '&publication=123e4567-e89b-42d3-a456-426614174001', '/faluss-fans/creator/creer'],
      [selectedText.replace('publication=', 'publication[]='), '/faluss-fans/creator/creer'],
      [selectedText.replace('-42d3-', '-12d3-'), '/faluss-fans/creator/creer'],
      [selectedText.replace('creator/creer', 'fan/espace'), '/faluss-fans/fan/espace'],
    ]) {
      assert.equal((await fan.goto(base + destination)).status(), 403);
      assert.equal(await fan.locator('input[name="faluss_fans_return_to"]').inputValue(), expected);
      assert.equal(await fan.locator('textarea, [data-publications]').count(), 0);
    }
    assert.equal((await fan.goto(`${base}/faluss-fans/creators/123e4567-e89b-42d3-a456-426614174000`)).status(), 200);
    assert.equal((await fan.goto(`${base}/faluss-fans/creators/123e4567-e89b-42d3-a456-426614174099`)).status(), 404);
    await desktop.addCookies([{ name: 'fans_ui_role', value: 'fan', url: base }]);
    const creatorDenied = await fan.goto(`${base}/faluss-fans/creator/creer`);
    assert.equal(creatorDenied.status(), 404);
    assert.equal(await fan.locator('.fu-app[data-fans-role="fan"]').count(), 1);
    await fan.getByRole('link', { name: 'Retour à Explorer', exact: false }).waitFor();

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

    await mobileFan.goto(`${base}/faluss-fans/fan/classement-fans`);
    await mobileFan.getByRole('heading', { name: 'Classement indisponible', exact: true }).waitFor();
    assert.equal(await mobileFan.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true);
    const rankingNav = mobileFan.getByRole('link', { name: 'Classement Fans', exact: true });
    const rankingBox = await rankingNav.boundingBox();
    assert.ok(rankingBox.width >= 44 && rankingBox.height >= 44);
    await mobileFan.screenshot({ path: path.join(output, 'classement-fans-mobile.png') });
    await mobileFan.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
    await mobileFan.screenshot({ path: path.join(output, 'classement-fans-mobile-bottom.png') });

    const creator = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 });
    await creator.addCookies([{ name: 'fans_ui_role', value: 'creator', url: base }]);
    const creatorPage = await creator.newPage();
    await creatorPage.goto(`${base}/faluss-fans/creator/creer`);
    assert.equal(await creatorPage.locator('.fu-nav__item').count(), 8);
    assert.equal(await creatorPage.locator('.fu-nav__item[aria-current="page"]').getAttribute('aria-label'), 'Créer');
    assert.equal(await creatorPage.locator('.fu-choice').count(), 4);
    assert.equal(await creatorPage.locator('form').count(), 0);
    assert.equal(await creatorPage.locator('.fu-nav__item:not(.is-active) .fu-nav__label:visible').count(), 0);
    await creatorPage.getByRole('link', { name: 'Progression', exact: true }).focus();
    assert.equal(await creatorPage.getByRole('link', { name: 'Progression', exact: true }).locator('.fu-nav__label').isVisible(), false);
    await creatorPage.locator('#fu-main').focus();
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
    assert.equal((await creatorPage.goto(`${base}/faluss-fans/fan/classement-fans`)).status(), 200);
    assert.equal((await creatorPage.goto(`${base}/faluss-fans/creator/classement-fans`)).status(), 404);

    const creatorMobile = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 1, isMobile: true, hasTouch: true });
    await creatorMobile.addCookies([{ name: 'fans_ui_role', value: 'creator', url: base }]);
    const mobileCreator = await creatorMobile.newPage();
    await mobileCreator.goto(`${base}/faluss-fans/creator/creer`);
    assert.equal(await mobileCreator.locator('.fu-nav__item').count(), 8);
    for (const width of [320, 390]) {
      await mobileCreator.setViewportSize({ width, height: 844 });
      for (const label of ['Accueil', 'Explorer', 'HoF', 'Messages', 'Créer', 'Ma boutique', 'Progression', 'Mon profil']) {
        const item = mobileCreator.getByRole('link', { name: label, exact: true });
        const box = await item.boundingBox();
        assert.ok(box.width >= 44 && box.height >= 44, `Touch target ${label}`);
        assert.equal(await item.evaluate(element => {
          const box = element.getBoundingClientRect();
          return document.elementFromPoint(box.x + box.width / 2, box.y + box.height / 2)?.closest('a') === element;
        }), true, `Unobstructed target ${label}`);
        const destination = await item.getAttribute('href');
        await Promise.all([mobileCreator.waitForURL(destination), item.tap()]);
        assert.equal(await mobileCreator.locator('.fu-nav__item[aria-current="page"]').getAttribute('aria-label'), label);
        assert.equal(await mobileCreator.locator('.fu-nav__item:not(.is-active) .fu-nav__label:visible').count(), 0);
      }
    }
    await mobileCreator.goto(`${base}/faluss-fans/creator/creer`);
    assert.equal(await mobileCreator.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true);
    for (const label of ['Accueil', 'Explorer', 'HoF', 'Messages', 'Créer', 'Ma boutique', 'Progression', 'Mon profil']) {
      const item = mobileCreator.getByRole('link', { name: label, exact: true });
      assert.equal(await item.count(), 1, label);
      assert.equal(await item.locator('.fu-nav__label').isVisible(), label === 'Créer', label);
    }
    await mobileCreator.screenshot({ path: path.join(output, 'creer-mobile.png') });
    await mobileCreator.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
    assert.equal(await mobileCreator.evaluate(() => window.scrollY > 0), true);
    await mobileCreator.screenshot({ path: path.join(output, 'creer-mobile-bottom.png') });
    await mobileCreator.setViewportSize({ width: 320, height: 640 });
    await mobileCreator.emulateMedia({ reducedMotion: 'reduce' });
    assert.equal(await mobileCreator.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true);
    assert.equal(await mobileCreator.locator('.fu-nav__item').count(), 8);
    for (const role of ['unlinked', 'admin']) {
      await desktop.addCookies([{ name: 'fans_ui_role', value: role, url: base }]);
      assert.equal((await fan.goto(`${base}/faluss-fans/fan/classement-fans`)).status(), 403, role);
      assert.equal(await fan.getByRole('button', { name: 'Continuer avec Faluss', exact: true }).count(), role === 'admin' ? 0 : 1);
    }
    await mobile.addCookies([{ name: 'fans_ui_role', value: 'guest', url: base }]);
    assert.equal((await mobileFan.goto(`${base}/faluss-fans/fan/classement-fans`)).status(), 403);
    assert.equal(await mobileFan.locator('input[name="faluss_fans_return_to"]').inputValue(), '/faluss-fans/fan/classement-fans');
    assert.equal(await mobileFan.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true);
    await mobileFan.screenshot({ path: path.join(output, 'connexion-requise-mobile.png') });
    assert.equal((await mobileFan.goto(base + selectedText)).status(), 403);
    assert.equal(await mobileFan.locator('input[name="faluss_fans_return_to"]').inputValue(), selectedText);
    console.log('Synthetic browser recipe passed: public guest discovery, desktop/mobile, keyboard focus, private permissions, empty/error boundaries, eight creator links.');
  } finally {
    await browser.close();
  }
})().catch((error) => { console.error(error); process.exitCode = 1; });
