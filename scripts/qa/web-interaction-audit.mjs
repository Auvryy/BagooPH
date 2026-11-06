import assert from 'node:assert/strict';
import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

// An opt-in browser audit. Uses seeded local accounts; never submits business commands.
// Playwright is supplied by the caller so the application dependency tree is unchanged.
const modulePath = process.env.BAGOO_PLAYWRIGHT_MODULE;
if (!modulePath) throw new Error('Set BAGOO_PLAYWRIGHT_MODULE to the installed Playwright entrypoint.');
const engines = await import(pathToFileURL(resolve(modulePath)).href);
const engineName = process.env.BAGOO_QA_ENGINE || 'chromium';
if (!['chromium', 'firefox', 'webkit'].includes(engineName)) throw new Error('Unsupported browser engine.');
const base = new URL(process.env.BAGOO_QA_URL || 'http://127.0.0.1:8000');
if (!['127.0.0.1', 'localhost', '[::1]'].includes(base.hostname)) {
    throw new Error('This runner is restricted to a local development server.');
}
const output = resolve(process.env.BAGOO_QA_OUTPUT || 'storage/app/qa-web-2026-11-03');
mkdirSync(output, { recursive: true });
const password = process.env.BAGOO_QA_PASSWORD || readFileSync('database/seeders/DatabaseSeeder.php', 'utf8')
    .match(/'password' => '([^']+)'/)?.[1];
assert.ok(password, 'A seeded local account password is required.');
const browser = await engines[engineName].launch({ headless: true,
    ...(engineName === 'chromium' && process.env.BAGOO_CHROME_PATH ? { executablePath: process.env.BAGOO_CHROME_PATH } : {}) });
const results = [];
const pages = [];
const roles = [
    { key: 'seller', email: 'seller@bagoo.test', home: '/seller/dashboard', login: '/seller/login' },
    { key: 'courier', email: 'rider@bagoo.test', home: '/courier/deliveries', login: '/courier/login' },
    { key: 'hub', email: 'logistics@bagoo.test', home: '/hub', login: '/hub/login' },
    { key: 'admin', email: 'admin@bagoo.test', home: '/admin/dashboard', login: '/admin/login' },
];
const widths = (process.env.BAGOO_QA_WIDTHS || '390,768,1440').split(',').map(Number);
const record = (name, status, details = {}) => { results.push({ name, status, ...details });
    console.log(JSON.stringify(results.at(-1))); };

async function settle(page) {
    await page.waitForFunction(() => document.querySelector('#app')?.children.length > 0, undefined, { timeout: 15000 });
    await page.waitForTimeout(180);
}
async function check(name, operation) {
    try { record(name, 'pass', await operation() || {}); }
    catch (error) { record(name, 'fail', { error: error.message.replaceAll(base.origin, '<LOCAL_ORIGIN>').slice(0, 500) }); }
}
async function metrics(page) {
    return page.evaluate(() => {
        const visible = el => !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length)
            && getComputedStyle(el).visibility !== 'hidden';
        const name = el => el.getAttribute('aria-label') || el.getAttribute('title')
            || (el.getAttribute('aria-labelledby') || '').split(' ').map(id => document.getElementById(id)?.textContent || '').join(' ').trim()
            || [...(el.labels || [])].map(label => label.textContent).join(' ').trim()
            || (!el.matches('input,textarea,select') ? el.textContent?.trim() : '') || el.getAttribute('alt');
        return { viewport: innerWidth, document: document.documentElement.scrollWidth,
            content: !!document.body.innerText.trim(),
            hiddenControls: [...document.querySelectorAll('input:not([type=hidden]),textarea,button')].filter(visible)
                .filter(el => { const r = el.getBoundingClientRect(); return r.left >= innerWidth || r.right <= 0; })
                .map(el => ({ tag: el.tagName, type: el.getAttribute('type'), label: name(el)?.slice(0, 90),
                    x: Math.round(el.getBoundingClientRect().x), width: Math.round(el.getBoundingClientRect().width) })),
            unnamedButtons: [...document.querySelectorAll('button')].filter(visible).filter(el => !name(el)).length,
            unlabelledFields: [...document.querySelectorAll('input:not([type=hidden]):not([type=submit]),textarea,select')]
                .filter(visible).filter(el => !name(el)).map(el => ({ tag: el.tagName, type: el.getAttribute('type'), id: el.id })),
            brokenImages: [...document.images].filter(visible).filter(el => el.complete && el.naturalWidth === 0).length,
            navigation: performance.getEntriesByType('navigation').map(x => ({ responseMs: Math.round(x.responseStart),
                domReadyMs: Math.round(x.domContentLoadedEventEnd), transferBytes: x.transferSize }))[0] };
    });
}

try {
    for (const role of roles) {
        const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
        const page = await context.newPage();
        page.setDefaultNavigationTimeout(20000);
        page.setDefaultTimeout(15000);
        const exceptions = [];
        page.on('pageerror', error => exceptions.push(error.message.slice(0, 200)));
        await check(`${role.key}: login renders`, async () => {
            const response = await page.goto(new URL(role.login, base).href);
            await settle(page);
            assert.equal(response.status(), 200);
            assert.ok(await page.locator('input[name=email]').isVisible());
        });
        await check(`${role.key}: required login fields`, async () => {
            await page.locator('button[type=submit]').click();
            assert.equal(await page.locator('input[name=email]').evaluate(el => el.validity.valueMissing), true);
            assert.equal(new URL(page.url()).pathname, role.login);
        });
        await check(`${role.key}: invalid login feedback`, async () => {
            await page.locator('input[name=email]').fill(role.email);
            await page.locator('input[name=password]').fill('invalid-local-QA-password');
            await page.locator('button[type=submit]').click();
            await page.waitForFunction(() => /credentials|incorrect|invalid|match|too many/i.test(document.body.innerText), undefined, { timeout: 15000 });
            assert.equal(new URL(page.url()).pathname, role.login);
        });
        await check(`${role.key}: login pending and recovery`, async () => {
            await page.locator('input[name=password]').fill(password);
            const pattern = '**/login';
            await page.route(pattern, async route => {
                if (route.request().method() === 'POST') await new Promise(resolve => setTimeout(resolve, 500));
                await route.continue();
            });
            await page.locator('button[type=submit]').click();
            assert.equal(await page.locator('button[type=submit]').isDisabled(), true);
            await page.waitForURL(url => !url.pathname.endsWith('/login'), { timeout: 20000 });
            await page.unroute(pattern);
            await settle(page);
            assert.ok(new URL(page.url()).pathname.startsWith(`/${role.key}`));
        });
        if (new URL(page.url()).pathname.endsWith('/login')) { await context.close(); continue; }
        await check(`${role.key}: session persists after reload`, async () => {
            await page.reload(); await settle(page);
            assert.ok(!new URL(page.url()).pathname.endsWith('/login'));
        });
        const targets = await page.locator('a[href]').evaluateAll((anchors, roleKey) =>
            [...new Set(anchors.map(a => { try { return new URL(a.href); } catch { return null; } })
                .filter(url => url && url.origin === location.origin && url.pathname.startsWith(`/${roleKey}`)
                    && !/logout|download|proof|evidence|artwork/.test(url.pathname))
                .map(url => url.pathname + url.search))], role.key);
        for (const target of targets) {
            for (const width of widths) {
                await check(`${role.key}: ${target} at ${width}px`, async () => {
                    await page.setViewportSize({ width, height: width < 600 ? 844 : 1000 });
                    const before = exceptions.length;
                    const response = await page.goto(new URL(target, base).href);
                    await settle(page);
                    const result = await metrics(page);
                    pages.push({ role: role.key, path: target, width, status: response.status(), ...result });
                    await page.screenshot({ path: resolve(output, `${role.key}-${target.replace(/[^a-z0-9]/gi, '_')}-${width}.png`), fullPage: true });
                    assert.equal(response.status(), 200);
                    assert.ok(result.content, 'Page content is empty');
                    assert.ok(result.document <= width + 1, `Document overflow: ${result.document}px > ${width}px`);
                    assert.equal(exceptions.length, before, exceptions.slice(before).join('; '));
                    return { path: target, width, hiddenControls: result.hiddenControls.length,
                        unnamedButtons: result.unnamedButtons, unlabelledFields: result.unlabelledFields.length,
                        brokenImages: result.brokenImages, navigation: result.navigation };
                });
            }
        }
        if (role.key === 'seller') {
            await check('seller: search produces useful empty state and restores', async () => {
                await page.goto(new URL('/seller/products', base).href); await settle(page);
                const search = page.locator('#inventory-search');
                await search.fill('QA-NO-SUCH-PRODUCT-20261103');
                await search.press('Enter');
                await page.getByText('No listings match these filters.', { exact: false }).waitFor();
                assert.equal(new URL(page.url()).searchParams.get('search'), 'QA-NO-SUCH-PRODUCT-20261103');
                await search.fill(''); await search.press('Enter'); await page.waitForTimeout(1000);
            });
            for (const width of [320, 390, 768, 1280]) {
                await check(`seller: conversation navigation and draft at ${width}px`, async () => {
                    await page.setViewportSize({ width, height: 844 });
                    const historyRequests = [];
                    const track = request => { if (new URL(request.url()).pathname.startsWith('/chat/messages/')) historyRequests.push(request); };
                    page.on('request', track);
                    await page.goto(new URL('/seller/messages', base).href); await settle(page);
                    const list = page.locator('[aria-label="Conversation list"]');
                    const contact = list.locator('button').first();
                    if (!await contact.count()) throw new Error('No seeded conversation available');
                    if (width < 1024) {
                        assert.equal(historyRequests.length, 0, 'A hidden mobile conversation was fetched and marked read');
                        await contact.click();
                        assert.equal(await list.isVisible(), false);
                        await page.waitForFunction(() => document.activeElement?.matches('[aria-label="Selected conversation"] h3'));
                    }
                    const input = page.locator('input[aria-label^="Message to"]');
                    await input.fill('Unsent QA draft');
                    const box = await input.boundingBox();
                    assert.ok(box && box.x >= 0 && box.x + box.width <= width, 'Reply field is clipped');
                    const send = page.getByRole('button', { name: 'Send message', exact: true });
                    const sendBox = await send.boundingBox();
                    assert.ok(sendBox && sendBox.x >= 0 && sendBox.x + sendBox.width <= width, 'Send button is clipped');
                    if (width < 1024) {
                        await page.getByRole('button', { name: 'Back to conversations', exact: true }).click();
                        assert.equal(await list.isVisible(), true);
                        await page.waitForFunction(() => document.activeElement?.closest('[aria-label="Conversation list"]'));
                        await contact.click();
                        assert.equal(await input.inputValue(), 'Unsent QA draft');
                    }
                    page.off('request', track);
                    await page.screenshot({ path: resolve(output, `seller-conversation-${width}.png`), fullPage: true });
                });
            }
            await check('seller: failed history read offers working retry', async () => {
                await page.route('**/chat/messages/*', route => route.abort('failed'));
                await page.goto(new URL('/seller/messages', base).href); await settle(page);
                if (await page.locator('[aria-label="Conversation list"]').isVisible()
                    && await page.getByRole('button', { name: 'Back to conversations', exact: true }).isHidden()) {
                    const list = page.locator('[aria-label="Conversation list"]');
                    if ((await page.viewportSize()).width < 1024) await list.locator('button').first().click();
                }
                const failure = page.getByRole('alert').filter({ hasText: 'Could not update this conversation' });
                await failure.waitFor();
                await page.unroute('**/chat/messages/*');
                await failure.getByRole('button', { name: 'Retry', exact: true }).click();
                await failure.waitFor({ state: 'hidden' });
            });
        }
        const labelledPages = role.key === 'seller' ? [
            ['/seller/settings', ['Storefront Brand Name:', 'Storefront Description & Brand Story:', 'Hub City / Region:',
                'Courier Pickup Hub Address (Warehouse):', 'Store Logo Image URL or Uploaded Path:', 'Store Banner Cover URL or Uploaded Path:']],
            ['/seller/profile', ['Full Name', 'Email Address', 'Mobile / Phone']],
        ] : role.key === 'hub' ? [
            ['/hub/counter', ['Search counter parcels']], ['/hub/network', ['Search facilities']],
        ] : role.key === 'admin' ? [['/admin/kyc', ['Search applicants']]] : [];
        for (const [path, labels] of labelledPages) {
            await check(`${role.key}: fields have accessible names on ${path}`, async () => {
                await page.goto(new URL(path, base).href); await settle(page);
                for (const label of labels) assert.ok(await page.getByLabel(label, { exact: false }).first().isVisible(), label);
            });
        }
        if (role.key === 'courier') {
            await check('courier: account menu keyboard dismissal and mobile navigation', async () => {
                await page.setViewportSize({ width: 390, height: 844 });
                await page.goto(new URL(role.home, base).href); await settle(page);
                const menu = page.getByRole('button', { name: 'Account options', exact: true });
                await menu.click(); assert.equal(await menu.getAttribute('aria-expanded'), 'true');
                await page.keyboard.press('Escape'); assert.equal(await menu.getAttribute('aria-expanded'), 'false');
                const nav = page.getByRole('navigation', { name: 'Rider navigation', exact: true });
                assert.ok(await nav.isVisible());
                for (const link of await nav.locator('a').all()) {
                    const box = await link.boundingBox();
                    assert.ok(box && box.x >= 0 && box.x + box.width <= 391, 'Bottom navigation control is clipped');
                }
            });
        }
        await check(`${role.key}: other role restricted`, async () => {
            const forbidden = role.key === 'admin' ? '/seller/dashboard' : '/admin/dashboard';
            const response = await page.goto(new URL(forbidden, base).href);
            assert.ok([403, 404].includes(response.status()) || new URL(page.url()).pathname !== forbidden,
                `Unexpected role access: ${response.status()}`);
        });
        await context.close();
    }
} finally {
    await browser.close();
    writeFileSync(resolve(output, 'results.json'), JSON.stringify({
        environment: `Local existing seeded development data; ${engineName} headless; reduced motion; no business mutations`,
        limits: ['No buyer UI tested or edited.', 'Only navigation links exposed by the seeded account are swept.',
            'Semantic-name scan is a candidate-finding heuristic, not a WCAG certification.',
            'Login and ordinary session/read acknowledgements may be persisted; business commands are not submitted.',
            'Local timings are observations, not production capacity or load acceptance.'],
        results, pages,
    }, null, 2) + '\n');
}
console.log(JSON.stringify({ passed: results.filter(x => x.status === 'pass').length,
    failed: results.filter(x => x.status === 'fail').length, renderedPages: pages.length }));
if (results.some(x => x.status === 'fail')) process.exitCode = 1;
