'use strict';

// Real PHP views and shipped JavaScript/CSS; AJAX responses are simulated locally.
const fs = require('fs');
const path = require('path');
const cp = require('child_process');
const assert = require('assert/strict');
const {chromium} = require('playwright');

const root = path.resolve(__dirname, '../..');
const evidence = path.join(root, process.env.FALUSS_EVIDENCE_DIR || 'docs/evidence/link-import');
const views = JSON.parse(cp.execFileSync(process.env.FALUSS_PHP || 'php', [path.join(__dirname, 'v3-composition-regression.php')], {
    encoding: 'utf8', env: {...process.env, FALUSS_V3_VIEWS: '1'}
}));
const css = fs.readFileSync(path.join(root, 'assets/me-studio/css/studio-v3.css'), 'utf8');
const importerJs = fs.readFileSync(path.join(root, 'assets/me-studio/js/link-import.js'), 'utf8');
const studioJs = fs.readFileSync(path.join(root, 'assets/me-studio/js/studio-v3.js'), 'utf8');
const config = {ajaxUrl: 'https://studio.test/ajax', importNonce: 'preview-nonce', managementNonce: 'commit-nonce'};
const literalTitle = '<img src=x onerror=window.importXss=1> Mon site';
const initialRows = [
    {label: literalTitle, url: 'https://example.com/site', order: 0, status: 'ready', message: '', selected: true},
    {label: 'Ma vidéo', url: 'https://example.com/video', order: 1, status: 'ready', message: '', selected: true},
    {label: 'Déjà sur ma carte', url: 'https://example.com/existing', order: 2, status: 'duplicate', message: 'Ce lien existe déjà.', selected: false},
    {label: 'À corriger', url: 'javascript:alert(1)', order: 3, status: 'invalid', message: 'Saisissez une URL HTTPS publique.', selected: false},
    {label: 'Mes articles', url: 'https://example.com/articles', order: 4, status: 'ready', message: '', selected: true}
];

(async () => {
    fs.mkdirSync(evidence, {recursive: true});
    const browser = await chromium.launch({headless: true, ...(process.env.FALUSS_CHROME ? {executablePath: process.env.FALUSS_CHROME} : {})});
    const results = [];
    try {
        for (const width of [320, 390, 768]) {
            const context = await browser.newContext({viewport: {width, height: 844}, reducedMotion: 'reduce'});
            const page = await context.newPage();
            const errors = [], requests = [], dialogs = [];
            let commitError = null, previewError = null, holdCommit = null, releaseCommit = null;
            let canonicalReads = 0, canonicalVersion = 'a'.repeat(64), failCanonicalRead = false;
            page.on('pageerror', error => errors.push(error.message));
            page.on('dialog', async dialog => { dialogs.push(dialog.message()); await dialog.dismiss(); });
            const html = section => {
                const view = views[section].replaceAll('https://faluss.test', 'https://studio.test')
                    .replace(/data-studio-config="[^"]*"/, 'data-studio-config="' + JSON.stringify(config).replaceAll('"', '&quot;') + '"')
                    .replace(/data-version="[^"]*"/, `data-version="${canonicalVersion}"`);
                return `<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}</style>${view}<script>window.falussStudioV3=${JSON.stringify(config)};</script><script>${importerJs}</script><script>${studioJs}</script>`;
            };
            await context.route('**/*', async route => {
                const request = route.request(), url = new URL(request.url());
                if (url.origin !== 'https://studio.test') { return route.abort(); }
                if (url.pathname === '/ajax') {
                    const data = new URLSearchParams(request.postData());
                    requests.push(data);
                    if (data.get('action') === 'faluss_studio_link_import_preview') {
                        if (previewError) { return route.fulfill({status: 422, contentType: 'application/json', body: JSON.stringify({success: false, data: {code: previewError}})}); }
                        // Revalidation receives only row values; the server owns statuses.
                        const rows = data.has('rows') ? JSON.parse(data.get('rows')).map((row, index) => ({
                            ...row, status: index === 2 ? 'duplicate' : 'ready', message: index === 2 ? 'Ce lien existe déjà.' : '', selected: index !== 2
                        })) : structuredClone(initialRows);
                        return route.fulfill({contentType: 'application/json', body: JSON.stringify({success: true, data: {rows, capacity: 8}})});
                    }
                    if (data.get('action') === 'faluss_studio_v3_manage') {
                        if (holdCommit) { await holdCommit; }
                        if (commitError) { return route.fulfill({status: 409, contentType: 'application/json', body: JSON.stringify({success: false, data: {code: commitError}})}); }
                        canonicalVersion = 'b'.repeat(64);
                        return route.fulfill({contentType: 'application/json', body: JSON.stringify({success: true, data: {imported: JSON.parse(data.get('fields')).rows.length}})});
                    }
                    return route.abort();
                }
                if (url.pathname === '/studio/') {
                    if (!request.isNavigationRequest()) { canonicalReads++; }
                    if (failCanonicalRead && !request.isNavigationRequest()) { return route.fulfill({status: 503, body: 'Unavailable'}); }
                    return route.fulfill({contentType: 'text/html', body: html(url.searchParams.get('v3_section') || 'v3_links')});
                }
                if (url.pathname.startsWith('/media/')) {
                    return route.fulfill({contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80"><rect width="80" height="80" fill="#ffe3db"/><text x="40" y="50" text-anchor="middle" font-family="sans-serif" font-size="32" fill="#33231f">M</text></svg>'});
                }
                return route.abort();
            });
            await page.goto('https://studio.test/studio/?v3_section=v3_links');
            const host = page.locator('[data-link-import]');
            const commit = host.locator('[data-import-commit]');
            const source = host.locator('[data-import-source]');
            const rows = host.locator('[data-import-rows] > li');
            const previewCalls = () => requests.filter(data => data.get('action') === 'faluss_studio_link_import_preview');
            const commitCalls = () => requests.filter(data => data.get('action') === 'faluss_studio_v3_manage');
            const waitIdle = () => page.waitForFunction(() => document.querySelector('[data-link-import]').getAttribute('aria-busy') === 'false');
            const paste = async value => {
                if (await host.locator('[data-import-preview]').isVisible()) { await host.locator('[data-import-back]').click(); }
                await host.locator('[data-import-method="text"]').click();
                await source.fill(value);
            };
            const expand = async row => {
                if (!await row.locator('details').evaluate(element => element.open)) { await row.locator('summary').click(); }
            };
            const analyse = async () => { await host.locator('[data-import-analyse]').click(); await waitIdle(); };

            // The disclosure can be opened with a keyboard; no source is persisted yet.
            await host.locator('.faluss-link-import__entry').focus();
            await page.keyboard.press('Enter');
            assert.equal(await host.evaluate(element => element.open), true);
            assert.equal(await commit.isDisabled(), true);
            assert.equal(await host.locator('[data-import-notice]').getAttribute('aria-live'), 'polite');
            assert.equal(await host.locator('[data-import-source]').isVisible(), false);
            assert.equal(await host.locator('[data-import-method-panel="file"]').isVisible(), true);
            assert.equal(await host.locator('[data-import-analyse]').isDisabled(), true, 'Empty source has no active action');
            await host.locator('[data-import-method="file"]').focus();
            await page.keyboard.press('ArrowRight');
            assert.equal(await host.locator('[data-import-method="text"]').getAttribute('aria-selected'), 'true');
            assert.equal(await host.locator('[data-import-method="text"]').evaluate(element => document.activeElement === element), true);
            await page.keyboard.press('Home');
            assert.equal(await host.locator('[data-import-method="file"]').getAttribute('aria-selected'), 'true');
            await paste('Mon site https://example.com/site\nMa vidéo https://example.com/video');
            assert.equal(commitCalls().length, 0, 'Typing cannot persist links');
            await analyse();
            assert.equal(await rows.count(), 5);
            assert.equal(await commit.isEnabled(), true);
            assert.equal(await rows.nth(0).getByRole('spinbutton', {name: 'Ordre', includeHidden: true}).inputValue(), '1', 'Initial order is presented from one while preserving source sequence');
            assert.match(await host.locator('[data-import-summary]').innerText(), /3 prêts.*1 doublon.*1 à corriger/s);
            assert.equal(previewCalls()[0].get('nonce'), config.importNonce);
            assert.equal(previewCalls()[0].get('format'), 'auto');
            assert.equal(await rows.nth(2).getByRole('checkbox').isChecked(), false);
            assert.equal(await rows.nth(3).getByRole('checkbox').isChecked(), false);
            assert.equal(await rows.nth(3).getByRole('textbox', {name: 'URL HTTPS', includeHidden: true}).getAttribute('aria-invalid'), 'true');
            assert.equal(await rows.nth(0).getByRole('textbox', {name: 'Titre', exact: true, includeHidden: true}).inputValue(), literalTitle);
            assert.equal(await host.locator('img, script').count(), 0, 'Untrusted title stays literal input text');
            assert.equal(await page.evaluate(() => window.importXss), undefined);
            assert.equal(await host.locator('input, textarea, select').evaluateAll(elements => elements.every(element => element.labels && element.labels.length > 0)), true, 'Every importer field has a semantic label');

            await rows.nth(1).getByRole('checkbox').uncheck();
            assert.equal(await rows.nth(1).locator('details').evaluate(element => element.open), false, 'Selection does not open the editor');
            await expand(rows.nth(3));
            await rows.nth(3).getByRole('textbox', {name: 'URL HTTPS', includeHidden: true}).fill('https://example.com/corrected');
            await expand(rows.nth(0));
            await rows.nth(0).getByRole('spinbutton', {name: 'Ordre'}).fill('6');
            assert.equal(await commit.isDisabled(), true, 'An edited draft requires server revalidation');
            const expandedOverflow = await rows.locator('input').evaluateAll(controls => controls.filter(control => control.checkVisibility() && control.getBoundingClientRect().right > innerWidth).map(control => ({html:control.outerHTML, right:control.getBoundingClientRect().right, width:control.getBoundingClientRect().width})));
            assert.deepEqual(expandedOverflow, [], `Expanded row editors fit at ${width}px`);
            assert.equal(await host.locator('[data-import-commit]').isVisible(), false, 'Revalidation replaces the commit action until edits are checked');
            await host.locator('[data-import-review]').click();
            await waitIdle();
            assert.equal(await rows.nth(1).getByRole('checkbox').isChecked(), false, 'Explicit exclusions survive revalidation');
            assert.equal(await rows.nth(3).getAttribute('data-import-status'), 'ready');
            await rows.nth(3).getByRole('checkbox').check();
            const revalidated = JSON.parse(previewCalls()[1].get('rows'));
            assert.deepEqual(Object.keys(revalidated[0]).sort(), ['label', 'order', 'url']);
            assert.equal(revalidated[0].order, 6);
            assert.equal(revalidated[3].url, 'https://example.com/corrected');
            const metrics = await page.evaluate(() => {
                const host = document.querySelector('[data-link-import]');
                return {document: document.documentElement.scrollWidth, viewport: innerWidth, panel: host.getBoundingClientRect().right,
                    fieldsFit: Array.from(host.querySelectorAll('input, textarea, select, button')).filter(control => control.checkVisibility()).every(control => control.getBoundingClientRect().right <= innerWidth),
                    overflow: Array.from(host.querySelectorAll('input, textarea, select, button')).filter(control => control.checkVisibility() && control.getBoundingClientRect().right > innerWidth).map(control => ({html:control.outerHTML, right:control.getBoundingClientRect().right}))};
            });
            assert(metrics.document <= metrics.viewport, `Document must not overflow at ${width}px`);
            assert(metrics.panel <= metrics.viewport && metrics.fieldsFit, `Importer controls must fit at ${width}px: ${JSON.stringify(metrics)}`);
            assert.equal(await host.locator('[data-import-source-stage]').isVisible(), false, 'Review is a separate step');
            assert.equal(await host.locator('[data-import-review]').isVisible(), false, 'Only one primary action is visible after validation');
            assert.equal(await rows.locator('details[open]').count(), 0, 'Validated links use compact summaries');
            assert(await rows.nth(0).evaluate(element => element.getBoundingClientRect().height <= 100), 'Collapsed rows stay compact');
            await page.screenshot({path: path.join(evidence, `preview-${width}.png`), fullPage: true});

            holdCommit = new Promise(resolve => { releaseCommit = resolve; });
            const committing = page.waitForRequest(request => request.method() === 'POST' && new URLSearchParams(request.postData()).get('action') === 'faluss_studio_v3_manage');
            await commit.evaluate(button => { button.click(); button.click(); });
            await committing;
            await page.waitForFunction(() => document.querySelector('[data-link-import]').getAttribute('aria-busy') === 'true');
            assert.equal(commitCalls().length, 1, 'A rapid double click sends exactly one commit');
            assert.equal(await commit.isDisabled(), true);
            const sent = commitCalls()[0];
            assert.equal(sent.get('nonce'), config.managementNonce);
            assert.equal(sent.get('version'), 'a'.repeat(64));
            assert.equal(sent.get('mutation'), 'import_links');
            const submitted = JSON.parse(sent.get('fields')).rows;
            assert.equal(submitted.length, 3);
            assert.deepEqual(submitted.map(row => row.label), [literalTitle, 'À corriger', 'Mes articles']);
            assert.deepEqual(Object.keys(submitted[0]).sort(), ['label', 'order', 'url']);
            assert.equal(submitted[0].order, 6);
            assert.equal(canonicalReads, 0, 'Canonical reload waits for confirmed persistence');
            releaseCommit();
            holdCommit = null;
            await page.waitForFunction(() => document.querySelector('[data-v3-status]').textContent.includes('3 liens importés'));
            assert.equal(canonicalReads, 1);
            assert.equal(await host.locator('[data-import-preview]').isVisible(), false, 'Confirmed import replaces the preview with a fresh canonical view');

            // Server rejection retains the editable draft and permits a deliberate retry.
            await host.locator('.faluss-link-import__entry').click();
            await paste('Nouveau lot https://example.com/next');
            await analyse();
            const retained = await rows.nth(0).getByRole('textbox', {name: 'Titre', exact: true, includeHidden: true}).inputValue();
            commitError = 'stale_version';
            await commit.click();
            await waitIdle();
            assert.match(await host.locator('[data-import-notice]').innerText(), /autre session/);
            assert.equal(await rows.nth(0).getByRole('textbox', {name: 'Titre', exact: true, includeHidden: true}).inputValue(), retained);
            assert.equal(await commit.isEnabled(), true);
            assert.equal(canonicalReads, 1, 'A conflict does not discard the local preview');
            previewError = 'invalid_json';
            await paste('{broken');
            assert.equal(await commit.isDisabled(), true);
            await analyse();
            assert.match(await host.locator('[data-import-notice]').innerText(), /JSON est illisible/);
            assert.equal(await source.inputValue(), '{broken');
            assert.equal(await commit.isDisabled(), true);

            // Local file selection is bounded and never uploads the selected file.
            await host.locator('[data-import-method="file"]').click();
            await host.locator('[data-import-file]').setInputFiles({name: 'links.json', mimeType: 'application/json', buffer: Buffer.from('[{"title":"Mon site","url":"https://example.com"}]')});
            await waitIdle();
            assert.equal(await host.locator('[data-import-file-name]').innerText(), 'links.json');
            assert.equal(await source.inputValue(), '{broken', 'A file never replaces a separately pasted draft');
            await host.locator('[data-import-method="text"]').click();
            assert.equal(await source.inputValue(), '{broken');
            await host.locator('[data-import-method="file"]').click();
            assert.equal(await host.locator('[data-import-file-name]').innerText(), 'links.json');
            assert.equal(await host.locator('[data-import-format]').inputValue(), 'json');
            const previewCount = previewCalls().length;
            await page.evaluate(() => {
                window.importOriginalFileText = File.prototype.text;
                File.prototype.text = function () {
                    return this.name === 'delayed.json' ? new Promise(resolve => { window.releaseImportFile = resolve; }) : window.importOriginalFileText.call(this);
                };
            });
            await host.locator('[data-import-file]').setInputFiles({name: 'delayed.json', mimeType: 'application/json', buffer: Buffer.from('[]')});
            assert.equal(await source.isDisabled(), true, 'An unfinished file read locks source editing');
            assert.equal(await host.locator('[data-import-analyse]').isDisabled(), true);
            assert.equal(await commit.isDisabled(), true, 'The previous preview cannot be committed during a file read');
            await page.evaluate(() => { window.releaseImportFile('[{"title":"Lecture terminée","url":"https://example.com/read"}]'); File.prototype.text = window.importOriginalFileText; });
            await waitIdle();
            assert.equal(await host.locator('[data-import-file-name]').innerText(), 'delayed.json');
            assert.equal(await source.inputValue(), '{broken', 'File reads preserve the paste draft');
            assert.equal(await commit.isDisabled(), true, 'A newly read file still needs an explicit preview');
            await host.locator('[data-import-file]').setInputFiles({name: 'large.csv', mimeType: 'text/csv', buffer: Buffer.alloc(524289, 97)});
            assert.match(await host.locator('[data-import-notice]').innerText(), /dépasse 512 Ko/);
            assert.equal(await host.locator('[data-import-analyse]').isDisabled(), true, 'Rejected file cannot reuse a previous file source');
            await host.locator('[data-import-file]').setInputFiles({name: 'links.html', mimeType: 'text/html', buffer: Buffer.from('<a href="https://example.com">Lien</a>')});
            assert.match(await host.locator('[data-import-notice]').innerText(), /fichier CSV ou JSON/);
            assert.equal(previewCalls().length, previewCount, 'Selecting or rejecting a file performs no automatic network request');
            const currentUrl = page.url();
            await page.locator('.faluss-studio-v3__bottom a').filter({hasText: 'Design'}).click();
            assert.equal(dialogs.length, 1, 'Unsaved import participates in the Studio navigation guard');
            assert.match(dialogs[0], /sans enregistrer/);
            assert.equal(page.url(), currentUrl, 'Cancelling navigation keeps the import source');
            assert.equal(await host.locator('[data-import-format]').inputValue(), 'json');
            const commitsBeforeOtherSave = commitCalls().length;
            const editor = page.locator('[data-mutation="create_link"]');
            await editor.locator('summary').click();
            await editor.locator('[data-v3-save-item]').click();
            assert.equal(dialogs.length, 2);
            assert.match(dialogs[1], /abandonner l’aperçu d’import/);
            assert.equal(commitCalls().length, commitsBeforeOtherSave, 'Cancelling another element save preserves the import draft without persistence');

            previewError = null; commitError = null;
            await paste('Après lecture https://example.com/confirmed');
            await analyse();
            failCanonicalRead = true;
            await commit.click();
            await page.waitForFunction(() => document.querySelector('[data-studio-refresh]'));
            assert.match(await page.locator('[data-v3-error]').innerText(), /Enregistré.*relecture est indisponible/);
            assert.equal(await source.isDisabled(), true);
            assert.equal(await commit.isDisabled(), true, 'A confirmed write with unavailable canonical reread must prevent duplicate retries');
            failCanonicalRead = false;
            await page.locator('[data-studio-refresh]').click();
            await page.waitForFunction(() => !document.querySelector('[data-studio-refresh]'));
            assert.equal(await host.locator('[data-import-preview]').isVisible(), false, 'A successful canonical refresh unlocks a clean view');
            assert.equal(canonicalReads, 3);
            assert.deepEqual(errors, []);
            results.push({width, mixedRows: true, literalXss: true, editedRevalidation: true, exclusions: true, nonceAndVersion: true,
                doubleClick: true, canonicalReload: true, conflictsRetainDraft: true, fileLimits: true, navigationGuard: true,
                fileReadLock: true, otherEditorDraftGuard: true, failedCanonicalReadLock: true, canonicalRetry: true,
                sourceTabsKeyboard: true, separateSourceDrafts: true, compactReview: true, singlePrimaryAction: true, semanticLabels: true, noOverflow: true, pageErrors: errors});
            await context.close();
        }
        fs.writeFileSync(path.join(evidence, 'browser-results.json'), JSON.stringify({engine: 'chromium', transport: 'mocked AJAX; real PHP views and shipped assets', results}, null, 2) + '\n');
        console.log('Link import browser integration: preview, edits, exclusion, XSS, nonce/version, one commit, conflicts, files, navigation and 320/390/768 responsive: OK');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
