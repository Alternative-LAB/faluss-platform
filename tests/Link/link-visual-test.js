'use strict';

const fs = require('fs');
const path = require('path');
const { chromium, webkit } = require('playwright');

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

const root = path.resolve(__dirname, '../..');
const css = [
  'faluss-link.css',
  'faluss-link-immersive.css',
  'faluss-link-studio.css'
].map((file) => fs.readFileSync(path.join(root, 'assets/link/css', file), 'utf8')).join('\n');

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

const markup = `<!doctype html><html><head><meta charset="utf-8"><title>Faluss Link parity</title></head>
<body style="margin:0">
  <div class="elementor-widget elementor-element elementor-widget-faluss_link_studio"><div class="elementor-widget-container">
    <section class="faluss-link-studio" data-faluss-studio="v1" data-faluss-studio-screen="main">
      <form class="faluss-link-studio__form">
        <header class="faluss-link-studio__topbar">
          <button class="faluss-link-studio__round-action" type="button" aria-label="Revenir en arrière"><span>←</span></button>
          <div class="faluss-link-studio__header-actions"><button class="faluss-link-studio__round-action" type="button"><span>•••</span></button><button class="faluss-link-studio__round-action" type="button"><span>↗</span></button></div>
        </header>
        <div class="faluss-link-studio__identity"><span class="faluss-link-studio__avatar"></span><div><h1>Membre Faluss</h1><p>@membre</p></div></div>
        <div class="faluss-link-studio__main"><section class="faluss-link-studio__main-panel"><nav class="faluss-link-studio__context-tabs"><button type="button" aria-selected="true">Tous</button><button type="button">Collections</button></nav><div class="faluss-link-studio__content"><section><h2>Mes liens</h2><p>Le contenu Studio reste server-canonical.</p></section></div></section></div>
        <nav class="faluss-link-studio__dock" aria-label="Navigation principale du Studio">
          <button class="faluss-link-studio__create" type="button" aria-label="Créer"><span>+</span></button>
          <button class="faluss-link-studio__preview-toggle" type="button" aria-label="Aperçu"><span class="faluss-link-studio__eyes"><i></i><i></i></span></button>
          <div class="faluss-link-studio__dock-tabs"><span class="faluss-link-studio__dock-indicator"></span><button type="button" aria-pressed="true">Liens</button><button type="button" aria-disabled="true">Shop</button><button type="button">Design</button><button type="button" aria-disabled="true">Profil</button></div>
        </nav>
      </form>
    </section>
  </div></div>
  <article class="faluss-link-card faluss-link-card--presentation-immersive faluss-link-card--align-center faluss-link-card--cover-no faluss-link-card--avatar-no faluss-link-card--links-solid" style="--fl-page-background:#FFFDF5">
    <div class="faluss-link-card__body"><h1 class="faluss-link-card__name faluss-link-card__name--strong">Membre Faluss</h1><p class="faluss-link-card__bio">Profil public partagé.</p><div class="faluss-link-card__links"><a class="faluss-link-card__link" href="#target">Lien public</a></div></div>
  </article>
</body></html>`;

async function snapshot(page) {
  return page.evaluate(() => {
    const studio = document.querySelector('.faluss-link-studio');
    const dock = document.querySelector('.faluss-link-studio__dock');
    const create = document.querySelector('.faluss-link-studio__create');
    const card = document.querySelector('.faluss-link-card');
    const studioRect = studio.getBoundingClientRect();
    const dockRect = dock.getBoundingClientRect();
    const createRect = create.getBoundingClientRect();
    const cardRect = card.getBoundingClientRect();
    const center = { x: createRect.left + createRect.width / 2, y: createRect.top + createRect.height / 2 };
    return {
      viewport: { width: innerWidth, height: innerHeight },
      scrollWidth: document.documentElement.scrollWidth,
      studio: { width: studioRect.width, height: studioRect.height, background: getComputedStyle(studio).backgroundColor },
      dock: { left: dockRect.left, right: dockRect.right, top: dockRect.top, bottom: dockRect.bottom, position: getComputedStyle(dock).position },
      create: { width: createRect.width, height: createRect.height, hit: document.elementsFromPoint(center.x, center.y).includes(create) },
      card: { width: cardRect.width, minHeight: parseFloat(getComputedStyle(card).minHeight) },
      linkTransition: getComputedStyle(document.querySelector('.faluss-link-card__link')).transitionDuration
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
        page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()); });
        await page.setContent(markup, { waitUntil: 'load' });
        await page.addStyleTag({ content: css });
        await page.emulateMedia({ reducedMotion: 'reduce' });
        const state = await snapshot(page);
        assert(state.scrollWidth <= viewport.width + 1, `${engine.name}/${viewport.name}: horizontal overflow`);
        assert(Math.abs(state.studio.width - viewport.width) <= 1, `${engine.name}/${viewport.name}: Studio is not full-width`);
        assert(state.studio.height >= viewport.height - 0.5, `${engine.name}/${viewport.name}: Studio is shorter than the viewport (${state.studio.height}/${viewport.height})`);
        assert(state.studio.background === 'rgb(255, 255, 255)', `${engine.name}/${viewport.name}: Studio background changed`);
        assert(state.dock.position === 'fixed' && state.dock.left >= 0 && state.dock.right <= viewport.width + 1 && state.dock.bottom <= viewport.height + 1, `${engine.name}/${viewport.name}: dock escaped the viewport`);
        assert(state.create.width >= 44 && state.create.height >= 44 && state.create.hit, `${engine.name}/${viewport.name}: create action is not a usable hit target (${JSON.stringify(state.create)})`);
        assert(Math.abs(state.card.width - viewport.width) <= 1 && state.card.minHeight >= viewport.height - 0.5, `${engine.name}/${viewport.name}: immersive public profile geometry changed (${JSON.stringify(state.card)})`);
        assert(state.linkTransition.split(',').every((duration) => duration.trim() === '0s'), `${engine.name}/${viewport.name}: reduced motion is not respected`);
        assert(errors.length === 0, `${engine.name}/${viewport.name}: browser errors: ${errors.join('; ')}`);
        await page.close();
      }
    } finally {
      await browser.close();
    }
  }
  process.stdout.write('Faluss Link synthetic visual parity: WebKit and Chromium, mobile and desktop: OK\n');
})().catch((error) => {
  process.stderr.write(`FAIL: ${error.message}\n`);
  process.exit(1);
});
