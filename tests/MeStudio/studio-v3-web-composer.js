'use strict';
// Real Chromium/WebKit layout and input; HTTP writes are a disposable in-memory fixture.
const fs = require('fs'), path = require('path'), cp = require('child_process'), assert = require('assert/strict');
const {chromium, webkit} = require('playwright');
const root = path.resolve(__dirname, '../..');
const views = JSON.parse(cp.execFileSync(process.env.FALUSS_PHP, [path.join(__dirname, 'v3-composition-regression.php')], {
    encoding: 'utf8', env: {...process.env, FALUSS_V3_VIEWS: '1'}
}));
const css = ['assets/link/css/faluss-link.css', 'assets/link/css/faluss-link-immersive.css',
    'assets/me-studio/css/card-v2.css', 'assets/me-studio/css/studio-v3.css']
    .map(file => fs.readFileSync(path.join(root, file), 'utf8')).join('\n');
const script = fs.readFileSync(path.join(root, 'assets/me-studio/js/studio-v3.js'), 'utf8');
const evidence = process.env.FALUSS_BROWSER_EVIDENCE_DIR || path.join(root, 'docs/evidence/me-web-studio');
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
                let savedLabel = initialLabel, previewDraft = null, saveCount = 0;
                const errors = [];
                page.on('pageerror', error => errors.push(error.stack || error.message));
                page.on('dialog', dialog => dialog.accept());
                await context.route('**/*', async route => {
                    const request = route.request(), url = new URL(request.url());
                    if (url.hostname !== 'studio.test') { return route.abort(); }
                    if (url.pathname === '/media/77.jpg') {
                        return route.fulfill({contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="600" height="600" viewBox="0 0 600 600"><rect width="600" height="600" fill="#e5d6dc"/><circle cx="510" cy="80" r="180" fill="#f2e7e5"/><circle cx="75" cy="520" r="215" fill="#d0b7c3"/><path d="M-60 315C150 210 370 410 660 270" fill="none" stroke="#b799ab" stroke-width="46" opacity=".45"/></svg>'});
                    }
                    if (url.pathname === '/wp-admin/admin-ajax.php') {
                        const body = new URLSearchParams(request.postData());
                        if (body.get('action') === 'faluss_onboarding_v3_preview') {
                            previewDraft = JSON.parse(body.get('fields'));
                            const label = previewDraft.link_draft ? JSON.parse(previewDraft.link_draft).label : savedLabel;
                            return route.fulfill({contentType: 'application/json', body: JSON.stringify({success: true, data: {
                                preview_html: `<article class="faluss-link-card"><a class="faluss-link-card__link" href="https://example.test/a" data-v3-preview-block-id="${blockId}">${label}</a></article>`
                            }})});
                        }
                        if (body.get('action') === 'faluss_studio_v3_manage') {
                            assert.equal(body.get('mutation'), 'update_link');
                            savedLabel = JSON.parse(body.get('fields')).label;
                            saveCount++;
                            return route.fulfill({contentType: 'application/json', body: JSON.stringify({success: true})});
                        }
                        return route.fulfill({status: 400, contentType: 'application/json', body: JSON.stringify({success: false, data: {code: 'unexpected_action'}})});
                    }
                    if (url.pathname === '/studio/') {
                        const section = url.searchParams.get('v3_section') || 'v3_links';
                        const view = views[section].replaceAll('https://faluss.test', 'https://studio.test').replaceAll(initialLabel, savedLabel);
                        return route.fulfill({contentType: 'text/html', body: `<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}</style><style>:where(main)>*{max-width:645px!important;margin-inline:auto!important}</style><header style="height:${width >= 980 && height >= 650 ? 280 : 0}px">En-tête du thème</header><main style="width:min(100%,645px);margin:auto">${view}</main><script>window.falussStudioV3={ajaxUrl:'/wp-admin/admin-ajax.php',previewNonce:'preview',managementNonce:'manage'};</script><script>${script}</script>`});
                    }
                    return route.abort();
                });
                await page.goto('https://studio.test/studio/?v3_section=v3_colors');
                const canvas = page.locator('[data-studio-canvas]');
                const visible = width >= 980 && height >= 650;
                assert.equal(await canvas.isVisible(), visible, `${engine} ${width}×${height}: adaptive composer`);
                const geometry = await page.evaluate(() => ({
                    overflow: document.documentElement.scrollWidth > innerWidth,
                    panel: document.querySelector('[data-v3-panel]').getBoundingClientRect().toJSON(),
                    canvas: document.querySelector('[data-studio-canvas]').getBoundingClientRect().toJSON(),
                    root: document.querySelector('[data-faluss-studio-v3]').getBoundingClientRect().toJSON()
                }));
                assert.equal(geometry.overflow, false, `${engine} ${width}×${height}: no horizontal overflow`);
                if (visible) { assert.equal(Math.round(geometry.root.width), width, `${engine} ${width}: composer uses viewport despite theme content width`); assert.equal(Math.round(geometry.root.left), 0, `${engine} ${width}: composer is centered in viewport`); assert.equal(Math.round(geometry.root.top), 0, `${engine} ${width}: theme header cannot push actions outside viewport`); assert.equal(Math.round(geometry.root.bottom), height, `${engine} ${width}: whole composer fits viewport`); }
                await page.goto('https://studio.test/studio/?v3_section=v3_activity');
                assert.equal(await page.locator('input[name="activity_category"]').count(), 5, `${engine} ${width}×${height}: activity choices`);
                if (visible) {
                    const activity = await page.evaluate(() => ({panel: document.querySelector('[data-v3-panel]').getBoundingClientRect().toJSON(), canvas: document.querySelector('[data-studio-canvas]').getBoundingClientRect().toJSON()}));
                    assert(activity.panel.right <= activity.canvas.left, `${engine} ${width}×${height}: activity editor left of card`);
                }
                if (engine === 'chromium' && (width === 390 || width === 1440)) { await page.screenshot({path: path.join(evidence, `activity-studio-${width}.png`)}); }
                await page.goto('https://studio.test/studio/?v3_section=v3_colors');
                if (width === 390 || visible) { await page.screenshot({path: path.join(evidence, `${engine}-studio-${width}-${height}.png`)}); }
                if (visible) {
                    assert(geometry.panel.right <= geometry.canvas.left, `${engine} ${width}: editor left, card right`);
                    const link = canvas.locator(`[data-v3-preview-block-id="${blockId}"]`);
                    if (engine === 'chromium') { await link.tap(); } else { await link.click(); }
                    await page.waitForFunction(() => document.querySelector('[data-faluss-studio-v3]').dataset.step === 'v3_links');
                    const editor = page.locator(`[data-v3-editor][data-id="${blockId}"]`);
                    assert.equal(await editor.getAttribute('open'), '', `${engine} ${width}: exact link editor opened`);
                    assert.equal(await page.url().includes('v3_links'), true);
                    assert.equal(await canvas.locator('[data-v3-selected]').getAttribute('data-v3-preview-block-id'), blockId);
                    await editor.locator('[data-field="label"]').fill('Lien web modifié');
                    await page.waitForFunction(() => document.querySelector('[data-studio-inline-preview]').textContent.includes('Lien web modifié'));
                    assert.equal(JSON.parse(previewDraft.link_draft).block_id, blockId);
                    assert.equal(saveCount, 0, 'Draft preview does not persist');
                    await page.locator('[data-v3-panel]').evaluate(node => { node.dataset.testOldPanel = ''; });
                    await editor.locator('[data-v3-save-item]').click();
                    await page.waitForFunction(id => !document.querySelector('[data-v3-panel]').hasAttribute('data-test-old-panel') && document.querySelector(`[data-v3-editor][data-id="${id}"] [data-field="label"]`).value === 'Lien web modifié', blockId);
                    assert.equal(saveCount, 1);
                    await page.reload();
                    assert.equal(await canvas.locator(`[data-v3-preview-block-id="${blockId}"]`).innerText(), 'Lien web modifié');
                    results.push(`${engine} ${width}x${height}: touch/mouse selection, live server preview, save and reload OK`);
                } else {
                    assert.equal(await page.locator('.faluss-studio-v3__bottom').evaluate(node => getComputedStyle(node).position), 'fixed');
                    await page.locator('[data-studio-preview-open]').click();
                    assert.equal(await page.locator('dialog').isVisible(), true);
                    await page.locator('[data-studio-preview-close]').click();
                    results.push(`${engine} ${width}x${height}: existing mobile editor and preview dialog retained`);
                }
                assert.deepEqual(errors, [], `${engine} ${width}: browser errors`);
                await context.close();
            }
        } finally { await browser.close(); }
    }
    fs.writeFileSync(path.join(evidence, 'results.txt'), results.join('\n') + '\n');
    console.log(results.join('\n'));
})().catch(error => { console.error(error); process.exitCode = 1; });
