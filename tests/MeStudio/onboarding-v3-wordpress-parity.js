'use strict';

// Compare a published local WordPress card across the public route and both integrations.
const { chromium } = require('playwright');
const base = process.env.FALUSS_V3_WP_BASE;
const slug = process.env.FALUSS_V3_WP_SLUG;
const mode = process.env.FALUSS_V3_WP_MODE;
if (!base || !slug || !['simple', 'atomic'].includes(mode)) {
    throw new Error('Set FALUSS_V3_WP_BASE, SLUG and MODE');
}
(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: process.env.FALUSS_BROWSER_EXECUTABLE || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe' });
    try {
        const page = await browser.newPage();
        const cards = {};
        for (const [surface, route, selector] of [
            ['public', '/' + slug + '/', '.faluss-link-card'],
            ['shortcode', '/shortcode-' + mode + '/', '.faluss-link-card'],
            ['widget', '/widget-' + mode + '/', '.elementor-widget-faluss_link_card .faluss-link-card']
        ]) {
            const response = await page.goto(base + route);
            if (response.status() !== 200) { throw new Error(surface + ' HTTP ' + response.status()); }
            cards[surface] = await page.locator(selector).first().evaluate((card) => ({
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
        if (JSON.stringify(cards.public) !== JSON.stringify(cards.shortcode) || JSON.stringify(cards.public) !== JSON.stringify(cards.widget)) {
            throw new Error('Card content differs between surfaces: ' + JSON.stringify(cards));
        }
        console.log(JSON.stringify({ mode, slug, equivalent: Object.keys(cards), card: cards.public }, null, 2));
    } finally {
        await browser.close();
    }
})().catch((error) => { console.error(error); process.exitCode = 1; });
