const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const launchBrowser = require('./browser-engine.cjs');
const base = process.env.FANS_UI_BASE || 'http://127.0.0.1:8765';
const output = process.env.FANS_UI_OUTPUT || path.resolve(__dirname, '../../../../docs/evidence/fans-48h/lot-5');
fs.mkdirSync(output, { recursive: true });
const routes = {
  fan: ['accueil', 'explorer', 'hof', 'hof/session', 'classements', 'classement-fans', 'messages', 'espace'],
  creator: ['accueil', 'explorer', 'hof', 'hof/session', 'classements', 'messages', 'progression', 'creer', 'boutique', 'mon-profil']
};
(async () => {
  const browser = await launchBrowser();
  const evidence = [];
  try {
    for (const width of [1440, 390]) {
      const context = await browser.newContext({ viewport: { width, height: width === 1440 ? 900 : 844 } });
      const page = await context.newPage();
      for (const role of ['guest', 'fan', 'creator', 'admin']) {
        await context.addCookies([{ name: 'fans_ui_role', value: role, url: base }]);
        for (const [shell, views] of Object.entries(routes)) {
          for (const view of views) {
            const publicView = ['explorer', 'hof'].includes(view);
            const linked = ['fan', 'creator'].includes(role);
            const expected = publicView ? 200 : (!linked ? 403 : (shell === 'creator' && role !== 'creator' ? 404 : 200));
            const response = await page.goto(`${base}/faluss-fans/${shell}/${view}`);
            assert.equal(response.status(), expected, `${role} ${shell}/${view}`);
            const slashResponse = await page.goto(`${base}/faluss-fans/${shell}/${view}/`);
            assert.equal(slashResponse.status(), expected, `${role} ${shell}/${view}/`);
            assert.match(response.headers()['cache-control'], /no-store/);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, `${width} ${role} ${view}`);
            const navRole = await page.locator('.fu-app').getAttribute('data-fans-role');
            assert.equal(await page.locator('.fu-nav__item').count(), navRole === 'creator' ? 8 : (navRole === 'fan' ? 6 : 2));
            if (navRole === 'creator') {
              assert.equal(await page.locator('.fu-nav__item:not(.is-active) .fu-nav__label:visible').count(), 0);
              assert.doesNotMatch(await page.locator('main').innerText(), /wallet|solde|€|\bPC\b|Guest_/i);
            }
            if (['hof', 'hof/session', 'classements', 'progression', 'classement-fans'].includes(view)) {
              assert.equal(await page.locator('main table, main progress, main [data-api]').count(), 0);
              assert.doesNotMatch(await page.locator('main').innerText(), /#\d|\d+\s*(PF|PC|€)/);
            }
            evidence.push({ width, role, route: `${shell}/${view}`, status: response.status(), navRole });
            evidence.push({ width, role, route: `${shell}/${view}/`, status: slashResponse.status(), navRole });
            if ((role === 'guest' && shell === 'fan' && view === 'hof')
              || (role === 'creator' && shell === 'creator' && ['progression', 'boutique', 'messages'].includes(view))) {
              await page.screenshot({ path: path.join(output, `${role}-${view}-${width}.png`) });
            }
          }
        }
        for (const state of ['missing', 'suspended', 'withdrawn']) {
          await context.addCookies([{ name: 'fans_ui_public_state', value: state, url: base }]);
          const id = state === 'missing' ? '123e4567-e89b-42d3-a456-426614174099' : '123e4567-e89b-42d3-a456-426614174000';
          assert.equal((await page.goto(`${base}/faluss-fans/creators/${id}/`)).status(), 404);
          assert.equal(await page.locator('[data-api], form').count(), 0);
          assert.doesNotMatch(await page.locator('main').innerText(), /123e4567|suspendu|retiré/);
          await page.getByRole('link', { name: 'Retour à Explorer', exact: false }).waitFor();
          evidence.push({ width, role, route: `public-profile:${state}`, status: 404 });
          if (role === 'guest' && state === 'missing') await page.screenshot({ path: path.join(output, `profil-indisponible-${width}.png`) });
        }
        await context.addCookies([{ name: 'fans_ui_public_state', value: 'active', url: base }]);
        assert.equal((await page.goto(`${base}/faluss-fans/creators/123e4567-e89b-42d3-a456-426614174000/`)).status(), 200);
        for (const invalid of ['creator/creer//', 'creator//creer', 'creator/creer/extra']) {
          assert.equal((await page.goto(`${base}/faluss-fans/${invalid}`)).status(), 404, invalid);
        }
      }
      await context.close();
    }
    fs.writeFileSync(path.join(output, 'routes.json'), JSON.stringify(evidence, null, 2) + '\n');
    console.log(`${evidence.length} role/route/viewport checks passed; HTTP page status, no-store, nav, no overflow and honest unavailable states.`);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
