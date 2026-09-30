'use strict';

// Generated local cookies stay in the external fixture file; only screenshots/metrics are exported.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');

(async () => {
    const fixture = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
    assert.match(fixture.base, /^http:\/\/127\.0\.0\.1:[0-9]+$/);
    const output = path.resolve(process.argv[3]);
    fs.mkdirSync(output, { recursive: true });
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const checks = [];
    try {
        for (const [device, viewport] of [['desktop', { width: 1440, height: 1000 }], ['mobile', { width: 390, height: 844 }]]) {
            const context = await browser.newContext({ viewport, javaScriptEnabled: false });
            await context.route('**/*', route => new URL(route.request().url()).hostname === '127.0.0.1' ? route.continue() : route.abort());
            await context.addCookies(Object.entries(fixture.sessions.admin.cookies).map(([name, value]) => ({ name, value, url: fixture.base })));
            const page = await context.newPage();
            const panel = '/wp-admin/admin.php?page=faluss-fans-moderation&view=profiles';
            for (const [view, url] of [['queue', panel], ['pending', panel+'&item='+fixture.profiles.waiting], ['active', panel+'&status=active&item='+fixture.profiles.member]]) {
                const response = await page.goto(fixture.base+url);
                assert.equal(response.status(), 200);
                await page.locator('.faluss-moderation').waitFor();
                await page.evaluate(() => document.fonts.ready);
                assert.equal(await page.locator('.faluss-moderation h1').textContent(), 'Profils Créateur');
                assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
                assert.equal(await page.locator('.faluss-moderation nav[aria-label="Modération Fans"] [aria-current="page"]').count(), 1);
                if (view !== 'queue') {
                    const form = page.locator('.fm-decision');
                    assert.equal(await form.getAttribute('method'), 'post');
                    assert.equal(await form.locator('input[name="_wpnonce"]').count(), 1);
                    assert.equal(await form.locator('input[name="revision"]').count(), 1);
                    assert.equal(await form.locator('select[required]').count(), 1);
                    assert.equal(await form.locator('input[name="confirm"][required]').count(), 1);
                    await form.locator('select').focus();
                    const focus = await form.locator('select').evaluate(el => getComputedStyle(el).outlineWidth);
                    assert.equal(focus, '3px');
                    // Check actual keyboard navigation from the decision to confirmation and submit.
                    await page.keyboard.press('Tab');
                    assert.equal(await page.evaluate(() => document.activeElement.name), 'confirm');
                    await page.keyboard.press('Tab');
                    assert.equal(await page.evaluate(() => document.activeElement.tagName), 'BUTTON');
                    await page.locator('h1').click();
                }
                await page.screenshot({ path: path.join(output, view+'-'+device+'.png'), fullPage: view !== 'queue' });
                checks.push({ device, view, http: response.status(), noHorizontalOverflow: true, nativeFormWithoutJavaScript: view !== 'queue' });
            }
            await context.close();
        }
        const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, javaScriptEnabled: false });
        await context.route('**/*', route => new URL(route.request().url()).hostname === '127.0.0.1' ? route.continue() : route.abort());
        await context.addCookies(Object.entries(fixture.sessions.admin.cookies).map(([name, value]) => ({ name, value, url: fixture.base })));
        const page = await context.newPage();
        await page.goto(fixture.base+'/wp-admin/admin.php?page=faluss-fans-moderation&view=profiles&item='+fixture.profiles.waiting);
        await page.locator('select[name="status"]').selectOption('active');
        await page.locator('input[name="confirm"]').check();
        const [submitted] = await Promise.all([
            page.waitForNavigation(), page.locator('.fm-decision button[type="submit"]').click()
        ]);
        assert.equal(submitted.status(), 200);
        assert.match(await page.locator('.fm-notice').textContent(), /Décision confirmée et journalisée/);
        assert.match(await page.locator('.fm-journal').textContent(), /En attente → Actif/);
        checks.push({ nativeBrowserSubmitWithoutJavaScript: true, http: 200, journalConfirmed: true });
        await context.close();
        fs.writeFileSync(path.join(output, 'browser.json'), JSON.stringify({ browser: browser.version(), checks }, null, 2));
        console.log('PASS 6 real WordPress browser views, keyboard, responsive overflow and native browser decision without JavaScript');
    } finally { await browser.close(); }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
