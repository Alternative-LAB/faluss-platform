'use strict';
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
(async () => {
    const fixture = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
    assert.match(fixture.base, /^http:\/\/127\.0\.0\.1:[0-9]+$/);
    const output = path.resolve(process.argv[3]); fs.mkdirSync(output, { recursive: true });
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const checks = [];
    try {
        for (const [device, viewport] of [['desktop', { width: 1440, height: 1000 }], ['mobile', { width: 390, height: 844 }]]) {
            for (const [name, who, url, selector] of [
                ['closed-conversation', 'member', '/app/fan/messages?thread='+fixture.thread, '[data-fans-private-reading]'],
                ['closed-appeal', 'member', '/app/fan/messages?section=reports', '[data-fans-private-reading]'],
                ['moderator-proof', 'admin-two', '/wp-admin/admin.php?page=faluss-fans-moderation&view=messages&item='+fixture.case, '.faluss-moderation']
            ]) {
                const context = await browser.newContext({ viewport, javaScriptEnabled: false });
                await context.route('**/*', route => new URL(route.request().url()).hostname === '127.0.0.1' ? route.continue() : route.abort());
                await context.addCookies(Object.entries(fixture.sessions[who].cookies).map(([name, value]) => ({ name, value, url: fixture.base })));
                const page = await context.newPage(); const response = await page.goto(fixture.base+url);
                assert.equal(response.status(), 200); await page.locator(selector).first().waitFor();
                await page.evaluate(() => document.fonts.ready);
                assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
                if (name === 'closed-conversation') { assert.equal(await page.locator('textarea[name="body"]').count(), 0); }
                if (name === 'closed-appeal') { assert.match(await page.locator('body').textContent(), /Recours en cours/); }
                if (name === 'moderator-proof') {
                    assert.equal(await page.locator('input[name="_wpnonce"]').count(), 1);
                    assert.equal(await page.locator('option[value="finalize"]').count(), 0);
                    await page.locator('select').focus();
                    assert.equal(await page.locator('select').evaluate(el => getComputedStyle(el).outlineWidth), '3px');
                    await page.locator('h1').click();
                }
                await page.screenshot({ path: path.join(output, name+'-'+device+'.png'), fullPage: true });
                checks.push({ device, name, http: 200, noHorizontalOverflow: true, javaScriptDisabled: true });
                await context.close();
            }
        }
        fs.writeFileSync(path.join(output, 'browser.json'), JSON.stringify({ browser: browser.version(), checks }, null, 2));
        console.log('PASS 6 real WordPress views with admission closed');
    } finally { await browser.close(); }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
