import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createInertiaApp } from '@inertiajs/react';

const root = resolve(import.meta.dirname, '../..');
const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-account-center-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const bundle = await build({
    stdin: { contents: `export { default as Entry } from '@/Components/AccountCenterEntry';
        export { default as Status } from '@/Pages/Auth/AccountStatus';
        export { default as Pending } from '@/Pages/Auth/PendingApproval';`, resolveDir: root, loader: 'tsx' },
    bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', write: false, loader: { '.css': 'empty' },
    plugins: [{ name: 'account-center-interactions', setup(builder) {
        builder.onResolve({ filter: /^@headlessui\/react$/ }, args => args.importer.endsWith('/AccountCenterEntry.tsx')
            ? { path: 'dialog', namespace: 'account-center-dialog' } : undefined);
        builder.onLoad({ filter: /.*/, namespace: 'account-center-dialog' }, () => ({ contents: `import { createElement } from 'react';
            export const Dialog = ({ open, children }) => open ? createElement('div', { role: 'dialog' }, children) : null;
            export const DialogPanel = 'aside'; export const DialogTitle = 'h2';`, resolveDir: root }));
        builder.onResolve({ filter: /^@inertiajs\/react$/ }, args => args.importer.endsWith('/AccountCenterEntry.tsx')
            ? { path: 'inertia', namespace: 'account-center-inertia' } : undefined);
        builder.onLoad({ filter: /.*/, namespace: 'account-center-inertia' }, () => ({ contents: `import { createElement } from 'react';
            export const Link = props => createElement('a', { href: props.href, onClick: props.onClick, className: props.className }, props.children);
            export const router = { visit: (...args) => globalThis.accountCenterNavigate?.(...args) };`, resolveDir: root }));
        builder.onResolve({ filter: /^react$/ }, args => args.importer.endsWith('/AccountCenterEntry.tsx')
            ? { path: 'hooks', namespace: 'account-center-hooks' } : undefined);
        builder.onLoad({ filter: /.*/, namespace: 'account-center-hooks' }, () => ({ contents: `import React from 'react'; export * from 'react';
            export const useState = initial => globalThis.accountCenterHarness ? globalThis.accountCenterHarness.state(initial) : React.useState(initial);`, resolveDir: root }));
        builder.onResolve({ filter: /^react\/jsx-runtime$/ }, args => args.importer.endsWith('/AccountCenterEntry.tsx')
            ? { path: 'jsx', namespace: 'account-center-jsx' } : undefined);
        builder.onLoad({ filter: /.*/, namespace: 'account-center-jsx' }, () => ({ contents: `import { jsx as realJsx, jsxs as realJsxs, Fragment } from 'react/jsx-runtime'; export { Fragment };
            const track = (fn, type, props, key) => { if (globalThis.accountCenterHarness && props.onClick) globalThis.accountCenterHarness.handlers.push({ type, props }); return fn(type, props, key); };
            export const jsx = (type, props, key) => track(realJsx, type, props, key);
            export const jsxs = (type, props, key) => track(realJsxs, type, props, key);`, resolveDir: root }));
    } }],
});
const path = join(temporary, 'account-center.mjs');
writeFileSync(path, bundle.outputFiles[0].text);
const ui = await import(pathToFileURL(path).href);
globalThis.route = name => '/' + String(name).replaceAll('.', '/');
after(() => { delete globalThis.route; });

const text = node => Array.isArray(node) ? node.map(text).join('') : typeof node === 'object' && node ? text(node.props?.children) : String(node ?? '');
const statusDetails = {
    title: 'Your application was rejected', message: 'Read the feedback below.',
    sources: [{ type: 'account', id: 7, name: 'Your seller account', status: 'rejected', reason: 'Please upload a readable identity document.', pending_request_id: 12 }],
    requests: [{ id: 12, subject_type: 'account', subject_id: 7, message: 'I uploaded a clearer document.', status: 'pending', created_at: '2026-10-16T00:00:00Z', resolved_at: null, decision: null }],
};
class Harness {
    index = 0; values = []; handlers = [];
    state(initial) {
        const index = this.index++;
        if (!Object.hasOwn(this.values, index)) this.values[index] = typeof initial === 'function' ? initial() : initial;
        return [this.values[index], value => { this.values[index] = typeof value === 'function' ? value(this.values[index]) : value; }];
    }
    async render() {
        this.index = 0; this.handlers = [];
        return renderToStaticMarkup(createElement(ui.Entry, { statusLabel: 'Application status: IN REVIEW', statusDescription: 'Your application is waiting for review.', statusDetails }));
    }
    click(label) { const handler = this.handlers.find(item => text(item.props.children).includes(label)); assert.ok(handler, `Missing control ${label}`); handler.props.onClick({ stopPropagation() {} }); }
}

async function renderPage(Component, name, props) {
    let view;
    const result = await createInertiaApp({ page: { component: name, props: { auth: { user: null }, flash: {}, cartCount: 0, ...props }, url: '/', version: null },
        resolve: () => Component, setup: ({ App, props: pageProps }) => { view = createElement(App, pageProps); return view; }, render: () => renderToStaticMarkup(view) });
    return result.body;
}

test('the account center opens a side panel on desktop and routes to its full page on mobile', async () => {
    const originalWindow = globalThis.window;
    const visits = [];
    const harness = new Harness();
    globalThis.accountCenterHarness = harness;
    globalThis.accountCenterNavigate = (...args) => visits.push(args);
    try {
        globalThis.window = { matchMedia: () => ({ matches: false }) };
        let html = await harness.render();
        assert.match(html, /Account status, security &amp; updates/);
        assert.doesNotMatch(html, /role="dialog"/);
        harness.click('Account status, security & updates');
        assert.equal(harness.values[0], true, 'the desktop action opens its panel');
        const source = readFileSync(resolve(root, 'resources/js/Components/AccountCenterEntry.tsx'), 'utf8');
        assert.match(source, /<Dialog open=\{open\}/);
        assert.match(source, /<DialogPanel as="aside"/);
        assert.match(source, /href="\/account\/status"/);
        assert.match(source, /href="\/account\/security"/);
        assert.match(source, /href="\/notifications"/);
        assert.equal(visits.length, 0);

        globalThis.window = { matchMedia: () => ({ matches: true }) };
        harness.values[0] = false;
        harness.click('Account status, security & updates');
        assert.equal(visits[0][0], '/account/status');
    } finally {
        if (originalWindow === undefined) delete globalThis.window; else globalThis.window = originalWindow;
        delete globalThis.accountCenterNavigate;
        delete globalThis.accountCenterHarness;
    }
});

test('desktop side panel contains the current review reason and request history inline', async () => {
    const originalWindow = globalThis.window;
    const harness = new Harness();
    globalThis.accountCenterHarness = harness;
    try {
        globalThis.window = { matchMedia: () => ({ matches: false }) };
        harness.values[0] = true;
        const html = await harness.render();
        assert.match(html, /Please upload a readable identity document/);
        assert.match(html, /I uploaded a clearer document/);
        assert.match(html, /Request #12: Waiting for review/);
        assert.match(html, /href="\/account\/status"/);
    } finally {
        if (originalWindow === undefined) delete globalThis.window; else globalThis.window = originalWindow;
        delete globalThis.accountCenterHarness;
    }
});

test('pending review shows its current state before the unified account center entry', async () => {
    const user = { id: 7, name: 'Ana Santos', email: 'ana@bagoo.test', role: 'buyer', status: 'pending_approval', kyc_status: 'pending_approval', kyc_feedback: null };
    const html = await renderPage(ui.Pending, 'Auth/PendingApproval', { user, shop: null, courierProfile: null, logisticsCompany: null,
        birthDate: { value: '2000-01-01', needs_correction: false, limits: { adult_maximum: '2008-01-01' } }, sellerCategory: null,
        accountCenterStatus: statusDetails,
        application: { values: {}, fields: [], errors: {}, can_correct: false } });
    const statusIndex = html.indexOf('Application status: IN REVIEW');
    const entryIndex = html.indexOf('Account status, security &amp; updates');
    assert.ok(statusIndex >= 0 && entryIndex > statusIndex);
    assert.match(html, /aria-haspopup="dialog"/);
    assert.doesNotMatch(html, /Account status and review requests|Login activity and devices|View your account updates/);
});

test('the full account status page puts status first and groups security and updates together', async () => {
    const user = { id: 7, name: 'Ana Santos', email: 'ana@bagoo.test', role: 'buyer', status: 'pending_approval', kyc_status: 'pending_approval' };
    const status = { title: 'Your application is under review', message: 'Your application has not been approved yet.', account: user,
        sources: [], requests: [], can_correct_application: true, can_correct_identity: false, can_open_portal: false, email_update: null };
    const html = await renderPage(ui.Status, 'Auth/AccountStatus', { status });
    assert.match(html, /Current account status/);
    assert.ok(html.indexOf('Current account status') < html.indexOf('Account status, security &amp; updates'));
    assert.match(html, /href="\/account\/security"[^>]*>Login activity and devices/);
    assert.match(html, /href="\/notifications"[^>]*>Account updates/);
});

test('account status keeps approval visible when a separate shop restriction blocks the portal', async () => {
    const user = { id: 7, name: 'Ana Santos', email: 'ana@bagoo.test', role: 'seller', status: 'active', kyc_status: 'approved' };
    const status = { title: 'Your account is approved', message: 'Your account is approved. Any separate shop restriction is shown below.', account: user,
        sources: [{ type: 'shop', id: 9, name: 'Ana’s shop', status: 'suspended', reason: 'Shop review is waiting for changes.', source_token: 'a'.repeat(64) }],
        requests: [], can_correct_application: false, can_correct_identity: true, can_open_portal: false, email_update: null };
    const html = await renderPage(ui.Status, 'Auth/AccountStatus', { status });
    assert.match(html, /<h2[^>]*>Approved<\/h2>/);
    assert.match(html, /Shop review is waiting for changes/);
});

test('the pending account entry keeps one responsive entry point and exposes the three requested tools in its panel', () => {
    const source = readFileSync(resolve(root, 'resources/js/Components/AccountCenterEntry.tsx'), 'utf8');
    assert.match(source, /matchMedia\('\(max-width: 767px\)'\)/);
    assert.match(source, /href="\/account\/status"/);
    assert.match(source, /href="\/account\/security"/);
    assert.match(source, /href="\/notifications"/);
    assert.equal((source.match(/aria-haspopup="dialog"/g) ?? []).length, 1);
});
