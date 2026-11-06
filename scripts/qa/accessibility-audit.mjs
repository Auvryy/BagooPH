import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

const { chromium } = await import(pathToFileURL(resolve(process.env.BAGOO_PLAYWRIGHT_MODULE)).href);
const axe = process.env.BAGOO_AXE_SCRIPT;
if (!axe) throw new Error('Set BAGOO_AXE_SCRIPT to an installed axe-core browser script.');
const origin = new URL(process.env.BAGOO_QA_URL || 'http://127.0.0.1:8000');
if (!['127.0.0.1', 'localhost', '[::1]'].includes(origin.hostname)) throw new Error('Local server required.');
const password = process.env.BAGOO_QA_PASSWORD || readFileSync('database/seeders/DatabaseSeeder.php', 'utf8')
    .match(/'password' => '([^']+)'/)?.[1];
const output = resolve(process.env.BAGOO_QA_OUTPUT || 'storage/app/qa-accessibility-2026-11-03');
mkdirSync(output, { recursive: true });
const browser = await chromium.launch({ headless: true,
    ...(process.env.BAGOO_CHROME_PATH ? { executablePath: process.env.BAGOO_CHROME_PATH } : {}) });
const results = [];
const roles = [
    ['seller', 'seller', ['/seller/dashboard', '/seller/settings', '/seller/profile', '/seller/messages']],
    ['courier', 'rider', ['/courier/deliveries', '/courier/profile', '/courier/messages']],
    ['hub', 'logistics', ['/hub/dashboard', '/hub/scan', '/hub/deliveries', '/hub/counter', '/hub/network']],
    ['admin', 'admin', ['/admin/dashboard', '/admin/users', '/admin/kyc']],
];
async function inspect(page, role, path) {
    await page.waitForTimeout(250);
    await page.addScriptTag({ path: axe });
    const audit = await page.evaluate(() => window.axe.run(document, {
        runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa'] },
        resultTypes: ['violations', 'incomplete'],
    }));
    const entry = { role, path, width: page.viewportSize().width, engine: audit.testEngine,
        violations: audit.violations.map(x => ({ id: x.id, impact: x.impact, help: x.help, helpUrl: x.helpUrl,
            nodes: x.nodes.map(n => ({ target: n.target, summary: n.failureSummary })) })),
        incompleteRules: audit.incomplete.map(x => x.id) };
    results.push(entry);
    console.log(JSON.stringify({ role, path, width: entry.width,
        violations: entry.violations.map(x => ({ id: x.id, impact: x.impact, nodes: x.nodes.length })),
        incompleteRules: entry.incompleteRules }));
}
try {
    for (const [role, account, paths] of roles) {
        const context = await browser.newContext({ viewport: { width: 390, height: 844 }, reducedMotion: 'reduce' });
        const page = await context.newPage();
        await page.goto(new URL(`/${role}/login`, origin).href);
        await page.locator('input[name=email]').waitFor();
        await inspect(page, role, `/${role}/login`);
        await page.locator('input[name=email]').fill(`${account}@bagoo.test`);
        await page.locator('input[name=password]').fill(password);
        await page.locator('button[type=submit]').click();
        await page.waitForURL(url => !url.pathname.endsWith('/login'));
        for (const path of paths) {
            await page.goto(new URL(path, origin).href);
            await page.waitForFunction(() => document.querySelector('#app')?.children.length > 0);
            await inspect(page, role, path);
        }
        await context.close();
    }
} finally {
    await browser.close();
    writeFileSync(resolve(output, 'results.json'), JSON.stringify({
        scope: 'Automated axe-core WCAG A/AA rules on selected rendered local pages; not a conformance certification.',
        limits: ['Only the currently rendered states and listed viewport are scanned.',
            'Keyboard, focus order, screen reader use, gestures and incomplete rules require their own review.',
            'Buyer application and buyer UI are excluded.'], results,
    }, null, 2) + '\n');
}
