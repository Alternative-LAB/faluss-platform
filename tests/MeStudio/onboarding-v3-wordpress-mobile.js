'use strict';

// Touch and viewport checks against a real, isolated WordPress/MariaDB Networks step.
const path = require('path');
const { chromium } = require('playwright');
const base = process.env.FALUSS_V3_WP_BASE;
const user = process.env.FALUSS_V3_WP_USER;
const password = process.env.FALUSS_V3_WP_PASSWORD;
const pageId = process.env.FALUSS_V3_WP_PAGE;
const onboardingPath = process.env.FALUSS_V3_WP_PATH || '/?page_id=' + pageId;
const chrome = process.env.FALUSS_BROWSER_EXECUTABLE || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if (!base || !user || !password || !pageId) { throw new Error('Set FALUSS_V3_WP_BASE, USER, PASSWORD and PAGE'); }
function assert(value, message) { if (!value) { throw new Error(message); } }

(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: chrome });
    const results = [];
    try {
        for (const width of [320, 390, 768]) {
            const context = await browser.newContext({ viewport: { width, height: 844 }, hasTouch: true, isMobile: true, reducedMotion: 'reduce' });
            const page = await context.newPage();
            await page.goto(base + '/wp-login.php');
            await page.locator('#user_login').fill(user);
            await page.locator('#user_pass').fill(password);
            await Promise.all([page.waitForNavigation(), page.locator('#wp-submit').click()]);
            await page.goto(base + onboardingPath);
            await page.waitForLoadState('networkidle');
            assert(await page.locator('[data-faluss-onboarding-v3]').getAttribute('data-step') === 'v3_socials', 'Networks step missing');
            await page.locator('[data-v3-network-choice]').evaluateAll((inputs) => {
                inputs.forEach((input) => {
                    input.checked = true;
                    input.closest('[data-network]').querySelector('[data-v3-network-url]').hidden = false;
                });
            });
            const initial = await page.evaluate(() => {
                const panel = document.querySelector('[data-v3-panel]');
                return { panelTop: panel.getBoundingClientRect().top, panelRight: panel.getBoundingClientRect().right,
                    overflow: document.documentElement.scrollWidth - innerWidth,
                    scrollMode: getComputedStyle(document.querySelector('[data-v3-scroll]')).overflowY };
            });
            assert(initial.overflow <= 1 && initial.scrollMode === 'hidden', width + ': compact geometry invalid');
            const cdp = await context.newCDPSession(page);
            async function swipe(start, end) {
                const x = Math.round(initial.panelRight - 12);
                await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y: start }] });
                for (let i = 1; i <= 10; i += 1) {
                    await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x, y: start + (end - start) * i / 10 }] });
                    await page.waitForTimeout(12);
                }
                await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
                await page.waitForTimeout(120);
            }
            await swipe(initial.panelTop + 95, initial.panelTop - 185);
            const first = await page.evaluate(() => ({
                expanded: document.querySelector('[data-v3-panel]').classList.contains('is-expanded'),
                top: document.querySelector('[data-v3-scroll]').scrollTop,
                mode: getComputedStyle(document.querySelector('[data-v3-scroll]')).overflowY
            }));
            assert(first.expanded && first.top === 0 && first.mode === 'auto', width + ': first touch did not expand before scroll');
            if (width === 390) { await page.screenshot({ path: path.resolve(__dirname, '../../docs/evidence/onboarding-v3/wordpress-networks-390-expanded.png') }); }
            for (let i = 0; i < 6; i += 1) { await swipe(600, 180); }
            const scrolled = await page.evaluate(() => {
                const element = document.querySelector('[data-v3-scroll]');
                const action = document.querySelector('[data-v3-primary]');
                return { top: element.scrollTop, max: element.scrollHeight - element.clientHeight,
                    actionBottom: action.getBoundingClientRect().bottom };
            });
            assert(scrolled.max > 0 && scrolled.top >= scrolled.max - 2, width + ': Networks did not scroll fully');
            assert(scrolled.actionBottom <= 845, width + ': action outside viewport');
            if (width === 390) { await page.screenshot({ path: path.resolve(__dirname, '../../docs/evidence/onboarding-v3/wordpress-networks-390-scrolled.png') }); }
            results.push({ width, compactOverflow: initial.overflow, firstGesture: first, scrolled });
            await context.close();
        }
        const context = await browser.newContext({ viewport: { width: 390, height: 500 }, hasTouch: true, isMobile: true, reducedMotion: 'reduce' });
        const page = await context.newPage();
        await page.goto(base + '/wp-login.php');
        await page.locator('#user_login').fill(user);
        await page.locator('#user_pass').fill(password);
        await Promise.all([page.waitForNavigation(), page.locator('#wp-submit').click()]);
            await page.goto(base + onboardingPath);
        await page.waitForLoadState('networkidle');
        await page.locator('[data-v3-network-url]').first().evaluate((input) => { input.hidden = false; });
        await page.locator('[data-v3-network-url]').first().focus();
        const keyboard = await page.evaluate(() => ({ expanded: document.querySelector('[data-v3-panel]').classList.contains('is-expanded'),
            actionBottom: document.querySelector('[data-v3-primary]').getBoundingClientRect().bottom }));
        assert(!keyboard.expanded && keyboard.actionBottom <= 501, 'Focus must not expand the anchored sheet');
        results.push({ keyboardViewport: 500, keyboard });
        await context.close();
        console.log(JSON.stringify(results, null, 2));
    } finally { await browser.close(); }
})().catch((error) => { console.error(error); process.exitCode = 1; });
