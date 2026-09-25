'use strict';
// One whole-page regression: actual Identity shell CSS + canonical PHP card.
// Local renderer/DOM evidence only; no WordPress, Elementor runtime or production access.
const fs = require('fs');
const path = require('path');
const cp = require('child_process');
const assert = require('assert/strict');
const {chromium} = require('playwright');
const root = path.resolve(__dirname, '../..');
const cards = JSON.parse(cp.execFileSync(process.env.FALUSS_PHP, [path.join(__dirname, 'v3-composition-regression.php')], {
    encoding: 'utf8', env: {...process.env, FALUSS_V3_PAGE: '1'}
}));
const css = ['assets/css/faluss-identity-public-profile.css', 'assets/me-studio/css/card-v2.css', 'assets/link/css/faluss-link.css', 'assets/link/css/faluss-link-immersive.css']
    .map(file => fs.readFileSync(path.join(root, file), 'utf8')).join('\n');
const image = 'data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="390" height="600"><rect width="390" height="600" fill="#af9dba"/><path d="M0 600L190 0 390 600" fill="#665471"/></svg>');
const illustrate = html => html.replaceAll('https://faluss.test/media/77.jpg', image);
(async () => {
    const browser = await chromium.launch({headless: true, ...(process.env.FALUSS_CHROME ? {executablePath: process.env.FALUSS_CHROME} : {})});
    try {
        const page = await browser.newPage({viewport: {width: 390, height: 844}, reducedMotion: 'reduce'});
        await page.route('**/*', route => route.abort());
        for (const length of ['short', 'long']) {
            await page.setContent(`<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}</style><body class="faluss-identity-public-shell faluss-identity-public-route"><main class="faluss-identity-profile-page">${illustrate(cards[length])}</main><div class="faluss-identity-public-header-layer"></div></body>`);
            const geometry = await page.evaluate(() => {
                const card = document.querySelector('.faluss-link-card--canonical');
                const box = card.getBoundingClientRect(), style = getComputedStyle(card);
                return {x: box.x, y: box.y, width: box.width, height: box.height, documentHeight: document.documentElement.scrollHeight, documentWidth: document.documentElement.scrollWidth, border: style.borderWidth, radius: style.borderRadius, shadow: style.boxShadow, color: style.backgroundColor};
            });
            assert.equal(geometry.x, 0); assert.equal(geometry.y, 0); assert.equal(geometry.width, 390);
            assert(geometry.height >= 844); assert.equal(geometry.documentHeight, Math.ceil(geometry.height));
            assert.equal(geometry.documentWidth, 390); assert.equal(geometry.border, '0px');
            assert.equal(geometry.radius, '0px'); assert.equal(geometry.shadow, 'none');
            assert.equal(geometry.color, 'rgb(222, 212, 228)');
            if (length === 'long') { assert(geometry.height > 844); }
            await page.evaluate(() => scrollTo(0, document.documentElement.scrollHeight));
            assert(await page.evaluate(() => [0, innerWidth / 2, innerWidth - 1].every(x => {
                const node = document.elementFromPoint(x, innerHeight - 1);
                return node && node.closest('.faluss-link-card--canonical');
            })), 'The card must cover the bottom of the whole document, including both corners');
            if (length === 'short') {
                const out = path.join(root, 'docs/evidence/me-v3-viewport'); fs.mkdirSync(out, {recursive: true});
                await page.screenshot({path: path.join(out, 'public-390.png'), fullPage: true});
            }
        }
        console.log('Whole public page 390×844: short/overflowing content, viewport/document bottom, corners, no frame or horizontal overflow: OK');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
