'use strict';

const fs = require('fs');
const path = require('path');
const { chromium, webkit } = require('playwright');

function assert(condition, message) {
    if (!condition) { throw new Error(message); }
}

const root = path.resolve(__dirname, '../..');
const css = fs.readFileSync(path.join(root, 'assets/me-studio/css/onboarding-v2.css'), 'utf8');
const baseCardCss = fs.readFileSync(path.join(root, 'assets/link/css/faluss-link.css'), 'utf8');
const cardCss = fs.readFileSync(path.join(root, 'assets/me-studio/css/card-v2.css'), 'utf8');
const baseStudioCss = fs.readFileSync(path.join(root, 'assets/link/css/faluss-link-studio.css'), 'utf8');
const studioCss = fs.readFileSync(path.join(root, 'assets/me-studio/css/studio-v2.css'), 'utf8');
const chromiumCandidates = [
    process.env.FALUSS_BROWSER_EXECUTABLE,
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe'
].filter(Boolean);
const chromiumExecutable = chromiumCandidates.find((candidate) => fs.existsSync(candidate));

assert(chromiumExecutable, 'A Chromium-compatible browser is required.');
assert(fs.existsSync(webkit.executablePath()), 'The Playwright WebKit runtime is required.');

const engines = [
    { name: 'webkit', type: webkit, options: { headless: true } },
    { name: 'chromium', type: chromium, options: { headless: true, executablePath: chromiumExecutable } }
];
const viewports = [
    { name: 'mobile', width: 390, height: 844 },
    { name: 'desktop', width: 1280, height: 900 }
];
const markup = `<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Faluss Studio V2</title></head><body>
<section class="faluss-me-onboarding-v2 faluss-me-onboarding-v2--atomic-colors" data-me-studio-onboarding data-step="wizard_atomic_colors" style="--fmo-progress:.3333">
  <header class="faluss-me-onboarding-v2__topbar">
    <button type="button" aria-label="Revenir à l’étape précédente">←</button>
    <div class="faluss-me-onboarding-v2__progress" role="progressbar"><span></span></div>
    <span class="faluss-me-onboarding-v2__logo">ϟ</span>
  </header>
  <aside class="faluss-me-onboarding-v2__preview" aria-label="Aperçu de votre Faluss"><article class="faluss-link-card faluss-link-card--structure-atomic faluss-link-card--cover-yes faluss-link-card--wallpaper-compact faluss-link-card--wallpaper-effect-none faluss-link-card--links-solid faluss-link-card--texture-grain" style="--fl-page-background:#333"><div class="faluss-link-card__cover"><img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='20'%3E%3Crect width='20' height='20' fill='%23666'/%3E%3C/svg%3E" alt=""></div><div class="faluss-link-card__body"><span class="faluss-link-card__avatar"></span><h2>Dylan</h2><div class="faluss-link-card__links"><a class="faluss-link-card__link">Lien</a></div></div></article></aside>
  <form class="faluss-me-onboarding-v2__panel">
    <div class="faluss-me-onboarding-v2__status" role="status"></div>
    <h1>Couleurs principales</h1>
    <div class="faluss-me-onboarding-v2__tabs" role="tablist"><button type="button" role="tab" aria-selected="true">Arrière Plan</button><button type="button" role="tab" aria-selected="false">Boutons</button></div>
    <fieldset class="faluss-me-onboarding-v2__palette"><legend>Arrière Plan</legend>
      <label style="--fmo-color:#000000"><input type="radio" name="page_background" checked><span></span></label>
      <label style="--fmo-color:#191919"><input type="radio" name="page_background"><span></span></label>
      <label style="--fmo-color:#737373"><input type="radio" name="page_background"><span></span></label>
      <label style="--fmo-color:#DDDDDD"><input type="radio" name="page_background"><span></span></label>
      <label style="--fmo-color:#FFFFFF"><input type="radio" name="page_background"><span></span></label>
    </fieldset>
    <footer class="faluss-me-onboarding-v2__actions"><button class="faluss-me-onboarding-v2__continue" type="submit">Continuer</button></footer>
  </form>
</section></body></html>`;
const studioMarkup = `<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Faluss Studio V2 sticky header</title></head><body style="margin:0">
<section class="faluss-link-studio" data-faluss-studio="v2">
  <form class="faluss-link-studio__form" style="min-height:200vh">
    <header class="faluss-link-studio__topbar" style="height:72px"><button type="button">Retour</button><span>Studio</span></header>
  </form>
</section></body></html>`;

async function state(page) {
    return page.evaluate(() => {
        const shell = document.querySelector('.faluss-me-onboarding-v2');
        const panel = document.querySelector('.faluss-me-onboarding-v2__panel');
        const progress = document.querySelector('.faluss-me-onboarding-v2__progress span');
        const action = document.querySelector('.faluss-me-onboarding-v2__continue');
        const selected = document.querySelector('.faluss-me-onboarding-v2__palette input:checked + span');
        const link = document.querySelector('.faluss-link-card__link');
        const cover = document.querySelector('.faluss-link-card__cover');
        const coverImage = cover.querySelector('img');
        const shellRect = shell.getBoundingClientRect();
        const panelRect = panel.getBoundingClientRect();
        const actionRect = action.getBoundingClientRect();
        return {
            viewport: { width: innerWidth, height: innerHeight },
            scrollWidth: document.documentElement.scrollWidth,
            shell: { left: shellRect.left, right: shellRect.right, width: shellRect.width, minHeight: parseFloat(getComputedStyle(shell).minHeight) },
            panel: { top: panelRect.top, width: panelRect.width, radius: getComputedStyle(panel).borderTopLeftRadius, background: getComputedStyle(panel).backgroundColor },
            action: { width: actionRect.width, height: actionRect.height, radius: getComputedStyle(action).borderRadius, background: getComputedStyle(action).backgroundColor },
            progressWidth: progress.getBoundingClientRect().width / progress.parentElement.getBoundingClientRect().width,
            selectedRing: getComputedStyle(selected).boxShadow,
            motion: getComputedStyle(action).transitionDuration,
            linkRadius: getComputedStyle(link).borderRadius,
            linkMotion: getComputedStyle(link).transitionDuration,
            coverPosition: getComputedStyle(cover).position,
            coverMask: getComputedStyle(coverImage).maskImage,
            noneOverlay: getComputedStyle(cover, '::after').backgroundImage
        };
    });
}

(async () => {
    for (const engine of engines) {
        const browser = await engine.type.launch(engine.options);
        try {
            for (const viewport of viewports) {
                const page = await browser.newPage({ viewport });
                const errors = [];
                page.on('pageerror', (error) => errors.push(error.message));
                page.on('console', (message) => { if (message.type() === 'error') { errors.push(message.text()); } });
                await page.setContent(markup, { waitUntil: 'load' });
                await page.addStyleTag({ content: baseCardCss });
                await page.addStyleTag({ content: css });
                await page.addStyleTag({ content: cardCss });
                await page.emulateMedia({ reducedMotion: 'reduce' });
                await page.waitForTimeout(50);
                const snapshot = await state(page);
                assert(snapshot.scrollWidth <= viewport.width + 1, `${engine.name}/${viewport.name}: horizontal overflow`);
                assert(snapshot.shell.width <= 440.5 && snapshot.shell.left >= 0 && snapshot.shell.right <= viewport.width + 1, `${engine.name}/${viewport.name}: shell escaped its 440px mobile boundary`);
                assert(snapshot.shell.minHeight >= viewport.height - 1, `${engine.name}/${viewport.name}: shell does not cover the viewport`);
                assert(Math.abs(snapshot.panel.width - snapshot.shell.width) <= 1 && snapshot.panel.background === 'rgb(255, 255, 255)' && parseFloat(snapshot.panel.radius) >= 44, `${engine.name}/${viewport.name}: bottom sheet geometry changed (${JSON.stringify(snapshot.panel)}/${JSON.stringify(snapshot.shell)})`);
                assert(snapshot.action.height >= 54 && snapshot.action.width >= 280 && parseFloat(snapshot.action.radius) >= 27 && snapshot.action.background === 'rgb(245, 63, 66)', `${engine.name}/${viewport.name}: continue action geometry changed`);
                assert(Math.abs(snapshot.progressWidth - .3333) < .02, `${engine.name}/${viewport.name}: progress value is not reflected visually`);
                assert(snapshot.selectedRing !== 'none', `${engine.name}/${viewport.name}: selected palette option has no visible state`);
                assert(parseFloat(snapshot.motion) <= .001, `${engine.name}/${viewport.name}: reduced motion is not respected`);
                assert(parseFloat(snapshot.linkRadius) >= 100 && parseFloat(snapshot.linkMotion) <= .001, `${engine.name}/${viewport.name}: atomic card style is not shared with the preview`);
                assert(snapshot.coverPosition === 'absolute' && snapshot.coverMask === 'none' && snapshot.noneOverlay === 'none', `${engine.name}/${viewport.name}: compact wallpaper without gradient is incorrect`);
                const gradientOverlay = await page.locator('.faluss-link-card').evaluate((card) => {
                    card.classList.remove('faluss-link-card--wallpaper-effect-none');
                    card.classList.add('faluss-link-card--wallpaper-effect-gradient');
                    return getComputedStyle(card.querySelector('.faluss-link-card__cover'), '::after').backgroundImage;
                });
                assert(gradientOverlay !== 'none', `${engine.name}/${viewport.name}: wallpaper gradient is not rendered`);
                assert(errors.length === 0, `${engine.name}/${viewport.name}: browser errors: ${errors.join('; ')}`);
                await page.close();

                const studioPage = await browser.newPage({ viewport });
                const studioErrors = [];
                studioPage.on('pageerror', (error) => studioErrors.push(error.message));
                studioPage.on('console', (message) => { if (message.type() === 'error') { studioErrors.push(message.text()); } });
                await studioPage.setContent(studioMarkup, { waitUntil: 'load' });
                await studioPage.addStyleTag({ content: baseCardCss });
                await studioPage.addStyleTag({ content: baseStudioCss });
                await studioPage.addStyleTag({ content: studioCss });
                await studioPage.evaluate(() => window.scrollTo(0, 240));
                await studioPage.waitForTimeout(20);
                const sticky = await studioPage.locator('.faluss-link-studio__topbar').evaluate((node) => ({
                    position: getComputedStyle(node).position,
                    top: node.getBoundingClientRect().top,
                    zIndex: getComputedStyle(node).zIndex
                }));
                assert(sticky.position === 'sticky' && Math.abs(sticky.top) <= 1 && Number(sticky.zIndex) >= 10, `${engine.name}/${viewport.name}: V2 header is not sticky (${JSON.stringify(sticky)})`);
                assert(studioErrors.length === 0, `${engine.name}/${viewport.name}: Studio browser errors: ${studioErrors.join('; ')}`);
                await studioPage.close();
            }
        } finally {
            await browser.close();
        }
    }
    process.stdout.write('Faluss Me Studio V2 synthetic visual contract: WebKit and Chromium, mobile and desktop: OK\n');
})().catch((error) => {
    process.stderr.write(`FAIL: ${error.message}\n`);
    process.exit(1);
});
