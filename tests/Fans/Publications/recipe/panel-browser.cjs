// Optional Windows browser proof against the disposable WSL recipe. Never logs sessions.
const { chromium } = require(require('node:path').join(process.argv[2], 'playwright'));
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const fs = require('node:fs');

(async () => {
    const sessions = JSON.parse(execFileSync('wsl.exe', ['-u', 'root', 'cat', '/var/tmp/faluss-image-proof/sessions.json'], { encoding: 'utf8' }));
    const a = sessions.admin;
    const cookie = `${a.cookie_name}=${a.cookie}; ${a.admin_cookie_name}=${a.admin_cookie}`;
    const browser = await chromium.launch({ headless: true, channel: 'msedge' });
    try {
        const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, javaScriptEnabled: false });
        const localRoute = async route => {
            const u = new URL(route.request().url());
            if (u.hostname !== 'text.local.test') return route.abort();
            // Unrelated WordPress heartbeat/compression probes are outside this UI proof.
            if (u.pathname === '/wp-admin/admin-ajax.php') return route.abort();
            try {
                const response = await route.fetch({ url: `http://127.0.0.1:8113${u.pathname}${u.search}`, headers: { ...route.request().headers(), Host: 'text.local.test', Cookie: cookie } });
                if (u.pathname === '/wp-admin/admin-post.php') console.log('Preview HTTP', response.status(), response.headers()['content-type']);
                await route.fulfill({ response });
            } catch (error) {
                // Playwright exception details can contain cookies: never print them.
                console.log('Local bridge failure', u.pathname, error.message.includes('timed out') ? 'timeout' : error.message.includes('decompress') ? 'compression' : 'transport');
                await route.abort();
            }
        };
        await context.route('**/*', localRoute);
        const page = await context.newPage();
        await page.goto('https://text.local.test/wp-admin/admin.php?page=faluss-fans-moderation');
        await page.locator('.fm-card').first().waitFor();
        const canvas = await page.locator('.faluss-moderation').evaluate(el => getComputedStyle(el).backgroundColor);
        if (canvas !== 'rgb(255, 253, 245)') throw new Error('Faluss stylesheet did not load');
        const directory = path.join(__dirname, 'captures');
        fs.mkdirSync(directory, { recursive: true });
        await page.screenshot({ path: path.join(directory, 'moderation-desktop.png') });
        await page.setViewportSize({ width: 390, height: 844 });
        await page.screenshot({ path: path.join(directory, 'moderation-mobile.png') });
        const overflow = await page.locator('.faluss-moderation').evaluate(el => el.scrollWidth > el.clientWidth);
        if (overflow) throw new Error('Panel overflows mobile width');
        const form = page.locator('.fm-decision').first();
        await form.locator('select').selectOption('needs_revision');
        await Promise.all([page.waitForNavigation(), form.locator('button').click()]);
        if (!(await page.locator('[role="status"]').textContent()).includes('Décision confirmée')) throw new Error('Server confirmation missing');
        console.log('PASS browser desktop/mobile, no-JavaScript form, no panel overflow, server-confirmed decision');
        const enhanced = await browser.newContext({ viewport: { width: 1280, height: 900 } });
        enhanced.setDefaultTimeout(10000);
        await enhanced.route('**/*', localRoute);
        const images = await enhanced.newPage();
        await images.goto('https://text.local.test/wp-admin/admin.php?page=faluss-fans-moderation&view=images');
        console.log('PASS enhanced panel loaded');
        await images.locator('.fm-preview button[type="submit"]').first().click();
        const preview = images.locator('.fm-preview img');
        try { await preview.waitFor(); }
        catch { console.log('Preview status:', await images.locator('.fm-preview [role="status"]').allTextContents()); throw new Error('Preview did not appear'); }
        if (!(await preview.getAttribute('src')).startsWith('blob:')) throw new Error('Preview must stay in browser memory');
        await images.screenshot({ path: path.join(directory, 'moderation-image.png') });
        await images.getByRole('button', { name: 'Fermer l’aperçu' }).click();
        if (await preview.count()) throw new Error('Preview was not removed');
        console.log('PASS enhanced authenticated image preview and explicit removal');
    } finally { await browser.close(); }
})().catch(() => { console.error('Browser proof failed; inspect local UI without logging requests or sessions.'); process.exitCode = 1; });
