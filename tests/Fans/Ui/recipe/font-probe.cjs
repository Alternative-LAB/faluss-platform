const fs = require('node:fs');
const path = require('node:path');
const launchBrowser = require('./browser-engine.cjs');
const base = process.env.FANS_UI_BASE || 'http://127.0.0.1:8765';
const output = process.env.FANS_UI_OUTPUT || path.resolve(__dirname, '../../../../docs/evidence/fans-48h/lot-10');
fs.mkdirSync(output, { recursive: true });
(async () => {
  const browser = await launchBrowser();
  try {
    const page = await browser.newPage({ viewport: { width: 1100, height: 600 } });
    await page.goto(`${base}/faluss-fans/fan/hof`);
    await page.setContent(`<style>
      @font-face{font-family:TestNormal;src:url('${base}/assets/fonts/outfit.ttf') format('truetype');font-weight:100 900}
      @font-face{font-family:TestVariable;src:url('${base}/assets/fonts/outfit.ttf') format('truetype-variations');font-weight:100 900}
      body{background:#090c0e;color:#f2f4f1;font-size:24px}p{margin:20px}b{font-weight:700}
      </style>
      <p style="font-family:TestNormal;font-weight:400">Format truetype : Texte normal <b>et gras</b></p>
      <p style="font-family:TestNormal;font-weight:400;font-variation-settings:'wght' 400">Axe explicite 400 : Texte normal <b style="font-variation-settings:'wght' 700">et gras</b></p>
      <p style="font-family:TestVariable;font-weight:400">Format variations : Texte normal <b>et gras</b></p>
      <p style="font-family:system-ui;font-weight:400">Police système : Texte normal <b>et gras</b></p>`);
    await page.evaluate(() => document.fonts.ready);
    console.log(await page.evaluate(() => [...document.fonts].map(font => ({ family: font.family, status: font.status, weight: font.weight }))));
    await page.screenshot({ path: path.join(output, `font-probe-${process.env.FANS_UI_BROWSER || 'chromium'}.png`) });
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
