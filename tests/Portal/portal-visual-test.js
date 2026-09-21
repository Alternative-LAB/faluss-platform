'use strict';

const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const { PNG } = require('pngjs');
const { chromium, webkit } = require('playwright');

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

const root = path.resolve(__dirname, '..', '..');
const portal = root;
const cssPath = path.join(portal, 'assets', 'css', 'faluss-portal.css');
const scriptPath = path.join(portal, 'assets', 'js', 'faluss-portal.js');
const mePath = path.join(portal, 'assets', 'images', 'apps', 'faluss-me.png');
const badgePath = path.join(portal, 'assets', 'images', 'pf', 'faluss-pf-badge.png');
const badgeHash = crypto.createHash('sha256').update(fs.readFileSync(badgePath)).digest('hex');
assert(badgeHash === 'a25533ca502e4e6286cb58c858de8d7a4de5d25b18a5d91894b31c46ff1f1955', 'The official PF badge changed.');
const meHash = crypto.createHash('sha256').update(fs.readFileSync(mePath)).digest('hex');
assert(meHash === '1541ef775c32d229c11ec79a579ef9d371cf8f23a77c0c4b1920fda5bdb6d64a', 'The official Faluss Me logo changed.');

const me = PNG.sync.read(fs.readFileSync(mePath));
assert(me.alpha === true, 'Faluss Me must be an RGBA PNG with a real alpha channel.');
const pixel = (x, y) => {
  const index = (me.width * y + x) * 4;
  return Array.from(me.data.subarray(index, index + 4));
};
[[0, 0], [me.width - 1, 0], [0, me.height - 1], [me.width - 1, me.height - 1]]
  .forEach(([x, y]) => assert(pixel(x, y)[3] === 0, 'Every Faluss Me corner must be transparent.'));
let opaqueWhite = 0;
for (let index = 0; index < me.data.length; index += 4) {
  if (me.data[index + 3] >= 250 && me.data[index] >= 250 && me.data[index + 1] >= 250 && me.data[index + 2] >= 250) opaqueWhite++;
}
assert(opaqueWhite > 1000, 'Faluss Me must contain opaque white symbol pixels.');

const browserCandidates = [
  process.env.FALUSS_BROWSER_EXECUTABLE,
  'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
  'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
  'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe'
].filter(Boolean);
const chromiumExecutablePath = browserCandidates.find((candidate) => fs.existsSync(candidate));
assert(chromiumExecutablePath, 'No installed Chromium-compatible browser is available for AP-02A.2 rendering.');
assert(fs.existsSync(webkit.executablePath()), 'The Playwright WebKit runtime is required for DR-02A.1.');
const engines = [
  { name: 'webkit', type: webkit, launchOptions: { headless: true } },
  { name: 'chromium', type: chromium, launchOptions: { headless: true, executablePath: chromiumExecutablePath } }
];

const markup = [
  '<!doctype html><html><head><meta charset="utf-8"><title>AP-02A.2</title></head>',
  '<body class="elementor" tabindex="-1"><div class="elementor-widget">',
  '<section class="faluss-portal" data-faluss-portal="v1" data-section="apps" data-tab="my-apps"><aside class="faluss-portal__sidebar"></aside><main class="faluss-portal__main"><div class="faluss-portal__content"><section class="faluss-portal__panel is-active">',
  '<div class="faluss-portal__apps faluss-portal__apps--owned" data-faluss-apps-view="my-apps">',
  '<article id="hub-card" class="faluss-portal__app-card faluss-portal__app-card--compact faluss-portal__app-card--hub-daily" data-faluss-app-card data-faluss-app="hub" style="--faluss-app-accent:#000000;--faluss-app-title-accent:#ffffff">',
  '<a class="faluss-portal__app-card-access" href="https://faluss.test/hub" target="_blank" rel="noopener noreferrer" aria-label="Ouvrir Faluss Hub"></a>',
  '<div class="faluss-portal__app-head"><span class="faluss-portal__app-logo"></span>',
  '<span class="faluss-portal__app-identity"><span class="faluss-portal__app-name">Faluss Hub</span><span class="faluss-portal__app-subtitle">M’y rendre</span></span>',
  '<span class="faluss-portal__hub-daily-action" data-faluss-portal-hub-daily-action data-faluss-portal-hub-daily-state="claimable">',
  '<form class="faluss-portal__hub-daily-form" data-faluss-portal-hub-daily-reward method="post" action="https://faluss.test/wp-admin/admin-ajax.php">',
  '<input type="hidden" name="action" value="faluss_portal_claim_hub_daily"><input type="hidden" name="faluss_portal_hub_daily_nonce" value="nonce">',
  '<button class="faluss-portal__app-open faluss-portal__app-open--reward" type="submit" data-faluss-portal-hub-daily-submit aria-label="Gain quotidien : 20 Points Faluss">',
  '<img class="faluss-portal__hub-daily-badge" data-faluss-portal-pf-badge src="https://faluss.test/badge.png" alt="" aria-hidden="true"><span class="faluss-portal__hub-daily-amount" data-faluss-portal-hub-daily-amount>20</span></button>',
  '<span class="faluss-portal__hub-daily-feedback" data-faluss-portal-hub-daily-feedback role="status" hidden></span></form></span></div></article>',
  '<article id="me-card" class="faluss-portal__app-card faluss-portal__app-card--compact" data-faluss-app-card data-faluss-app="me" style="--faluss-app-accent:#ee4a4a;--faluss-app-title-accent:#ee4a4a">',
  '<a class="faluss-portal__app-card-access" href="https://faluss.me/mon-faluss" target="_blank" rel="noopener noreferrer" aria-label="Ouvrir Faluss Me"></a>',
  '<div class="faluss-portal__app-head"><span class="faluss-portal__app-logo"><img src="https://faluss.test/faluss-me.png" alt=""></span>',
  '<span class="faluss-portal__app-identity"><span class="faluss-portal__app-name">Faluss Me</span><span class="faluss-portal__app-subtitle">M’y rendre</span></span><span class="faluss-portal__app-open" aria-hidden="true"></span>',
  '</div></article></div></section></div></main></section></div></body></html>'
].join('');

const hostileElementorCSS = [
  '.elementor button {',
  'appearance:auto;display:block;width:100%;margin:14px;padding:24px;',
  'border:5px solid rgb(255,0,128);border-radius:0;outline:6px solid rgb(255,0,128);',
  'background:rgb(255,0,128);box-shadow:0 0 0 8px rgb(255,0,128);',
  'color:rgb(255,0,128);font-size:40px;',
  '}',
  '.elementor button:focus { outline:6px solid rgb(255,0,128);box-shadow:0 0 0 8px rgb(255,0,128); }'
].join('\n');

const geometry = async (page) => page.evaluate(() => {
  const button = document.querySelector('[data-faluss-portal-hub-daily-submit]');
  const badge = button.querySelector('[data-faluss-portal-pf-badge]');
  const amount = button.querySelector('[data-faluss-portal-hub-daily-amount]');
  const buttonRect = button.getBoundingClientRect();
  const badgeRect = badge.getBoundingClientRect();
  const amountRect = amount.getBoundingClientRect();
  const style = getComputedStyle(button);
  const logoStyle = getComputedStyle(document.querySelector('#me-card .faluss-portal__app-logo img'));
  const action = button.closest('[data-faluss-portal-hub-daily-action]');
  const form = button.closest('form');
  const head = button.closest('.faluss-portal__app-head');
  const cardLink = document.querySelector('#hub-card .faluss-portal__app-card-access');
  const cardRect = document.querySelector('#hub-card').getBoundingClientRect();
  const outsideTarget = document.elementFromPoint(cardRect.left + 12, cardRect.top + (cardRect.height / 2));
  const center = { x: buttonRect.left + (buttonRect.width / 2), y: buttonRect.top + (buttonRect.height / 2) };
  const centerTarget = document.elementFromPoint(center.x, center.y);
  const layer = (element) => {
    const computed = getComputedStyle(element);
    return { position: computed.position, zIndex: computed.zIndex, pointerEvents: computed.pointerEvents };
  };
  return {
    button: { left: buttonRect.left, top: buttonRect.top, right: buttonRect.right, bottom: buttonRect.bottom, width: buttonRect.width, height: buttonRect.height },
    card: { left: cardRect.left, top: cardRect.top, right: cardRect.right, bottom: cardRect.bottom, width: cardRect.width, height: cardRect.height },
    badge: { left: badgeRect.left, right: badgeRect.right, width: badgeRect.width, height: badgeRect.height },
    amount: { left: amountRect.left, right: amountRect.right, width: amountRect.width, height: amountRect.height, fontSize: getComputedStyle(amount).fontSize },
    buttonEdges: { left: buttonRect.left, right: buttonRect.right },
    style: { width: style.width, borderRadius: style.borderRadius, borderWidth: style.borderWidth, outlineStyle: style.outlineStyle, fontSize: style.fontSize, whiteSpace: style.whiteSpace },
    logo: { filter: logoStyle.filter, mixBlendMode: logoStyle.mixBlendMode, transform: logoStyle.transform, objectFit: logoStyle.objectFit },
    center: {
      ...center,
      target: centerTarget ? centerTarget.tagName.toLowerCase() + (centerTarget.className ? '.' + String(centerTarget.className).trim().replace(/\s+/g, '.') : '') : 'null',
      stack: document.elementsFromPoint(center.x, center.y).map((element) => element.tagName.toLowerCase() + (element.className ? '.' + String(element.className).trim().replace(/\s+/g, '.') : '')),
      isButton: Boolean(centerTarget && centerTarget.closest('[data-faluss-portal-hub-daily-submit]')),
      isCardLink: Boolean(centerTarget && centerTarget.closest('.faluss-portal__app-card-access'))
    },
    layers: { link: layer(cardLink), head: layer(head), action: layer(action), form: layer(form), button: layer(button) },
    outsideIsCardLink: Boolean(outsideTarget && outsideTarget.closest('.faluss-portal__app-card-access'))
  };
});

(async () => {
  for (const engine of engines) {
    const browser = await engine.type.launch(engine.launchOptions);
    try {
      for (const viewport of [{ name: 'desktop', width: 1200, height: 800, touch: false }, { name: 'mobile', width: 360, height: 760, touch: true }]) {
        const context = await browser.newContext({ viewport: { width: viewport.width, height: viewport.height }, hasTouch: viewport.touch });
        const page = await context.newPage();
        let postCount = 0;
        const postTargets = [];
        await context.route('https://faluss.test/fixture', (route) => route.fulfill({ status: 200, contentType: 'text/html', body: markup }));
        await context.route('https://faluss.test/badge.png', (route) => route.fulfill({ status: 200, contentType: 'image/png', body: fs.readFileSync(badgePath) }));
        await context.route('https://faluss.test/faluss-me.png', (route) => route.fulfill({ status: 200, contentType: 'image/png', body: fs.readFileSync(mePath) }));
        await context.route('https://faluss.test/hub', (route) => route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>Hub</title>' }));
        await context.route('**/wp-admin/admin-ajax.php*', (route) => {
          const request = route.request();
          if (request.method() === 'POST') {
            postCount++;
            postTargets.push(request.url());
          }
          assert(request.method() === 'POST', 'The reward request must stay POST.');
          return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { status: 'claimed', reward: { amount_pf: 20, economic_class: 'earned', label: '20 PF' } } }) });
        });
        await page.goto('https://faluss.test/fixture');
        await page.addStyleTag({ path: cssPath });
        await page.addStyleTag({ content: hostileElementorCSS });
        await page.addScriptTag({ path: scriptPath });
        await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded', { bubbles: true })));

        let popupCount = 0;
        let newPageCount = 0;
        let navigationCount = 0;
        page.on('popup', () => { popupCount++; });
        context.on('page', (openedPage) => { if (openedPage !== page) newPageCount++; });
        page.on('framenavigated', (frame) => { if (frame === page.mainFrame()) navigationCount++; });
        const initialPageCount = context.pages().length;
        const beforeClaimURL = page.url();
        const before = await geometry(page);
        const label = engine.name.toUpperCase() + ' ' + viewport.name.toUpperCase();
        process.stdout.write(label + ' preflight button=' + JSON.stringify(before.button) + ' card=' + JSON.stringify(before.card) + ' target=' + before.center.target + ' closestButton=' + before.center.isButton + ' closestCardLink=' + before.center.isCardLink + ' layers=' + JSON.stringify(before.layers) + ' stack=' + JSON.stringify(before.center.stack) + '\n');
        assert(before.center.isButton && !before.center.isCardLink, label + ': elementFromPoint at pill center must resolve to the real claim button, never the card link.');
        assert(before.layers.link.zIndex === '1', label + ': the global card link must remain on layer 1.');
        assert(before.layers.head.zIndex === 'auto' && before.layers.head.pointerEvents === 'auto', label + ': the app header must not create a pointer-events barrier above the card link.');
        assert(before.layers.action.position === 'relative' && before.layers.action.zIndex === '3' && before.layers.action.pointerEvents === 'auto', label + ': the daily action must own an explicit hit-test layer.');
        assert(before.layers.form.position === 'relative' && before.layers.form.zIndex === '4' && before.layers.form.pointerEvents === 'auto', label + ': the daily form must own an explicit hit-test layer.');
        assert(before.layers.button.position === 'relative' && before.layers.button.zIndex === '5' && before.layers.button.pointerEvents === 'auto', label + ': the real button must be the top interactive layer.');
        assert(before.button.width > before.button.height, label + ': reward must be a pill wider than it is high.');
        assert(before.button.width < 180 && before.style.width !== '100%', label + ': reward pill must remain compact, never full width.');
        assert(Math.abs(before.button.height - (viewport.name === 'desktop' ? 72 : 52)) < 0.5, label + ': historical glass height must be preserved.');
        assert(before.badge.left >= before.buttonEdges.left && before.badge.right <= before.buttonEdges.right && before.amount.left >= before.buttonEdges.left && before.amount.right <= before.buttonEdges.right, label + ': badge and amount must not overflow.');
        assert(before.amount.fontSize === (viewport.name === 'desktop' ? '23px' : '20px'), label + ': reward amount must retain the approved responsive scale.');
        assert(before.style.borderRadius === '999px' && before.style.borderWidth === '0px' && before.style.outlineStyle === 'none' && before.style.whiteSpace === 'nowrap', label + ': Elementor must not replace the pill geometry or wrapping.');
        assert(before.logo.filter === 'none' && before.logo.mixBlendMode === 'normal' && before.logo.transform === 'none' && before.logo.objectFit === 'contain', label + ': Faluss Me must use the official asset without CSS repair effects.');
        assert(before.outsideIsCardLink, label + ': card area outside the pill must remain the Hub link.');

        const pressPill = () => viewport.touch ? page.touchscreen.tap(before.center.x, before.center.y) : page.mouse.click(before.center.x, before.center.y);
        await page.$eval('[data-faluss-portal-hub-daily-submit]', (button) => { button.type = 'button'; });
        await pressPill();
        const pointerFocus = await page.$eval('[data-faluss-portal-hub-daily-submit]', (button) => {
          const computed = getComputedStyle(button);
          return { shadow: computed.boxShadow, outline: computed.outlineStyle, border: computed.borderWidth };
        });
        assert(pointerFocus.outline === 'none' && pointerFocus.border === '0px' && !pointerFocus.shadow.includes('255, 0, 128'), label + ': pointer focus must not show the Elementor pink rectangle.');
        assert(popupCount === 0 && newPageCount === 0 && navigationCount === 0 && context.pages().length === initialPageCount && page.url() === beforeClaimURL, label + ': touching the pill must not activate the card link.');
        await page.$eval('[data-faluss-portal-hub-daily-submit]', (button) => { button.type = 'submit'; });

        await page.focus('body');
        await page.keyboard.press('Tab');
        await page.keyboard.press('Tab');
        const keyboardFocus = await page.$eval('[data-faluss-portal-hub-daily-submit]', (button) => {
          const computed = getComputedStyle(button);
          return { focused: document.activeElement === button, visible: button.matches(':focus-visible'), shadow: computed.boxShadow, radius: computed.borderRadius };
        });
        assert(keyboardFocus.focused && keyboardFocus.visible && keyboardFocus.radius === '999px' && keyboardFocus.shadow.includes('255, 255, 255') && !keyboardFocus.shadow.includes('255, 0, 128'), label + ': keyboard focus must be a white pill-shaped ring.');

        await pressPill();
        await page.waitForSelector('[data-faluss-portal-hub-daily-state="claimed"] .faluss-portal__app-open--reward');
        const afterRect = await page.$eval('[data-faluss-portal-hub-daily-state="claimed"] .faluss-portal__app-open--reward', (element) => {
          const rect = element.getBoundingClientRect();
          return { width: rect.width, height: rect.height, role: element.getAttribute('role'), label: element.getAttribute('aria-label'), buttonCount: element.closest('[data-faluss-portal-hub-daily-action]').querySelectorAll('button').length };
        });
        assert(postCount === 1 && postTargets.length === 1 && postTargets[0] === 'https://faluss.test/wp-admin/admin-ajax.php', label + ': pill must issue exactly one POST to the form action URL.');
        assert(page.url() === beforeClaimURL && popupCount === 0 && newPageCount === 0 && navigationCount === 0 && context.pages().length === initialPageCount, label + ': claim must preserve the tab, page and URL without opening the card.');
        assert(Math.abs(afterRect.width - before.button.width) < 0.5 && Math.abs(afterRect.height - before.button.height) < 0.5, label + ': claimed must preserve pill geometry.');
        assert(afterRect.role === 'status' && afterRect.label.includes('déjà reçu') && afterRect.buttonCount === 0, label + ': claimed must be the existing non-interactive received state.');
        await pressPill();
        await page.waitForTimeout(100);
        assert(postCount === 1 && popupCount === 0 && newPageCount === 0 && navigationCount === 0 && context.pages().length === initialPageCount && page.url() === beforeClaimURL, label + ': the non-interactive claimed pill must not submit or navigate on a second press.');
        process.stdout.write(label + ' pill=' + before.button.width + 'x' + before.button.height + ' badge=' + before.badge.width + 'x' + before.badge.height + ' amount=' + before.amount.fontSize + ' post=' + postCount + ' navigation=' + navigationCount + ' popup=' + popupCount + ' newPages=' + newPageCount + '\n');
        await context.close();
      }
    } finally {
      await browser.close();
    }
  }
  process.stdout.write('DR-02A.1 AP-02A.2 alpha=' + me.alpha + ' size=' + me.width + 'x' + me.height + ' opaqueWhite=' + opaqueWhite + ' meSHA256=' + meHash + ' badgeSHA256=' + badgeHash + ': OK\n');
})().catch((error) => {
  process.stderr.write('FAIL: ' + error.message + '\n');
  process.exit(1);
});
