const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const launchBrowser = require('./browser-engine.cjs');
const base = process.env.FANS_UI_BASE || 'http://127.0.0.1:8765';
const output = process.env.FANS_UI_OUTPUT;
if (!output) throw new Error('Choose a temporary FANS_UI_OUTPUT directory for the visual review.');
fs.mkdirSync(output, { recursive: true });
const profile = 'creators/123e4567-e89b-42d3-a456-426614174000';
const screens = {
  fan: [
    ['01-hof', 'HoF', 'fan/hof'], ['02-session', 'Session HoF', 'fan/hof/session'],
    ['03-classements', 'Classements HoF', 'fan/classements'], ['04-profil', 'Profil public', profile],
    ['05-messages', 'Messages', 'fan/messages'], ['06-accueil', 'Accueil', 'fan/accueil'],
    ['07-explorer', 'Explorer', 'fan/explorer'], ['08-espace', 'Mon espace', 'fan/espace'],
    ['09-classement-fans', 'Classement Fans', 'fan/classement-fans'],
  ],
  creator: [
    ['01-hof', 'HoF', 'creator/hof'], ['02-session', 'Session HoF', 'creator/hof/session'],
    ['03-classements', 'Classements HoF', 'creator/classements'], ['04-profil', 'Profil public', profile],
    ['05-messages', 'Messages', 'creator/messages'], ['06-accueil', 'Accueil', 'creator/accueil'],
    ['07-explorer', 'Explorer', 'creator/explorer'], ['08-progression', 'Progression', 'creator/progression'],
    ['09-creer', 'Créer', 'creator/creer'], ['10-boutique', 'Ma boutique', 'creator/boutique'],
    ['11-mon-profil', 'Mon profil', 'creator/mon-profil'],
  ],
};

(async () => {
  const browser = await launchBrowser();
  const captures = [];
  try {
    for (const [role, views] of Object.entries(screens)) {
      for (const width of [1440, 390]) {
        const height = width === 1440 ? 900 : 844;
        const context = await browser.newContext({ viewport: { width, height }, isMobile: width === 390, hasTouch: width === 390 });
        await context.addCookies([
          { name: 'fans_ui_role', value: role, url: base },
          { name: 'fans_ui_author', value: 'open', url: base },
          { name: 'fans_ui_admission', value: 'open', url: base },
        ]);
        const page = await context.newPage();
        for (const [key, title, route] of views) {
          assert.equal((await page.goto(`${base}/faluss-fans/${route}`)).status(), 200, route);
          await page.evaluate(() => document.fonts.ready);
          if (await page.locator('[data-fans-publications]').count()) {
            await page.getByText('Publications chargées.', { exact: false }).waitFor();
          }
          if (await page.locator('[data-fans-profile]').count()) {
            await page.getByText('Nombre de suivis indisponible.', { exact: true }).waitFor();
          }
          assert.equal(await page.locator('.fu-nav__item').count(), role === 'creator' ? 8 : 6);
          assert.equal(await page.locator('.fu-nav__item[aria-current="page"]').count(), 1);
          assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true);
          const file = `${role}-${key}-${width}.png`;
          await page.screenshot({ path: path.join(output, file) });
          captures.push({ role, title, route, width, height, file });
        }
        await context.close();
      }
    }
    // Contact sheets contain only scaled screenshots, with evidence labels outside the UI.
    const review = await browser.newPage();
    for (const role of Object.keys(screens)) {
      for (const width of [1440, 390]) {
        const items = captures.filter(item => item.role === role && item.width === width);
        const columns = 3;
        const thumbnail = width === 1440 ? 440 : 260;
        await review.setViewportSize({ width: columns * (thumbnail + 16) + 16, height: 900 });
        await review.setContent(`<html lang="fr"><style>body{margin:16px;background:#202323;color:white;font:16px system-ui}h1{font-size:20px}section{display:grid;grid-template-columns:repeat(3,${thumbnail}px);gap:16px}figure{margin:0}img{width:100%;display:block}figcaption{padding:8px 0}</style><h1>Recette isolée — ${role} — ${width} px — données de test</h1><section>${items.map(item => `<figure><img src="data:image/png;base64,${fs.readFileSync(path.join(output, item.file)).toString('base64')}"><figcaption>${item.title}</figcaption></figure>`).join('')}</section></html>`);
        await review.evaluate(() => Promise.all([...document.images].map(img => img.decode())));
        await review.screenshot({ path: path.join(output, `overview-${role}-${width}.png`), fullPage: true });
      }
    }
    fs.writeFileSync(path.join(output, 'screens.json'), JSON.stringify(captures, null, 2) + '\n');
    console.log(`${captures.length} current UI captures and four contact sheets prepared for visual inspection; fixtures only.`);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
