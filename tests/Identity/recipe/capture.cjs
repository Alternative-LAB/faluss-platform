// Only the disposable loopback WordPress fixture. Never save a raw secret or cookie.
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
let phase = 'read fixture';
(async () => {
  const fixture = process.env.SSO_WP_FIXTURE;
  assert.match(fixture, /^\/var\/tmp\/sso-admin-wp-[a-z0-9_]+$/);
  const session = JSON.parse(execFileSync('wsl.exe', ['-u', 'root', '--', 'cat', fixture + '/browser-session.json'], { encoding: 'utf8' }));
  assert.match(session.base, /^http:\/\/127\.0\.0\.1:\d+$/);
  const output = path.resolve(process.env.SSO_WP_OUTPUT);
  fs.mkdirSync(output, { recursive: true });
  phase = 'launch browser';
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    phase = 'restore test session';
    await context.addCookies(session.cookies);
    await context.route('**/*', route => new URL(route.request().url()).origin === session.base ? route.continue() : route.abort());
    const page = await context.newPage();
    phase = 'open local WordPress';
    await page.goto(session.base + '/wp-admin/options-general.php?page=faluss-identity-sso-clients');
    phase = 'check Fans form';
    const card = page.locator('.card').filter({ has: page.locator('input[name="client_name"][value="Fans local incident fixture"]') });
    assert.equal(await card.count(), 1);
    assert.equal(await card.locator('input[name="first_party"]').isChecked(), false);
    assert.equal(await card.locator('input[name="first_party"]').isDisabled(), true);
    await card.screenshot({ path: path.join(output, 'fans-client-desktop.png') });
    await page.setViewportSize({ width: 390, height: 844 });
    await card.screenshot({ path: path.join(output, 'fans-client-mobile.png') });
    await page.setViewportSize({ width: 1440, height: 1000 });
    phase = 'rotate in browser';
    const response = await Promise.all([
      page.waitForNavigation({ waitUntil: 'load' }),
      card.getByRole('button', { name: 'Générer un nouveau secret' }).click()
    ]);
    assert.equal(response[0].status(), 200);
    const secret = page.locator('[data-faluss-client-secret]');
    assert.equal(await secret.count(), 1);
    assert.match(await secret.textContent(), /^[A-Za-z0-9_-]{43}$/);
    // Redact the DOM before any screenshot, trace, HTML file or console output.
    await secret.evaluate(node => { node.textContent = '[SECRET DE RECETTE MASQUÉ]'; });
    await page.screenshot({ path: path.join(output, 'confirmation-desktop.png'), fullPage: true });
    await page.getByRole('link', { name: 'Retour aux clients SSO' }).click();
    assert.equal(await page.locator('[data-faluss-client-secret]').count(), 0);
    fs.writeFileSync(path.join(output, 'browser.json'), JSON.stringify({ browser: await browser.version(), wordpress: '7.1.2', viewports: [1440,390], fansCheckbox: 'unchecked and disabled', rotation: 200, secret: 'one response, redacted before capture, absent after return', environment: 'disposable real WordPress, loopback only' }, null, 2) + '\n');
    console.log('PASS native WordPress browser: Fans ineligible, rotation 200, secret absent after return; 3 redacted captures');
  } finally { await browser.close(); }
})().catch(error => { console.error('WordPress browser recipe failed at ' + phase + ' (' + error.name + '; private response omitted)'); process.exitCode = 1; });
