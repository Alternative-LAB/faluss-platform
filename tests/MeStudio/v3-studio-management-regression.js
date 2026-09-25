'use strict';
// Functional client contract, with a mocked AJAX server (not a WordPress recipe).
const fs = require('fs'), path = require('path'), cp = require('child_process'), assert = require('assert/strict');
const {chromium} = require('playwright');
const root = path.resolve(__dirname, '../..');
const views = JSON.parse(cp.execFileSync(process.env.FALUSS_PHP, [path.join(__dirname, 'v3-composition-regression.php')], {encoding: 'utf8', env: {...process.env, FALUSS_V3_VIEWS: '1'}}));
const js = fs.readFileSync(path.join(root, 'assets/me-studio/js/onboarding-v3.js'), 'utf8');
(async () => {
    const browser = await chromium.launch({headless: true, ...(process.env.FALUSS_CHROME ? {executablePath: process.env.FALUSS_CHROME} : {})});
    try {
        const page = await browser.newPage();
        const requests = [], errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', route => {
            if (route.request().url() === 'https://studio.test/ajax') {
                requests.push(new URLSearchParams(route.request().postData()));
                return route.fulfill({status: 409, contentType: 'application/json', body: JSON.stringify({success: false, data: {code: 'stale_version'}})});
            }
            if (route.request().url() === 'https://studio.test/') {
                return route.fulfill({contentType: 'text/html', body: `${views.v3_collections}<script>window.falussOnboardingV3={ajaxUrl:'/ajax',managementNonce:'test-nonce'};</script><script>${js}</script>`});
            }
            return route.abort();
        });
        await page.goto('https://studio.test/');
        const editor = page.locator('[data-mutation="create_collection"]');
        await editor.locator('summary').click();
        await editor.locator('[data-field="name"]').fill('Ma collection');
        await editor.locator('[data-field="description"]').fill('Description conservée');
        await editor.locator('[data-v3-save-item]').click();
        await page.waitForFunction(() => !document.querySelector('[data-v3-error]').hidden);
        assert.equal(requests.length, 1);
        assert.equal(requests[0].get('action'), 'faluss_studio_v3_manage');
        assert.equal(requests[0].get('nonce'), 'test-nonce');
        assert.equal(requests[0].get('mutation'), 'create_collection');
        const fields = JSON.parse(requests[0].get('fields'));
        assert.equal(fields.name, 'Ma collection'); assert.equal(fields.description, 'Description conservée');
        assert.match(fields.block_id, /^[0-9a-f-]{36}$/); assert.notEqual(fields.block_id, fields.description_block_id);
        assert.equal(await editor.locator('[data-field="name"]').inputValue(), fields.name);
        assert.equal(await editor.locator('[data-v3-save-item]').isEnabled(), true);
        assert.match(await page.locator('[data-v3-error]').innerText(), /autre session/);
        assert.deepEqual(errors, []);
        console.log('Native collection editor: scoped payload/nonce, UUIDs, one request, 409 retains fields and retry: OK');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
