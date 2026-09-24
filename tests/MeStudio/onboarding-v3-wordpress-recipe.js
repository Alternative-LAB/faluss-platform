'use strict';

// Run against an isolated WordPress/MariaDB site. Set the URL and local test account in env.
const path = require('path');
const fs = require('fs');
const { chromium } = require('playwright');

const base = process.env.FALUSS_V3_WP_BASE;
const user = process.env.FALUSS_V3_WP_USER;
const password = process.env.FALUSS_V3_WP_PASSWORD;
const pageId = process.env.FALUSS_V3_WP_PAGE;
const onboardingPath = process.env.FALUSS_V3_WP_PATH || '/?page_id=' + pageId;
const mode = process.env.FALUSS_V3_WP_MODE || 'simple';
const auth = process.env.FALUSS_V3_WP_AUTH || 'wordpress';
const mailbox = process.env.FALUSS_V3_WP_MAILBOX;
const chrome = process.env.FALUSS_BROWSER_EXECUTABLE || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const root = path.resolve(__dirname, '../..');
if (!base || !user || (!password && auth !== 'passwordless') || !pageId || !['simple', 'atomic'].includes(mode) || !['wordpress', 'passwordless'].includes(auth)) {
    throw new Error('Set FALUSS_V3_WP_BASE, USER, PAGE, MODE and the selected authentication inputs');
}
function assert(value, message) { if (!value) { throw new Error(message); } }

(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: chrome });
    const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const page = await context.newPage();
    const requests = [];
    page.on('response', async (response) => {
        if (!response.url().includes('admin-ajax.php')) { return; }
        const post = response.request().postData() || '';
        const action = new URLSearchParams(post).get('action') || 'multipart';
        const raw = await response.text().catch(() => '');
        const failed = response.status() >= 400 || raw.startsWith('{"success":false');
        requests.push({ at: new Date().toISOString(), action, status: response.status(), ...(failed && action !== 'multipart' ? { body: raw } : {}) });
    });
    async function authenticate(target) {
        if (auth === 'passwordless') {
            assert(mailbox, 'A local passwordless mail sink is required');
            fs.rmSync(mailbox, { force: true });
            await target.goto(base + '/login/?redirect_to=' + encodeURIComponent(base + onboardingPath));
            assert(await target.locator('[data-faluss-login-stage="email"]').count() === 1, 'Passwordless email stage unavailable');
            await target.locator('[data-faluss-login-stage="email"] [name="email"]').fill(user);
            await target.locator('[data-faluss-login-stage="email"] button[type="submit"]').click();
            await target.waitForSelector('[data-faluss-login-stage="otp"]', { timeout: 12000 });
            const started = Date.now();
            while (!fs.existsSync(mailbox) && Date.now() - started < 12000) { await target.waitForTimeout(100); }
            assert(fs.existsSync(mailbox), 'Local test mail was not captured');
            const mail = JSON.parse(fs.readFileSync(mailbox, 'utf8'));
            fs.rmSync(mailbox, { force: true });
            assert(mail.to === user, 'Passwordless mail recipient differs from requested account');
            const match = String(mail.message || '').match(/\b[0-9]{6}\b/);
            assert(match, 'Passwordless mail has no six-digit code');
            await target.locator('[data-faluss-login-stage="otp"] [name="otp"]').fill(match[0]);
            await target.locator('[data-faluss-login-stage="otp"] button[type="submit"]').first().click();
            await target.waitForURL((url) => url.pathname === '/commencer/', { timeout: 12000 });
        } else {
            await target.goto(base + '/wp-login.php');
            await target.locator('#user_login').fill(user);
            await target.locator('#user_pass').fill(password);
            await Promise.all([target.waitForNavigation(), target.locator('#wp-submit').click()]);
        }
    }
    try {
        await authenticate(page);
        await page.goto(base + onboardingPath);
        await page.waitForSelector('[data-faluss-onboarding-v3]', { timeout: 10000 });
        await page.waitForLoadState('networkidle');
        const step = await page.locator('[data-faluss-onboarding-v3]').getAttribute('data-step');
        const config = await page.evaluate(() => window.falussOnboardingV3 || {});
        assert(config.transitionNonce && config.previewNonce, 'Missing V3 AJAX config');
        assert(['v3_mode', 'v3_socials', 'v3_colors', 'v3_review'].includes(step), 'Account must resume in V3, got ' + step);
        async function action(expected, selector = '[data-v3-primary]') {
            await page.locator(selector).click();
            await page.waitForSelector('[data-step="' + expected + '"]', { timeout: 12000 });
            await page.waitForLoadState('networkidle');
            assert(await page.locator('[data-faluss-onboarding-v3]').getAttribute('data-step') === expected, 'Wrong V3 step after ' + selector);
        }
        let slug = '';
        let avatarId = 0;
        if (step === 'v3_mode') {
            await page.reload();
            await page.waitForLoadState('networkidle');
            assert(await page.locator('[data-faluss-onboarding-v3]').getAttribute('data-step') === 'v3_mode', 'Mode lost after reload');
            await page.locator('input[name="structure"][value="' + mode + '"]').check({ force: true });
            await action('v3_identity');
            const displayName = 'Recette ' + mode;
            slug = 'v3-' + mode + '-' + Date.now().toString(36);
            await page.locator('[name="display_name"]').fill(displayName);
            await page.locator('[name="public_slug"]').fill(slug);
            await page.waitForResponse((response) => response.url().includes('admin-ajax.php') && (response.request().postData() || '').includes('faluss_onboarding_v3_identity_draft') && response.status() === 200);
            await page.reload();
            await page.waitForLoadState('networkidle');
            assert(await page.locator('[name="display_name"]').inputValue() === displayName, 'Identity name lost after reload');
            assert(await page.locator('[name="public_slug"]').inputValue() === slug, 'Identity slug lost after reload');
            await action('v3_mode', '[data-v3-back]');
            await action('v3_identity');
            assert(await page.locator('[name="display_name"]').inputValue() === displayName, 'Identity name lost after Back');
            assert(await page.locator('[name="public_slug"]').inputValue() === slug, 'Identity slug lost after Back');
            assert(!(await page.locator('[name="public_slug"]').isDisabled()) && !(await page.locator('[name="public_slug"]').getAttribute('readonly')), 'Unclaimed slug became readonly');
            const avatar = process.env.FALUSS_V3_AVATAR_PATH || path.join(root, 'assets/images/pf/faluss-pf-badge.png');
            const oldAvatarId = Number(await page.locator('[name="avatar_attachment_id"]').inputValue());
            await page.locator('[data-v3-upload="avatar"]').setInputFiles(avatar);
            await page.waitForFunction((oldId) => {
                const current = Number(document.querySelector('[name="avatar_attachment_id"]').value);
                return current > 0 && current !== oldId;
            }, oldAvatarId, { timeout: 12000 });
            avatarId = Number(await page.locator('[name="avatar_attachment_id"]').inputValue());
            await page.waitForFunction(() => (document.querySelector('[data-v3-status]')?.textContent || '').includes('Image prête.'), null, { timeout: 12000 });
            await page.reload();
            await page.waitForLoadState('networkidle');
            assert(await page.locator('[name="display_name"]').inputValue() === displayName, 'Identity name lost after avatar reload');
            assert(await page.locator('[name="public_slug"]').inputValue() === slug, 'Identity slug lost after avatar reload');
            const reloadedAvatar = await page.locator('[name="avatar_attachment_id"]').evaluate((field) => ({ value: field.value, attribute: field.getAttribute('value') }));
            assert(Number(reloadedAvatar.value) === avatarId, 'Avatar lost after reload: ' + JSON.stringify({ avatarId, reloadedAvatar }));
            await action('v3_socials');
        } else {
            slug = (await page.locator('[data-v3-preview] .faluss-link-card__handle').textContent()).trim().replace(/^@/, '');
        }
        if (process.env.FALUSS_V3_WP_STOP_AT_SOCIALS === 'true') {
            assert(await page.locator('[data-faluss-onboarding-v3]').getAttribute('data-step') === 'v3_socials', 'Expected Networks pause');
            console.log(JSON.stringify({ mode, slug, avatarId, pausedAt: 'v3_socials' }));
            return;
        }
        if (step !== 'v3_review' && step !== 'v3_colors') {
        assert(await page.locator('[data-network]').count() >= 2, 'Link social catalog missing');
        assert(await page.locator('[data-network="instagram"] img').count() === 1, 'Official Instagram catalog media absent');
        assert(await page.locator('[data-network="telegram"] img').count() === 1, 'Official Telegram catalog media absent');
        await action('v3_links', '[data-v3-skip]');
        await page.reload();
        await page.waitForLoadState('networkidle');
        assert(await page.locator('[data-faluss-onboarding-v3]').getAttribute('data-step') === 'v3_links', 'Links lost after reload');
        await action('v3_socials', '[data-v3-back]');
        for (const [network, url] of [['instagram', 'https://instagram.com/recette'], ['telegram', 'https://t.me/recette']]) {
            const row = page.locator('[data-network="' + network + '"]');
            await row.locator('[data-v3-network-choice]').check();
            await row.locator('[data-v3-network-url]').fill(url);
        }
        await page.waitForFunction(() => document.querySelectorAll('[data-v3-preview] .faluss-link-card__social img').length >= 2);
        await action('v3_links');
        await action('v3_socials', '[data-v3-back]');
        assert(await page.locator('[data-network="instagram"] [data-v3-network-choice]').isChecked(), 'Instagram lost after Back');
        assert(await page.locator('[data-network="telegram"] [data-v3-network-choice]').isChecked(), 'Telegram lost after Back');
        await action('v3_links');
        await action(mode === 'simple' ? 'v3_review' : 'v3_colors', '[data-v3-skip]');
        await action('v3_links', '[data-v3-back]');
        await page.locator('[data-v3-add-link]').click();
        await page.locator('[data-v3-link-label]').last().fill('Premier lien');
        await page.locator('[data-v3-link-url]').last().fill('https://example.test/premier');
        await page.locator('[data-v3-add-link]').click();
        await page.locator('[data-v3-link-label]').last().fill('Second lien');
        await page.locator('[data-v3-link-url]').last().fill('https://example.test/second');
        await page.locator('[data-v3-link]').last().locator('[data-v3-move="up"]').click();
        await action(mode === 'simple' ? 'v3_review' : 'v3_colors');
        await action('v3_links', '[data-v3-back]');
        assert(await page.locator('[data-v3-link-label]').first().inputValue() === 'Second lien', 'Link ordering lost after Back');
        await action(mode === 'simple' ? 'v3_review' : 'v3_colors');
        await page.reload();
        await page.waitForLoadState('networkidle');
        assert(await page.locator('[data-faluss-onboarding-v3]').getAttribute('data-step') === (mode === 'simple' ? 'v3_review' : 'v3_colors'), 'Step lost after reload');
        }
        let coverId = 0;
        if (mode === 'atomic') {
            await page.locator('input[name="page_background"][value="#DED4E4"]').check({ force: true });
            await page.locator('[data-v3-tab="buttons"]').click();
            await page.locator('input[name="button_color"][value="#FF515B"]').check({ force: true });
            await action('v3_buttons');
            await page.reload(); await page.waitForLoadState('networkidle');
            await action('v3_colors', '[data-v3-back]');
            assert(await page.locator('input[name="page_background"][value="#DED4E4"]').isChecked(), 'Background lost after Back');
            await action('v3_buttons');
            await page.locator('input[name="link_style"][value="outline"]').check({ force: true });
            await page.locator('[data-v3-tab="texture"]').click();
            await page.locator('input[name="button_texture"][value="grain"]').check({ force: true });
            await action('v3_avatar');
            await page.reload(); await page.waitForLoadState('networkidle');
            await action('v3_buttons', '[data-v3-back]');
            assert(await page.locator('input[name="link_style"][value="outline"]').isChecked(), 'Button style lost after Back');
            await action('v3_avatar');
            await action('v3_wallpaper', '[data-v3-skip]');
            await page.reload(); await page.waitForLoadState('networkidle');
            await action('v3_avatar', '[data-v3-back]');
            await page.locator('input[name="avatar_shape"][value="rounded"]').check({ force: true });
            await page.locator('[data-v3-tab="effects"]').click();
            await page.locator('input[name="avatar_effect"][value="shadow"]').check({ force: true });
            await action('v3_wallpaper');
            await action('v3_name', '[data-v3-skip]');
            await action('v3_wallpaper', '[data-v3-back]');
            const cover = process.env.FALUSS_V3_COVER_PATH || path.join(root, 'assets/link/images/faluss-onboarding-device.png');
            await page.locator('[data-v3-upload="cover"]').setInputFiles(cover);
            await page.waitForFunction(() => Number(document.querySelector('[name="cover_attachment_id"]').value) > 0, null, { timeout: 12000 });
            coverId = Number(await page.locator('[name="cover_attachment_id"]').inputValue());
            await page.locator('input[name="wallpaper_size"][value="cover"]').check({ force: true });
            await action('v3_name');
            await page.reload(); await page.waitForLoadState('networkidle');
            await action('v3_wallpaper', '[data-v3-back]');
            assert(Number(await page.locator('[name="cover_attachment_id"]').inputValue()) === coverId, 'Cover lost after Back');
            await action('v3_name');
            await page.locator('select[name="name_font"]').selectOption('system');
            await page.locator('input[name="name_weight"][value="400"]').check({ force: true });
            await page.locator('input[name="name_color"][value="#F54955"]').locator('xpath=..').click();
            assert(await page.locator('input[name="name_color"][value="#F54955"]').isChecked(), 'Name color was not selected');
            await action('v3_network_style');
            await page.reload(); await page.waitForLoadState('networkidle');
            await action('v3_name', '[data-v3-back]');
            assert(await page.locator('input[name="name_weight"][value="400"]').isChecked(), 'Name weight lost after Back');
            await action('v3_network_style');
            await action('v3_review', '[data-v3-skip]');
            await action('v3_network_style', '[data-v3-back]');
            await page.locator('input[name="social_style"][value="brand-dark"]').check({ force: true });
            await page.locator('[data-v3-tab="color"]').click();
            await page.locator('input[name="social_color"][value="#FF515B"]').check({ force: true });
            await action('v3_review');
            await page.reload(); await page.waitForLoadState('networkidle');
        }
        {
            const second = await browser.newContext({ viewport: { width: 390, height: 844 } });
            const rival = await second.newPage();
            await authenticate(rival);
            await rival.goto(base + onboardingPath);
            await rival.waitForLoadState('networkidle');
            assert(await rival.locator('[data-faluss-onboarding-v3]').getAttribute('data-step') === 'v3_review', 'Second session did not see review');
            const rivalState = await rival.evaluate(() => ({ nonce: window.falussOnboardingV3.publishNonce, version: document.querySelector('[data-faluss-onboarding-v3]').dataset.version }));
            const ownState = await page.evaluate(() => ({ nonce: window.falussOnboardingV3.publishNonce, version: document.querySelector('[data-faluss-onboarding-v3]').dataset.version }));
            assert(rivalState.version === ownState.version, 'Concurrent sessions did not start from same version');
            await action(mode === 'simple' ? 'v3_links' : 'v3_network_style', '[data-v3-back]');
            if (mode === 'simple') {
                await page.locator('[data-v3-link-label]').first().fill('Second lien corrigé');
            } else {
                await page.locator('input[name="social_style"][value="mono-dark"]').check({ force: true });
            }
            await action('v3_review');
            const stale = await second.request.post(base + '/wp-admin/admin-ajax.php', { form: {
                action: 'faluss_onboarding_v3_publish', nonce: rivalState.nonce, version: rivalState.version
            } });
            const staleAt = new Date().toISOString();
            const staleRaw = await stale.text();
            assert(stale.status() === 409 && JSON.parse(staleRaw).data.code === 'stale_version', 'Stale publication must return HTTP 409');
            const badUpload = await context.request.post(base + '/wp-admin/admin-ajax.php', { multipart: {
                action: 'faluss_onboarding_v3_upload_avatar', nonce: (await page.evaluate(() => window.falussOnboardingV3.avatarNonce)),
                avatar: { name: 'refuse.txt', mimeType: 'text/plain', buffer: Buffer.from('not an image') }
            } });
            const badAt = new Date().toISOString();
            const badRaw = await badUpload.text();
            assert(badUpload.status() >= 400 && JSON.parse(badRaw).success === false, 'Non-image upload must be refused');
            async function cardSnapshot(selector) {
                return page.locator(selector).first().evaluate((card) => ({
                    name: card.querySelector('.faluss-link-card__name').textContent.trim(),
                    handle: card.querySelector('.faluss-link-card__handle').textContent.trim(),
                    links: Array.from(card.querySelectorAll('.faluss-link-card__link')).map((link) => ({ label: link.textContent.trim(), href: link.href })),
                    socials: Array.from(card.querySelectorAll('.faluss-link-card__social a')).map((link) => ({ href: link.href, image: new URL(link.querySelector('img').src).pathname })),
                    avatar: !!card.querySelector('.faluss-link-card__avatar img'),
                    cover: !!card.querySelector('.faluss-link-card__cover img'),
                    visualClasses: Array.from(card.classList).filter((name) => name.startsWith('faluss-link-card--') && !name.includes('density') && !name.includes('presentation')).sort(),
                    variables: ['--fl-page-background', '--fl-action', '--fl-name-color', '--fl-name-font', '--fl-name-weight'].map((name) => [name, card.style.getPropertyValue(name).trim()])
                }));
            }
            const review = await cardSnapshot('[data-v3-preview] .faluss-link-card');
            const currentVersion = await page.locator('[data-faluss-onboarding-v3]').getAttribute('data-version');
            const publish = await context.request.post(base + '/wp-admin/admin-ajax.php', { form: {
                action: 'faluss_onboarding_v3_publish', nonce: ownState.nonce, version: currentVersion
            } });
            const published = await publish.json();
            assert(publish.status() === 200 && published.success, 'First publication failed');
            const repeated = await context.request.post(base + '/wp-admin/admin-ajax.php', { form: {
                action: 'faluss_onboarding_v3_publish', nonce: ownState.nonce, version: currentVersion
            } });
            const repeatedJson = await repeated.json();
            assert(repeated.status() === 200 && repeatedJson.success && repeatedJson.data.public_url === published.data.public_url, 'Repeated publication must be idempotent');
            await page.reload();
            await page.waitForLoadState('networkidle');
            assert(await page.locator('[data-faluss-onboarding-v3]').getAttribute('data-step') === 'v3_success', 'Published card did not reach V3 success');
            const publicResponse = await page.goto(published.data.public_url);
            assert(publicResponse.status() === 200, 'Public profile returned non-200');
            const publicCard = await cardSnapshot('.faluss-link-card');
            assert(JSON.stringify(review) === JSON.stringify(publicCard), 'Review and public card content differ');
            let shortcodeCard = null;
            let widgetCard = null;
            if (process.env.FALUSS_V3_WP_SKIP_INTEGRATION_PARITY !== 'true') {
                await page.goto(base + '/shortcode-' + mode + '/');
                shortcodeCard = await cardSnapshot('.faluss-link-card');
                assert(JSON.stringify(publicCard) === JSON.stringify(shortcodeCard), 'Shortcode and public card differ');
                await page.goto(base + '/widget-' + mode + '/');
                widgetCard = await cardSnapshot('.elementor-widget-faluss_link_card .faluss-link-card');
                assert(JSON.stringify(publicCard) === JSON.stringify(widgetCard), 'Elementor widget and public card differ');
            }
            console.log(JSON.stringify({ mode, slug, avatarId, coverId, publicUrl: published.data.public_url, review, publicCard, shortcodeCard, widgetCard,
                stalePublish: { at: staleAt, status: stale.status(), raw: staleRaw },
                refusedUpload: { at: badAt, status: badUpload.status(), raw: badRaw },
                publish: { status: publish.status(), code: published.data.code }, repeat: { status: repeated.status(), code: repeatedJson.data.code } }, null, 2));
            await second.close();
        }
    } finally {
        console.log(JSON.stringify({ requests }, null, 2));
        await browser.close();
    }
})().catch((error) => { console.error(error); process.exitCode = 1; });
