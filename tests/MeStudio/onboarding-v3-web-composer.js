'use strict';
// Browser layout and input on actual PHP views. HTTP and persistence are isolated in memory.
const fs = require('fs'), path = require('path'), cp = require('child_process'), assert = require('assert/strict');
const {chromium, webkit} = require('playwright');
const root = path.resolve(__dirname, '../..');
const views = JSON.parse(cp.execFileSync(process.env.FALUSS_PHP, [path.join(__dirname, 'v3-composition-regression.php')], {
    encoding: 'utf8', env: {...process.env, FALUSS_V3_ONBOARDING_VIEWS: '1'}
}));
const css = ['assets/link/css/faluss-link.css', 'assets/link/css/faluss-link-immersive.css',
    'assets/me-studio/css/card-v2.css', 'assets/me-studio/css/onboarding-v3.css']
    .map(file => fs.readFileSync(path.join(root, file), 'utf8')).join('\n');
const script = fs.readFileSync(path.join(root, 'assets/me-studio/js/onboarding-v3.js'), 'utf8');
const evidence = path.join(root, 'docs/evidence/me-web-onboarding');
const blockId = '44444444-4444-4444-8444-444444444444';
const initialLabel = 'Dans A';

(async () => {
    fs.mkdirSync(evidence, {recursive: true});
    const results = [];
    for (const [engine, launcher, options] of [
        ['chromium', chromium, {executablePath: process.env.FALUSS_CHROME}], ['webkit', webkit, {}]
    ]) {
        const browser = await launcher.launch({headless: true, ...options});
        try {
            for (const [width, height] of [[390, 844], [768, 900], [900, 900], [1024, 600], [1024, 768], [1440, 900]]) {
                const context = await browser.newContext({viewport: {width, height}, hasTouch: true});
                const page = await context.newPage();
                const errors = [];
                let savedLabel = initialLabel, saveCount = 0, previewDraft = null, failSave = true;
                page.on('pageerror', error => errors.push(error.stack || error.message));
                page.on('dialog', dialog => dialog.accept());
                await context.route('**/*', async route => {
                    const request = route.request(), url = new URL(request.url());
                    if (url.hostname !== 'onboarding.test') { return route.abort(); }
                    if (url.pathname === '/media/77.jpg') {
                        return route.fulfill({contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="390" height="600"><rect width="390" height="600" fill="#bca7bd"/></svg>'});
                    }
                    if (url.pathname.endsWith('/faluss-onboarding-header-logo.png')) {
                        return route.fulfill({contentType: 'image/png', body: fs.readFileSync(path.join(root, 'assets/link/images/faluss-onboarding-header-logo.png'))});
                    }
                    if (url.pathname === '/wp-admin/admin-ajax.php') {
                        const body = new URLSearchParams(request.postData());
                        if (body.get('action') === 'faluss_onboarding_v3_preview') {
                            previewDraft = JSON.parse(body.get('fields'));
                            const link = previewDraft.link_draft ? JSON.parse(previewDraft.link_draft) : null;
                            if (link && !link.url.startsWith('https://')) {
                                return route.fulfill({status: 422, contentType: 'application/json', body: JSON.stringify({success: false, data: {code: 'invalid_links'}})});
                            }
                            return route.fulfill({contentType: 'application/json', body: JSON.stringify({success: true, data: {
                                preview_html: `<article class="faluss-link-card"><a class="faluss-link-card__link" href="https://example.test/a" data-v3-preview-block-id="${blockId}">${link ? link.label : savedLabel}</a></article>`
                            }})});
                        }
                        if (body.get('action') === 'faluss_studio_v3_manage') {
                            assert.equal(body.get('mutation'), 'update_link');
                            assert.equal(body.get('version') !== '', true);
                            if (failSave) {
                                failSave = false;
                                return route.fulfill({status: 503, contentType: 'application/json', body: JSON.stringify({success: false, data: {code: 'transaction_unavailable'}})});
                            }
                            savedLabel = JSON.parse(body.get('fields')).label;
                            saveCount++;
                            return route.fulfill({contentType: 'application/json', body: JSON.stringify({success: true})});
                        }
                        return route.fulfill({status: 400, contentType: 'application/json', body: JSON.stringify({success: false, data: {code: 'unexpected_action'}})});
                    }
                    if (url.pathname === '/onboarding/') {
                        const step = url.searchParams.get('step') || 'v3_colors';
                        const view = views[step].replaceAll('https://faluss.test', 'https://onboarding.test').replaceAll(initialLabel, savedLabel);
                        return route.fulfill({contentType: 'text/html', body: `<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}</style><style>:where(main)>*{max-width:645px!important;margin-inline:auto!important}</style><main style="width:min(100%,645px);margin:auto">${view}</main><script>window.falussOnboardingV3={ajaxUrl:'/wp-admin/admin-ajax.php',previewNonce:'preview',managementNonce:'manage'};</script><script>${script}</script>`});
                    }
                    return route.abort();
                });
                await page.goto('https://onboarding.test/onboarding/?step=v3_colors');
                const web = width >= 980 && height >= 650;
                const geometry = await page.evaluate(() => ({
                    overflow: document.documentElement.scrollWidth > innerWidth,
                    panel: document.querySelector('[data-v3-panel]').getBoundingClientRect().toJSON(),
                    preview: document.querySelector('[data-v3-preview]').getBoundingClientRect().toJSON(),
                    grabber: getComputedStyle(document.querySelector('[data-v3-grabber]')).display,
                    root: document.querySelector('[data-faluss-onboarding-v3]').getBoundingClientRect().toJSON()
                }));
                assert.equal(geometry.overflow, false, `${engine} ${width}x${height}: no horizontal overflow`);
                if (web) { assert.equal(Math.round(geometry.root.width), width, `${engine} ${width}: composer uses viewport despite theme content width`); assert.equal(Math.round(geometry.root.left), 0, `${engine} ${width}: composer is centered in viewport`); }
                assert.equal(geometry.panel.right <= geometry.preview.left, web, `${engine} ${width}x${height}: adaptive layout`);
                assert.equal(geometry.grabber === 'none', web, `${engine} ${width}x${height}: mobile sheet`);
                if (width === 390 || web) { await page.screenshot({path: path.join(evidence, `${engine}-onboarding-${width}-${height}.png`)}); }
                const editor = page.locator(`[data-v3-web-editors] [data-v3-editor][data-id="${blockId}"]`);
                assert.equal(await editor.isVisible(), false, 'Step starts with link editor hidden');
                if (web) {
                    const link = page.locator(`[data-v3-preview] [data-v3-preview-block-id="${blockId}"]`);
                    if (engine === 'chromium') { await link.tap(); } else { await link.click(); }
                    assert.equal(await editor.isVisible(), true, 'Selected link editor appears at left');
                    assert.equal(await page.locator('[data-v3-step-controls]').isVisible(), false);
                    assert.equal(await page.locator('[data-v3-preview] [data-v3-selected]').getAttribute('data-v3-preview-block-id'), blockId);
                    assert.equal(await page.locator('[data-faluss-onboarding-v3]').getAttribute('data-step'), 'v3_colors', 'Cursor remains on same step');
                    if (engine === 'chromium' && width === 1440) { await page.screenshot({path: path.join(evidence, 'chromium-onboarding-link-selected.png')}); }
                    await editor.locator('[data-field="url"]').fill('http://invalid.test');
                    await page.waitForFunction(() => document.querySelector('[data-v3-error]').textContent.includes('URL HTTPS'));
                    assert.equal(saveCount, 0, 'Invalid draft cannot write');
                    await editor.locator('[data-field="url"]').fill('https://example.test/a');
                    await editor.locator('[data-field="label"]').fill('Lien modifié en onboarding');
                    await page.waitForFunction(() => document.querySelector('[data-v3-preview]').textContent.includes('Lien modifié en onboarding'));
                    assert.equal(await page.locator('[data-v3-error]').isVisible(), false, 'Valid draft clears error');
                    assert.equal(JSON.parse(previewDraft.link_draft).block_id, blockId);
                    assert.equal(saveCount, 0, 'Preview remains transient');
                    await editor.locator('[data-v3-save-item]').click();
                    await page.waitForFunction(() => document.querySelector('[data-v3-error]').textContent.includes('temporairement indisponible'));
                    assert.equal(await editor.locator('[data-field="label"]').inputValue(), 'Lien modifié en onboarding', '503 keeps input');
                    assert.equal(saveCount, 0, 'Rejected write does not persist');
                    await editor.locator('[data-v3-save-item]').click();
                    await page.waitForFunction(id => document.querySelector(`[data-v3-web-editors] [data-v3-editor][data-id="${id}"] [data-field="label"]`).value === 'Lien modifié en onboarding' && document.querySelector('[data-v3-step-controls]').hidden === false, blockId);
                    assert.equal(saveCount, 1);
                    await page.reload();
                    assert.equal(await page.locator(`[data-v3-preview] [data-v3-preview-block-id="${blockId}"]`).innerText(), 'Lien modifié en onboarding');
                    assert.equal(await page.locator('[data-faluss-onboarding-v3]').getAttribute('data-step'), 'v3_colors');
                    if (engine === 'chromium' && width === 1440) {
                        await page.locator(`[data-v3-preview] [data-v3-preview-block-id="${blockId}"]`).click();
                        await page.setViewportSize({width: 768, height: 900});
                        assert.equal(await page.locator('[data-v3-web-editors]').isVisible(), false, 'Tablet contraction restores mobile step');
                        assert.equal(await page.locator('[data-v3-step-controls]').isVisible(), true);
                        await page.setViewportSize({width: 1440, height: 900});
                        await page.waitForFunction(() => !document.querySelector('[data-v3-web-editors]').hidden);
                        assert.equal(await editor.isVisible(), true, 'Desktop editor resumes after enlargement');
                        await page.locator('[data-v3-web-edit-close]').click();
                        await page.locator(`[data-v3-preview] [data-v3-preview-block-id="${blockId}"]`).click();
                        await editor.locator('[data-field="label"]').fill('Brouillon lors du redimensionnement');
                        await page.setViewportSize({width: 768, height: 900});
                        await page.waitForFunction(() => !document.querySelector('[data-v3-step-controls]').hidden);
                        assert.equal(await page.locator('[data-v3-step-controls]').isVisible(), true, 'Mobile controls win over a pending web draft');
                        await page.locator('[name="page_background"]').first().check({force: true});
                        await page.setViewportSize({width: 1440, height: 900});
                        await page.waitForFunction(() => !document.querySelector('[data-v3-step-controls]').hidden);
                        assert.equal(await page.locator('[data-v3-step-controls]').isVisible(), true, 'Unsaved mobile step stays visible on desktop');
                    }
                    await page.locator('[name="page_background"]').first().check({force: true});
                    await page.locator(`[data-v3-preview] [data-v3-preview-block-id="${blockId}"]`).click();
                    assert.equal(await editor.isVisible(), false, 'Unsaved step blocks link editing');
                    assert.match(await page.locator('[data-v3-error]').innerText(), /Enregistre cette étape/);
                    results.push(`${engine} ${width}x${height}: touch/mouse select, invalid draft, preview, save/reload OK`);
                } else {
                    assert.equal(await page.locator('[data-v3-web-editors]').isVisible(), false);
                    assert.equal(await page.locator('[data-v3-step-controls]').isVisible(), true);
                    results.push(`${engine} ${width}x${height}: mobile step and bottom panel retained`);
                }
                assert.deepEqual(errors, [], `${engine} ${width}x${height}: browser errors`);
                await context.close();
            }
        } finally { await browser.close(); }
    }
    fs.writeFileSync(path.join(evidence, 'results.txt'), results.join('\n') + '\n');
    console.log(results.join('\n'));
})().catch(error => { console.error(error); process.exitCode = 1; });
