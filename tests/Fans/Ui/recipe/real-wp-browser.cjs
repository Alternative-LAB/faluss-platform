const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const fixture = JSON.parse(fs.readFileSync(0, 'utf8'));
const base = 'https://127.0.0.1:8444';
const output = path.resolve(__dirname, '../../../../docs/evidence/fans-ui-v2');
fs.mkdirSync(output, { recursive: true });
const uuid = /[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/i;

async function login(page, member) {
  await page.goto(`${base}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  assert.equal(new URL(await page.locator('#loginform').getAttribute('action')).origin, base, 'Local WordPress login must keep the HTTPS proxy origin');
  await page.locator('#user_login').fill(member.login);
  await page.locator('#user_pass').fill(member.password);
  await page.locator('#wp-submit').click();
  try {
    await page.waitForURL(/wp-admin/, { waitUntil: 'domcontentloaded' });
  } catch (error) {
    const notice = await page.locator('body').innerText();
    throw new Error(`Local recipe login did not reach wp-admin: ${new URL(page.url()).pathname}; ${notice.slice(0, 600)}`, { cause: error });
  }
}

async function status(page, route, expected) {
  let response;
  for (let attempt = 0; attempt < 12; attempt += 1) {
    try {
      response = await page.goto(`${base}${route}`, { waitUntil: 'domcontentloaded' });
      break;
    } catch (error) {
      if (!String(error).includes('ERR_CONNECTION_REFUSED') || attempt === 11) throw error;
      await new Promise((resolve) => setTimeout(resolve, 500));
    }
  }
  assert.equal(response.status(), expected, route);
  return response;
}

async function publicChecks(page, expectedRole) {
  await status(page, '/app/fan/explorer', 200);
  await page.getByText('1 fiche publique structurée. Découverte en préparation.').waitFor();
  assert.equal(await page.locator('.fu-app').getAttribute('data-fans-role'), expectedRole);
  assert.equal(await page.locator('.fu-card').count(), 1);
  assert.equal(uuid.test(await page.locator('body').innerText()), false);
  assert.equal(uuid.test(await page.locator('.fu-card__link').getAttribute('aria-label')), false);
  assert.equal(await page.locator('.fu-app img').count(), 0);
  assert.equal(await page.locator('.fu-discovery-note').isVisible(), true);
  const style = await page.locator('.fu-app').evaluate((node) => getComputedStyle(node).display);
  assert.ok(['grid', 'block'].includes(style), 'Fans UI stylesheet applied');
  await page.locator('.fu-card__link').click();
  assert.equal(new URL(page.url()).pathname, `/app/creators/${fixture.active}`);
  await page.getByText('Profil public chargé.').waitFor();
  assert.equal(uuid.test(await page.locator('body').innerText()), false);
  assert.equal(await page.locator('.fu-app img').count(), 0);
  assert.equal(await page.locator('.fu-profile').count(), 1);
}

async function noAdminBar(page) {
  assert.equal(await page.locator('#wpadminbar').count(), 0);
  assert.equal(await page.locator('body.admin-bar').count(), 0);
}

(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    const desktop = { viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1, ignoreHTTPSErrors: true };
    const guest = await browser.newContext(desktop);
    const guestPage = await guest.newPage();
    await publicChecks(guestPage, 'visitor');
    assert.equal(await guestPage.locator('.fu-nav__item').count(), 2);
    await guestPage.screenshot({ path: path.join(output, 'real-wp-profile-guest-desktop.png'), fullPage: true });
    await status(guestPage, '/app/fan/explorer', 200);
    await guestPage.getByText('1 fiche publique structurée. Découverte en préparation.').waitFor();
    await guestPage.screenshot({ path: path.join(output, 'real-wp-explorer-guest-desktop.png'), fullPage: true });
    await status(guestPage, '/app/creator/explorer', 200);
    assert.equal(await guestPage.locator('.fu-nav__item').count(), 2);
    await status(guestPage, '/app/fan/hof', 200);
    assert.equal(await guestPage.locator('.fu-app').getAttribute('data-fans-role'), 'visitor');
    assert.equal(await guestPage.locator('.fu-nav__item').count(), 2);
    assert.equal(await guestPage.locator('.fu-nav__item[aria-current="page"]').getAttribute('aria-label'), 'HoF');
    await guestPage.locator('.fu-panel--status').getByText('Aucune session, aucun rang ni aucun point ne peut être affiché.').waitFor();
    assert.equal(await guestPage.locator('[data-fans-results]').count(), 0);
    await guestPage.screenshot({ path: path.join(output, 'real-wp-hof-guest-desktop.png'), fullPage: true });
    await status(guestPage, '/app/creator/hof', 200);
    assert.equal(await guestPage.locator('.fu-nav__item').count(), 2);
    await status(guestPage, '/app/fan/explorer/', 200);
    await status(guestPage, '/app/creator/hof/', 200);
    await status(guestPage, `/app/creators/${fixture.active}/`, 200);
    await status(guestPage, '/app/fan/hof/session/', 403);
    await status(guestPage, '/app/fan/accueil/', 403);
    await status(guestPage, '/app/fan/hof/session', 403);
    await status(guestPage, '/app/fan/classements', 403);
    await status(guestPage, '/app/fan/accueil', 403);
    await status(guestPage, '/app/creator/creer', 403);
    for (const key of ['suspended', 'withdrawn', 'unknown']) {
      await status(guestPage, `/app/creators/${fixture[key]}`, 404);
      const rest = await guestPage.request.get(`${base}/wp-json/faluss-fans/v1/creators/${fixture[key]}`);
      assert.equal(rest.status(), 404, `REST ${key}`);
    }
    assert.equal((await guestPage.request.get(`${base}/wp-json/faluss-fans/v1/creators`)).status(), 200);
    assert.equal((await guestPage.request.get(`${base}/wp-content/plugins/faluss-platform/assets/fans-ui-v2.css`)).status(), 200);
    console.log('PASS guest: Explorer and active profile 200; private pages 403; missing, suspended and withdrawn pages and REST 404.');

    const unlinked = await browser.newContext(desktop);
    const unlinkedPage = await unlinked.newPage();
    await login(unlinkedPage, fixture.unlinked);
    await publicChecks(unlinkedPage, 'visitor');
    await noAdminBar(unlinkedPage);
    await status(unlinkedPage, '/app/fan/hof', 200);
    await noAdminBar(unlinkedPage);
    await status(unlinkedPage, '/app/fan/accueil', 403);
    console.log('PASS unlinked WordPress user: public discovery 200, personal page 403.');

    const fan = await browser.newContext(desktop);
    const fanPage = await fan.newPage();
    await login(fanPage, fixture.fan);
    await publicChecks(fanPage, 'fan');
    await noAdminBar(fanPage);
    assert.equal(await fanPage.locator('.fu-nav__item').count(), 6);
    await status(fanPage, '/app/fan/accueil', 200);
    await noAdminBar(fanPage);
    await status(fanPage, '/app/fan/hof', 200);
    await noAdminBar(fanPage);
    await fanPage.screenshot({ path: path.join(output, 'real-wp-hof-fan-desktop.png'), fullPage: true });
    await status(fanPage, '/app/fan/accueil/', 200);
    await status(fanPage, '/app/creator/creer', 404);
    await status(fanPage, '/app/creator/creer/', 404);
    await status(fanPage, '/app/creator/explorer', 200);
    assert.equal(await fanPage.locator('.fu-app').getAttribute('data-fans-role'), 'fan');
    await status(fanPage, '/', 200);
    assert.equal(await fanPage.locator('#wpadminbar').count(), 1, 'Other WordPress pages keep the normal toolbar preference');
    console.log('PASS linked Fan: public discovery and personal page 200, creator management 404.');

    const creator = await browser.newContext(desktop);
    const creatorPage = await creator.newPage();
    await login(creatorPage, fixture.creator);
    await publicChecks(creatorPage, 'creator');
    await noAdminBar(creatorPage);
    assert.equal(await creatorPage.locator('.fu-nav__item').count(), 8);
    await status(creatorPage, '/app/creator/creer', 200);
    await noAdminBar(creatorPage);
    assert.equal(await creatorPage.locator('.fu-choice').count(), 4);
    await status(creatorPage, '/app/creator/creer/', 200);
    await noAdminBar(creatorPage);
    await status(creatorPage, '/app/creator/explorer', 200);
    await creatorPage.getByText('1 fiche publique structurée. Découverte en préparation.').waitFor();
    await noAdminBar(creatorPage);
    await creatorPage.screenshot({ path: path.join(output, 'real-wp-explorer-creator-desktop.png'), fullPage: true });
    console.log('PASS linked Creator: public discovery and creator management 200, eight navigation links.');

    const mobile = { viewport: { width: 390, height: 844 }, deviceScaleFactor: 1, isMobile: true, hasTouch: true, ignoreHTTPSErrors: true };
    const guestMobile = await browser.newContext(mobile);
    const guestMobilePage = await guestMobile.newPage();
    await publicChecks(guestMobilePage, 'visitor');
    assert.equal(await guestMobilePage.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true);
    await guestMobilePage.screenshot({ path: path.join(output, 'real-wp-profile-guest-mobile.png'), fullPage: true });
    await status(guestMobilePage, '/app/fan/explorer', 200);
    await guestMobilePage.getByText('1 fiche publique structurée. Découverte en préparation.').waitFor();
    await guestMobilePage.screenshot({ path: path.join(output, 'real-wp-explorer-guest-mobile.png'), fullPage: true });
    await status(guestMobilePage, '/app/fan/hof', 200);
    assert.equal(await guestMobilePage.locator('.fu-nav__item').count(), 2);
    assert.equal(await guestMobilePage.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true);
    await guestMobilePage.screenshot({ path: path.join(output, 'real-wp-hof-guest-mobile.png'), fullPage: true });

    const creatorMobile = await browser.newContext(mobile);
    const creatorMobilePage = await creatorMobile.newPage();
    await login(creatorMobilePage, fixture.creator);
    await status(creatorMobilePage, '/app/creator/creer', 200);
    await noAdminBar(creatorMobilePage);
    assert.equal(await creatorMobilePage.locator('.fu-nav__item').count(), 8);
    assert.equal(await creatorMobilePage.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true);
    await creatorMobilePage.screenshot({ path: path.join(output, 'real-wp-creer-creator-mobile.png'), fullPage: true });
    const admin = await browser.newContext(desktop);
    const adminPage = await admin.newPage();
    await login(adminPage, fixture.admin);
    await status(adminPage, '/app/fan/hof', 200);
    assert.equal(await adminPage.locator('#wpadminbar').count(), 1);
    assert.equal(await adminPage.locator('#wp-admin-bar-site-name').isVisible(), true);
    await adminPage.screenshot({ path: path.join(output, 'real-wp-hof-admin-desktop.png'), fullPage: true });
    await status(adminPage, '/app/creator/creer', 403);
    const adminMobile = await browser.newContext(mobile);
    const adminMobilePage = await adminMobile.newPage();
    await login(adminMobilePage, fixture.admin);
    await status(adminMobilePage, '/app/fan/hof/', 200);
    assert.equal(await adminMobilePage.locator('#wpadminbar').isVisible(), true);
    assert.equal(await adminMobilePage.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true);
    await adminMobilePage.screenshot({ path: path.join(output, 'real-wp-hof-admin-mobile.png'), fullPage: true });
    console.log('PASS Chrome desktop/mobile: stylesheet, no visible UUID or invented portrait, Explorer to public profile, no horizontal overflow.');
    console.log('PASS HoF guest desktop/mobile and admin bar: ordinary members hidden on Fans pages, administrators retain tools.');
    console.log('PASS real WordPress: guest, unlinked user, linked Fan, linked Creator; page and REST 404; assets; desktop/mobile Explorer to profile.');
  } finally {
    await browser.close();
  }
})().catch((error) => { console.error(error); process.exitCode = 1; });
