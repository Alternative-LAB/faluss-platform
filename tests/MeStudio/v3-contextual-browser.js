'use strict';
// Chromium/WebKit interaction with rendered PHP views and simulated HTTP writes.
const fs = require('fs'), path = require('path'), cp = require('child_process'), assert = require('assert/strict');
const {chromium, webkit} = require('playwright');
const root = path.resolve(__dirname, '../..');
const views = JSON.parse(cp.execFileSync(process.env.FALUSS_PHP, [path.join(__dirname, 'v3-composition-regression.php')], {
    encoding: 'utf8', env: {...process.env, FALUSS_V3_VIEWS: '1'}
}));
const css = ['assets/link/css/faluss-link.css', 'assets/link/css/faluss-link-immersive.css', 'assets/me-studio/css/card-v2.css', 'assets/me-studio/css/studio-v3.css']
    .map(file => fs.readFileSync(path.join(root, file), 'utf8')).join('\n');
const studioJs = fs.readFileSync(path.join(root, 'assets/me-studio/js/studio-v3.js'), 'utf8');
const cardJs = fs.readFileSync(path.join(root, 'assets/link/js/faluss-link-card.js'), 'utf8');
const evidence = process.env.FALUSS_BROWSER_EVIDENCE_DIR;

(async () => {
    for (const [name, launcher, options] of [['chromium', chromium, {executablePath: process.env.FALUSS_CHROME}], ['webkit', webkit, {}]]) {
        const browser = await launcher.launch({headless: true, ...options});
        try {
            for (const [width, height] of [[390, 844], [768, 900], [1024, 768], [1440, 900]]) {
                const context = await browser.newContext({viewport: {width, height}, hasTouch: true});
                const page = await context.newPage();
                const errors = [], mutations = [];
                let savedTitle = 'Vidéo à la une', draftTitle = savedTitle, originalPreviewHtml = '';
                page.on('pageerror', error => errors.push(error.stack || error.message));
                page.on('dialog', dialog => dialog.accept());
                await page.route('**/*', route => {
                    const url = new URL(route.request().url());
                    if (url.hostname !== 'studio.test') { return route.abort(); }
                    if (url.pathname === '/wp-admin/admin-ajax.php') {
                        const fields = new URLSearchParams(route.request().postData());
                        if (fields.get('action') === 'faluss_onboarding_v3_preview') {
                            const draft = JSON.parse(fields.get('fields'));
                            if (draft.contextual_draft) { draftTitle = JSON.parse(draft.contextual_draft).title; }
                            return route.fulfill({contentType: 'application/json', body: JSON.stringify({success: true, data: {preview_html: originalPreviewHtml.replaceAll('Vidéo à la une', draftTitle)}})});
                        }
                        if (fields.get('action') === 'faluss_studio_v3_manage') {
                            mutations.push({mutation: fields.get('mutation'), fields: JSON.parse(fields.get('fields'))});
                            savedTitle = mutations.at(-1).fields.title;
                            return route.fulfill({contentType: 'application/json', body: JSON.stringify({success: true})});
                        }
                        return route.fulfill({status: 400, contentType: 'application/json', body: '{"success":false}'});
                    }
                    if (url.pathname === '/studio/') {
                        const html = views.v3_context.replaceAll('https://faluss.test', 'https://studio.test').replaceAll('Vidéo à la une', savedTitle);
                        return route.fulfill({contentType: 'text/html', body: `<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}</style><main>${html}</main><script>window.falussStudioV3={ajaxUrl:'/wp-admin/admin-ajax.php',previewNonce:'preview',managementNonce:'manage'};</script><script>${cardJs}</script><script>${studioJs}</script>`});
                    }
                    return route.abort();
                });
                await page.goto('https://studio.test/studio/?v3_section=v3_context');
                originalPreviewHtml = await page.locator('[data-studio-inline-preview]').innerHTML();
                const canvas = page.locator('[data-studio-canvas]');
                assert.equal(await canvas.isVisible(), width >= 980, `${name} ${width}: adaptive composer`);
                const editor = page.locator('[data-mutation="update_contextual"]');
                assert.equal(await editor.count(), 1, `${name} ${width}: contextual editor`);
                if (width >= 980) {
                    const block = canvas.locator('.faluss-link-card__contextual');
                    if (name === 'chromium') { await block.tap(); } else { await block.click(); }
                    assert.equal(await editor.getAttribute('open'), '', `${name} ${width}: preview selects contextual editor`);
                } else { await editor.locator('summary').click(); }
                await editor.locator('[data-field="title"]').fill('Nouvelle vidéo');
                if (width < 980) { await page.locator('[data-studio-preview-open]').click(); }
                await page.waitForFunction(() => document.querySelector('[data-studio-inline-preview]').textContent.includes('Nouvelle vidéo') || document.querySelector('dialog').textContent.includes('Nouvelle vidéo'));
                if (width < 980) { await page.locator('[data-studio-preview-close]').click(); }
                assert.equal(mutations.length, 0, 'Draft did not write');
                if (evidence && name === 'chromium' && (width === 390 || width === 1440)) {
                    fs.mkdirSync(evidence, {recursive: true});
                    await page.screenshot({path: path.join(evidence, `contextual-studio-${width}.png`)});
                }
                await page.locator('[data-v3-panel]').evaluate(node => { node.dataset.contextOldPanel = ''; });
                await editor.locator('[data-v3-save-item]').click();
                await page.waitForFunction(() => !document.querySelector('[data-v3-panel]').hasAttribute('data-context-old-panel') && document.querySelector('[data-field="title"]').value === 'Nouvelle vidéo' && document.querySelector('[data-faluss-studio-v3]').dataset.step === 'v3_context');
                assert.equal(mutations.length, 1, `${name} ${width}: one save`);
                assert.equal(mutations[0].mutation, 'update_contextual');
                assert.equal(mutations[0].fields.category, 'video');
                await page.reload();
                assert.equal(await page.locator('[data-mutation="update_contextual"] [data-field="title"]').inputValue(), 'Nouvelle vidéo');
                assert.deepEqual(errors, [], `${name} ${width}: no browser errors`);
                await context.close();
            }
            const publicPage = await browser.newPage({viewport: {width: 600, height: 900}});
            await publicPage.route('**/*', route => route.request().url() === 'https://studio.test/public' || route.request().url().startsWith('https://www.youtube-nocookie.com/') ? route.fulfill({contentType: 'text/html', body: '<!doctype html>'}) : route.abort());
            await publicPage.goto('https://studio.test/public');
            const html = views.v3_context.match(/<section class="faluss-link-card__contextual"[\s\S]*?<\/section>/);
            assert(html, 'Public card fixture contains contextual markup');
            await publicPage.setContent(`<style>${css}</style><article class="faluss-link-card faluss-link-card--canonical">${html[0]}</article>`);
            await publicPage.addScriptTag({content: cardJs});
            assert.equal(await publicPage.locator('.faluss-link-card__contextual a[href^="https://www.youtube.com/watch"]').count(), 1, 'Permanent fallback link');
            await publicPage.locator('[data-faluss-contextual-embed]').click();
            assert.equal(await publicPage.locator('iframe[src^="https://www.youtube-nocookie.com/embed/"]').count(), 1, 'Click loads canonical YouTube embed');
            if (evidence && name === 'chromium') { await publicPage.screenshot({path: path.join(evidence, 'contextual-public-fallback.png')}); }
            await publicPage.setContent('<section class="faluss-link-card__contextual"><button data-faluss-contextual-embed="https://player.twitch.tv/?channel=example&parent=studio.test">Lire ici</button><div class="faluss-link-card__contextual-player" style="width:350px" hidden></div><a href="https://www.twitch.tv/example">Ouvrir sur Twitch</a></section>');
            await publicPage.addScriptTag({content: cardJs});
            await publicPage.evaluate(() => { window.fallbackClicks = 0; document.querySelector('a').addEventListener('click', event => { event.preventDefault(); window.fallbackClicks++; }); });
            await publicPage.locator('[data-faluss-contextual-embed]').click();
            assert.equal(await publicPage.locator('iframe').count(), 0, 'Narrow Twitch card does not embed below minimum width');
            assert.equal(await publicPage.evaluate(() => window.fallbackClicks), 1, 'Narrow Twitch card opens its permanent fallback');
            await publicPage.close();
        } finally { await browser.close(); }
    }
    console.log('Contextual composer and public fallback: Chromium/WebKit, 390/768/1024/1440: OK');
})().catch(error => { console.error(error); process.exitCode = 1; });
