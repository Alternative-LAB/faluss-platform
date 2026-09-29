const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const launchBrowser = require('./browser-engine.cjs');
const base = process.env.FANS_UI_BASE || 'http://127.0.0.1:8765';
const output = process.env.FANS_UI_OUTPUT;
const api = '**/wp-json/faluss-fans/v1/text-publications?*';
const loaded = 'Publications chargées. Les textes restent soumis à la modération.';

(async () => {
  const browser = await launchBrowser();
  try {
    for (const width of [1440, 390]) {
      const context = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 900 } });
      const page = await context.newPage();
      const status = page.locator('[data-text-status]');
      const next = page.locator('[data-text-next]');
      const refresh = page.locator('[data-text-refresh]');
      const focused = locator => locator.evaluate(node => node === document.activeElement);
      await page.goto(`${base}/faluss-fans/fan/explorer`);
      await page.getByText(loaded, { exact: true }).waitFor();
      assert.equal(await focused(status), false, 'Automatic initial reading must not move focus');

      // Hold a real fixture response so the in-flight keyboard state can be inspected.
      let release;
      let requests = 0;
      await page.route(api, async route => {
        requests += 1;
        await new Promise(resolve => { release = resolve; });
        await route.continue();
      });
      await next.focus();
      await page.keyboard.press('Enter');
      await page.waitForFunction(() => document.querySelector('[data-text-status]').textContent === 'Chargement des publications…');
      assert.equal(await focused(next), true, 'Page suivante must keep focus while loading');
      assert.equal(await next.getAttribute('aria-disabled'), 'true');
      await page.keyboard.press('Enter');
      assert.equal(requests, 1, 'A repeated key must not issue a concurrent page request');
      release();
      await page.getByText('Fixture de recette — seconde page.', { exact: true }).waitFor();
      assert.equal(await focused(status), true, 'End of pagination must place focus before the new content');
      assert.equal(await next.isVisible(), false);
      await page.unrouteAll({ behavior: 'wait' });
      if (output) {
        fs.mkdirSync(output, { recursive: true });
        await page.screenshot({ path: path.join(output, `lecture-clavier-${width}.png`) });
      }

      await page.keyboard.press('Tab');
      // Windows WebKit skips native links even in a minimal independent HTML probe.
      // Verify its button destination explicitly; do not claim Safari keyboard parity.
      if (process.env.FANS_UI_BROWSER === 'webkit') assert.equal(await focused(refresh), true);
      else assert.match(await page.evaluate(() => document.activeElement.textContent), /Voir la fiche de l’auteur/);
      await refresh.focus();
      await page.keyboard.press('Enter');
      await next.waitFor({ state: 'visible' });
      assert.equal(await focused(status), true, 'Explicit restart must return before the first page');

      // An error leaves a useful keyboard destination and a reachable retry button.
      await page.route(api, route => route.fulfill({ status: 503, json: {} }));
      await next.focus();
      await page.keyboard.press('Enter');
      await page.getByText('Publications indisponibles.', { exact: false }).waitFor();
      assert.equal(await focused(status), true);
      await page.keyboard.press('Tab');
      assert.equal(await focused(refresh), true);
      await page.unrouteAll();
      await page.keyboard.press('Enter');
      await next.waitFor({ state: 'visible' });

      // Moving elsewhere during the response must not be undone by its completion.
      release = undefined;
      await page.route(api, async route => {
        await new Promise(resolve => { release = resolve; });
        await route.continue();
      });
      await next.focus();
      await page.keyboard.press('Enter');
      await page.waitForFunction(() => document.querySelector('[data-text-status]').textContent === 'Chargement des publications…');
      while (!release) await new Promise(resolve => setTimeout(resolve, 10));
      await page.keyboard.press('Shift+Tab');
      assert.equal(await focused(refresh), true);
      release();
      await page.getByText('Fixture de recette — seconde page.', { exact: true }).waitFor();
      assert.equal(await focused(refresh), true, 'Late response must not steal focus');
      await page.unrouteAll({ behavior: 'wait' });
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true);
      await context.close();
    }
    console.log('Reading keyboard: initial focus, pending/duplicate, last page, restart, error/retry, no focus theft; desktop/mobile passed.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
