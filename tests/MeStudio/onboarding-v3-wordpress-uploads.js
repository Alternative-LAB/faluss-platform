'use strict';

// Exercise the real V3 upload endpoints against an isolated WordPress site.
const fs = require('fs');
const { chromium } = require('playwright');
const base = process.env.FALUSS_V3_WP_BASE;
const user = process.env.FALUSS_V3_WP_USER;
const password = process.env.FALUSS_V3_WP_PASSWORD;
const files = {
    jpeg: process.env.FALUSS_V3_UPLOAD_JPEG,
    gif: process.env.FALUSS_V3_UPLOAD_GIF,
    webp: process.env.FALUSS_V3_UPLOAD_WEBP
};
if (!base || !user || !password || Object.values(files).some((file) => !file || !fs.existsSync(file))) {
    throw new Error('Set local WordPress credentials and existing JPEG, GIF and WebP file paths');
}
function assert(value, message) { if (!value) { throw new Error(message); } }

(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: process.env.FALUSS_BROWSER_EXECUTABLE || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe' });
    try {
        const context = await browser.newContext();
        const page = await context.newPage();
        await page.goto(base + '/wp-login.php');
        await page.locator('#user_login').fill(user);
        await page.locator('#user_pass').fill(password);
        await Promise.all([page.waitForNavigation(), page.locator('#wp-submit').click()]);
        await page.goto(base + '/commencer/');
        const nonces = await page.evaluate(() => ({ avatar: window.falussOnboardingV3?.avatarNonce, cover: window.falussOnboardingV3?.coverNonce }));
        assert(nonces.avatar && nonces.cover, 'V3 upload nonces unavailable');
        const results = [];
        async function send(kind, name, mimeType, buffer, nonce = nonces[kind]) {
            const action = 'faluss_onboarding_v3_upload_' + kind;
            const response = await context.request.post(base + '/wp-admin/admin-ajax.php', { multipart: {
                action, nonce, [kind]: { name, mimeType, buffer }
            } });
            const at = new Date().toISOString();
            const raw = await response.text();
            const result = { kind, name, at, status: response.status(), raw };
            results.push(result);
            return { result, parsed: JSON.parse(raw) };
        }
        for (const [extension, mime] of [['jpeg', 'image/jpeg'], ['gif', 'image/gif'], ['webp', 'image/webp']]) {
            const buffer = fs.readFileSync(files[extension]);
            for (const kind of ['avatar', 'cover']) {
                const { result, parsed } = await send(kind, 'valid-' + kind + '.' + (extension === 'jpeg' ? 'jpg' : extension), mime, buffer);
                assert(result.status === 200 && parsed.success && parsed.data.id > 0, extension + ' ' + kind + ' upload failed');
                const media = await context.request.get(parsed.data.url);
                assert(media.status() === 200 && (media.headers()['content-type'] || '').startsWith(mime), extension + ' ' + kind + ' media response mismatched');
                const refused = await send(kind, 'invalid-' + kind + '.' + (extension === 'jpeg' ? 'jpg' : extension), mime, Buffer.from('not an image'));
                assert(refused.result.status === 400 && refused.parsed.data.code === 'unsupported_image', extension + ' ' + kind + ' malformed file was not refused');
            }
        }
        for (const kind of ['avatar', 'cover']) {
            const action = 'faluss_onboarding_v3_upload_' + kind;
            const missing = await context.request.post(base + '/wp-admin/admin-ajax.php', { form: { action, nonce: nonces[kind] } });
            const missingRaw = await missing.text();
            results.push({ kind, name: 'missing', at: new Date().toISOString(), status: missing.status(), raw: missingRaw });
            assert(missing.status() === 400 && JSON.parse(missingRaw).data.code === 'missing_file', kind + ' missing file was not refused');
        }
        const oversized = await send('avatar', 'too-large.jpg', 'image/jpeg', Buffer.alloc(9 * 1024 * 1024, 0));
        assert(oversized.result.status === 400 && oversized.parsed.data.code === 'invalid_file_size', 'Oversized image did not reach the V3 size guard');
        const badNonce = await send('avatar', 'valid-avatar.jpg', 'image/jpeg', fs.readFileSync(files.jpeg), 'invalid-nonce');
        assert(badNonce.result.status === 403 && badNonce.parsed.success === false, 'Invalid nonce was not refused');
        console.log(JSON.stringify({ results }, null, 2));
    } finally {
        await browser.close();
    }
})().catch((error) => { console.error(error); process.exitCode = 1; });
