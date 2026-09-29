const { chromium, webkit } = require('playwright');

// The same fixtures and assertions run in both engines; never silently fall back.
module.exports = async () => {
  const name = process.env.FANS_UI_BROWSER || 'chromium';
  if (!['chromium', 'webkit'].includes(name)) throw new Error('FANS_UI_BROWSER must be chromium or webkit');
  const browser = await (name === 'webkit' ? webkit.launch({ headless: true }) : chromium.launch({ channel: 'chrome', headless: true }));
  console.log(`Recipe browser: ${name} ${browser.version()}`);
  return browser;
};
