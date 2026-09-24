'use strict';

// Visual contract for V3 markup and controls. The card and media are synthetic:
// this is not a WordPress/MariaDB or public-profile integration test.
const fs = require('fs');
const path = require('path');
const childProcess = require('child_process');
const { chromium, webkit } = require('playwright');

const root = path.resolve(__dirname, '../..');
const php = process.env.FALUSS_PHP || 'php';
const fixture = JSON.parse(childProcess.execFileSync(php, [path.join(__dirname, 'onboarding-v3-fixture.php')], { encoding: 'utf8' }));
const css = [
    'assets/link/css/faluss-link.css',
    'assets/me-studio/css/card-v2.css',
    'assets/me-studio/css/onboarding-v3.css'
].map((file) => fs.readFileSync(path.join(root, file), 'utf8')).join('\n');
const js = fs.readFileSync(path.join(root, 'assets/me-studio/js/onboarding-v3.js'), 'utf8');
const chrome = [
    process.env.FALUSS_BROWSER_EXECUTABLE,
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe'
].find((candidate) => candidate && fs.existsSync(candidate));
if (!chrome || !fs.existsSync(webkit.executablePath())) { throw new Error('Chromium and WebKit are required'); }

function assert(condition, message) { if (!condition) { throw new Error(message); } }
function escapeHtml(text) { return String(text).replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character])); }

const avatar = 'data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect width="100" height="100" fill="#d0c9ca"/><circle cx="50" cy="36" r="20" fill="#302d30"/><path d="M13 100c3-32 18-43 37-43s34 11 37 43" fill="#302d30"/></svg>');
function card() {
    return `<article class="faluss-link-card faluss-link-card--structure-atomic faluss-link-card--align-center faluss-link-card--links-outline faluss-link-card--density-comfortable faluss-link-card--cover-no faluss-link-card--avatar-yes faluss-link-card--avatar-border-yes faluss-link-card--texture-smooth faluss-link-card--avatar-shape-round faluss-link-card--avatar-effect-border faluss-link-card--wallpaper-cover faluss-link-card--wallpaper-effect-gradient faluss-link-card--social-style-brand-light faluss-link-card--links-mode-neutral faluss-link-card--link-width-wide" style="--fl-page-background:#ded4e4;--fl-canvas:#ded4e4;--fl-action:#080808;--fl-name-color:#f54955;--fl-name-font:system-ui;--fl-name-weight:700;--fl-avatar-border-color:#fff">
    <div class="faluss-link-card__cover" hidden></div><div class="faluss-link-card__body">
    <div class="faluss-link-card__avatar"><img src="${avatar}" alt=""></div>
    <h2 class="faluss-link-card__name faluss-link-card__name--strong">Dylan</h2><p class="faluss-link-card__handle">@dylan</p>
    <div class="faluss-link-card__content-blocks faluss-link-card__links"><a class="faluss-link-card__link" href="#"><span>Mon travail</span></a><a class="faluss-link-card__link" href="#"><span>Ma boutique</span></a><a class="faluss-link-card__link" href="#"><span>Mes vidéos</span></a></div>
    </div></article>`;
}

function html(mode, step, controls) {
    const steps = mode === 'atomic' ? Object.keys(fixture.atomic).filter((item) => item !== 'v3_success') : Object.keys(fixture.simple);
    const index = Math.max(0, steps.indexOf(step));
    const progress = steps.map((_, number) => `<span class="${number <= index ? 'is-complete' : ''}"></span>`).join('');
    const cta = step === 'v3_success' ? '' : '<button type="submit" class="faluss-onboarding-v3__primary" data-v3-primary>Continuer <span>→</span></button>';
    return `<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Fixture V3 ${escapeHtml(step)}</title><style>${css}</style></head><body>
    <section class="faluss-onboarding-v3" data-faluss-onboarding-v3 data-step="${step}" data-mode="${mode}" data-version="fixture">
      <header class="faluss-onboarding-v3__header"><button type="button" class="faluss-onboarding-v3__back" data-v3-back aria-label="Retour">←</button><div class="faluss-onboarding-v3__progress" role="progressbar" aria-label="Progression" aria-valuemin="1" aria-valuemax="${steps.length}" aria-valuenow="${index + 1}">${progress}</div><strong aria-label="Faluss">F</strong></header>
      <div class="faluss-onboarding-v3__stage" aria-label="Aperçu de votre Faluss"><div class="faluss-onboarding-v3__phone"><div class="faluss-onboarding-v3__phone-screen" data-v3-preview>${card()}</div></div></div>
      <form class="faluss-onboarding-v3__panel" data-v3-panel novalidate><button type="button" class="faluss-onboarding-v3__grabber" data-v3-grabber aria-label="Agrandir le panneau" aria-expanded="false"><span></span></button><div class="faluss-onboarding-v3__scroll" data-v3-scroll>${controls}<p class="faluss-onboarding-v3__error" data-v3-error role="alert" hidden></p><p class="faluss-onboarding-v3__status" data-v3-status role="status"></p></div><footer class="faluss-onboarding-v3__actions">${cta}</footer></form>
    </section><script>window.falussOnboardingV3={ajaxUrl:'/admin-ajax.php'};</script><script>${js}</script></body></html>`;
}

async function geometry(page, engine, width, step) {
    const result = await page.evaluate(() => {
        const shell = document.querySelector('.faluss-onboarding-v3');
        const panel = document.querySelector('.faluss-onboarding-v3__panel');
        const phone = document.querySelector('.faluss-onboarding-v3__phone');
        const action = document.querySelector('.faluss-onboarding-v3__actions');
        return {
            overflow: document.documentElement.scrollWidth - innerWidth,
            phones: document.querySelectorAll('.faluss-onboarding-v3__phone').length,
            shell: shell.getBoundingClientRect().toJSON(),
            panel: panel.getBoundingClientRect().toJSON(),
            phone: phone.getBoundingClientRect().toJSON(),
            action: action.getBoundingClientRect().toJSON(),
            scrollable: getComputedStyle(document.querySelector('[data-v3-scroll]')).overflowY
        };
    });
    assert(result.overflow <= 1, `${engine}/${width}/${step}: horizontal overflow ${result.overflow}`);
    assert(result.phones === 1, `${engine}/${width}/${step}: expected one phone`);
    assert(result.panel.width <= result.shell.width + 1, `${engine}/${width}/${step}: panel escapes shell`);
    assert(result.action.bottom <= result.shell.bottom + 1, `${engine}/${width}/${step}: action under viewport`);
    assert(result.scrollable === 'hidden', `${engine}/${width}/${step}: compact content scrolls`);
    return result;
}

(async () => {
    const output = path.join(root, 'docs/evidence/onboarding-v3');
    fs.mkdirSync(output, { recursive: true });
    for (const engine of [
        { name: 'chromium', browser: chromium, options: { headless: true, executablePath: chrome } },
        { name: 'webkit', browser: webkit, options: { headless: true } }
    ]) {
        const browser = await engine.browser.launch(engine.options);
        try {
            for (const width of [320, 375, 430, 768]) {
                for (const step of ['v3_mode', 'v3_socials', 'v3_links', 'v3_name', 'v3_success']) {
                    if (!fixture.atomic[step]) { continue; }
                    const page = await browser.newPage({ viewport: { width, height: 844 }, reducedMotion: 'reduce' });
                    const errors = [];
                    page.on('pageerror', (error) => errors.push(error.message));
                    await page.setContent(html('atomic', step, fixture.atomic[step]));
                    await geometry(page, engine.name, width, step);
                    await page.locator('[data-v3-grabber]').click();
                    const expanded = await page.locator('[data-v3-panel]').evaluate((element) => {
                        const action = element.querySelector('[data-v3-primary]');
                        return { height: element.getBoundingClientRect().height, scroll: getComputedStyle(element.querySelector('[data-v3-scroll]')).overflowY, actionBottom: action ? action.getBoundingClientRect().bottom : 0 };
                    });
                    assert(expanded.scroll === 'auto' && expanded.height > 500, `${engine.name}/${width}/${step}: panel did not expand`);
                    if (step !== 'v3_success') { assert(expanded.actionBottom <= 845 && expanded.actionBottom > 0, `${engine.name}/${width}/${step}: expanded action escaped viewport`); }
                    assert(errors.length === 0, `${engine.name}/${width}/${step}: ${errors.join('; ')}`);
                    await page.close();
                }
            }
            if (engine.name !== 'chromium') { continue; }
            for (const [mode, screens] of Object.entries(fixture)) {
                for (const [step, controls] of Object.entries(screens)) {
                    const page = await browser.newPage({ viewport: { width: 390, height: 844 }, reducedMotion: 'reduce' });
                    await page.setContent(html(mode, step, controls));
                    await geometry(page, engine.name, 390, step);
                    await page.screenshot({ path: path.join(output, `${mode}-${step}-compact.png`) });
                    await page.locator('[data-v3-grabber]').click();
                    await page.screenshot({ path: path.join(output, `${mode}-${step}-expanded.png`) });
                    await page.close();
                }
            }
            const keyboardPage = await browser.newPage({ viewport: { width: 390, height: 500 }, reducedMotion: 'reduce' });
            await keyboardPage.setContent(html('atomic', 'v3_links', fixture.atomic.v3_links));
            await keyboardPage.locator('[data-v3-link-label]').first().focus();
            const keyboardState = await keyboardPage.evaluate(() => ({
                expanded: document.querySelector('[data-v3-panel]').classList.contains('is-expanded'),
                actionBottom: document.querySelector('[data-v3-primary]').getBoundingClientRect().bottom
            }));
            assert(keyboardState.expanded && keyboardState.actionBottom <= 501, 'Short visible viewport obscures the action');
            await keyboardPage.close();

            const touchContext = await browser.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true, reducedMotion: 'reduce' });
            const touchPage = await touchContext.newPage();
            await touchPage.setContent(html('atomic', 'v3_socials', fixture.atomic.v3_socials));
            await touchPage.evaluate(() => {
                document.querySelectorAll('[data-v3-network-url]').forEach((input) => { input.hidden = false; });
            });
            const cdp = await touchContext.newCDPSession(touchPage);
            async function swipe(x, startY, endY) {
                await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y: startY }] });
                for (let index = 1; index <= 10; index += 1) {
                    const y = startY + (endY - startY) * index / 10;
                    await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x, y }] });
                    await touchPage.waitForTimeout(12);
                }
                await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
                await touchPage.waitForTimeout(120);
            }
            const compactTop = await touchPage.locator('[data-v3-panel]').evaluate((element) => element.getBoundingClientRect().top);
            await swipe(335, compactTop + 90, compactTop - 190);
            const afterFirstSwipe = await touchPage.evaluate(() => ({
                expanded: document.querySelector('[data-v3-panel]').classList.contains('is-expanded'),
                scrollTop: document.querySelector('[data-v3-scroll]').scrollTop
            }));
            assert(afterFirstSwipe.expanded && afterFirstSwipe.scrollTop === 0, 'First upward touch must expand before scrolling');
            for (let attempt = 0; attempt < 5; attempt += 1) { await swipe(335, 600, 180); }
            const afterScroll = await touchPage.evaluate(() => {
                const element = document.querySelector('[data-v3-scroll]');
                return { top: element.scrollTop, max: element.scrollHeight - element.clientHeight };
            });
            assert(afterScroll.max > 0 && afterScroll.top >= afterScroll.max - 2, 'Expanded Networks content must scroll fully by touch');
            await touchContext.close();

            const dragPage = await browser.newPage({ viewport: { width: 390, height: 844 }, reducedMotion: 'reduce' });
            await dragPage.setContent(html('atomic', 'v3_links', fixture.atomic.v3_links));
            const handle = await dragPage.locator('[data-v3-grabber]').boundingBox();
            const x = handle.x + handle.width / 2;
            const y = handle.y + handle.height / 2;
            const initialHeight = await dragPage.locator('[data-v3-panel]').evaluate((element) => element.getBoundingClientRect().height);
            await dragPage.mouse.move(x, y);
            await dragPage.mouse.down();
            await dragPage.mouse.move(x, y - 170, { steps: 5 });
            const draggedHeight = await dragPage.locator('[data-v3-panel]').evaluate((element) => element.getBoundingClientRect().height);
            assert(draggedHeight > initialHeight + 100, 'Panel height did not follow pointer drag');
            await dragPage.mouse.up();
            await dragPage.close();

            const coverPage = await browser.newPage({ viewport: { width: 390, height: 844 }, reducedMotion: 'reduce' });
            await coverPage.setContent(html('atomic', 'v3_wallpaper', fixture.atomic.v3_wallpaper));
            await coverPage.evaluate(() => {
                const card = document.querySelector('.faluss-link-card');
                card.classList.remove('faluss-link-card--cover-no');
                card.classList.add('faluss-link-card--cover-yes');
                const cover = card.querySelector('.faluss-link-card__cover');
                cover.hidden = false;
                cover.innerHTML = '<img alt="" src="data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 300 600%22%3E%3Crect width=%22300%22 height=%22600%22 fill=%22%233b4951%22/%3E%3Ccircle cx=%2270%22 cy=%22100%22 r=%2280%22 fill=%22%23757b68%22/%3E%3Cpath d=%22M0 450Q150 300 300 550%22 fill=%22%23293134%22/%3E%3C/svg%3E">';
            });
            const coverState = await coverPage.evaluate(() => {
                const card = document.querySelector('.faluss-link-card');
                const cover = card.querySelector('.faluss-link-card__cover');
                const link = card.querySelector('.faluss-link-card__link:last-child');
                const gradient = getComputedStyle(cover, '::after').backgroundImage;
                const coverBottom = cover.getBoundingClientRect().bottom;
                const linkBottom = link.getBoundingClientRect().bottom;
                const avatarTop = card.querySelector('.faluss-link-card__avatar').getBoundingClientRect().top;
                const cardTop = card.getBoundingClientRect().top;
                card.classList.remove('faluss-link-card--wallpaper-effect-gradient');
                card.classList.add('faluss-link-card--wallpaper-effect-none');
                const without = getComputedStyle(cover, '::after').backgroundImage;
                card.classList.remove('faluss-link-card--wallpaper-effect-none');
                card.classList.add('faluss-link-card--wallpaper-effect-gradient');
                return { gradient, without, coverBottom, linkBottom, avatarTop, cardTop };
            });
            assert(coverState.coverBottom >= coverState.linkBottom - 1, 'Full cover does not extend behind links');
            assert(coverState.avatarTop >= coverState.cardTop + 18, 'Avatar is clipped at the top of the full cover');
            assert(coverState.gradient !== coverState.without, 'Wallpaper effect does not change the overlay');
            await coverPage.screenshot({ path: path.join(output, 'atomic-cover-overlay.png') });
            await coverPage.close();
        } finally {
            await browser.close();
        }
    }
    process.stdout.write('V3 visual fixture: Chromium/WebKit at 320, 375, 430 and 768 px; screenshots at 390 px.\n');
})().catch((error) => { console.error(error); process.exitCode = 1; });
