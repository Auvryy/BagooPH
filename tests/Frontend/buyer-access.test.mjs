import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createInertiaApp, router } from '@inertiajs/react';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-buyer-access-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const bundle = await build({
    stdin: { contents: `export { default as Notifications } from '@/Pages/Notifications/Index';
        export { default as ApplicationModal, buyerApplicationLabel } from '@/Components/BuyerApplicationModal';
        export { openNotice } from '@/Components/NotificationMenu';`,
        resolveDir: resolve(import.meta.dirname, '../..'), loader: 'tsx' },
    bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', write: false,
    loader: { '.css': 'empty' },
    plugins: [{ name: 'server-dialog-content', setup(builder) {
        builder.onResolve({ filter: /^@headlessui\/react$/ }, args => args.importer.endsWith('/BuyerApplicationModal.tsx') ? { path: 'dialog', namespace: 'server-dialog' } : undefined);
        builder.onLoad({ filter: /.*/, namespace: 'server-dialog' }, () => ({ contents: `
            import { createElement } from 'react';
            export const Dialog = ({ open, children }) => open ? createElement('div', { role: 'dialog' }, children) : null;
            export const DialogPanel = ({ children, className }) => createElement('div', { className }, children);
            export const DialogTitle = ({ children, className }) => createElement('h2', { className }, children);
        `, resolveDir: resolve(import.meta.dirname, '../..') }));
    } }],
});
const path = join(temporary, 'buyer-access.mjs');
writeFileSync(path, bundle.outputFiles[0].text);
const ui = await import(pathToFileURL(path).href);
globalThis.route = name => name ? `/${name.replaceAll('.', '/')}` : { current: () => false, has: () => true };
after(() => { delete globalThis.route; });
const user = { id: 7, name: 'Ana Dela Cruz', role: 'buyer', email: 'buyer@bagoo.test', status: 'active', kyc_status: 'pending_approval' };
const limits = { today: '2026-10-15', past_maximum: '2026-10-14', adult_maximum: '2008-10-15' };
const application = { user, can_use_portal: false, birth_date_limits: limits, identity_document_url: null,
    application: { values: { birthday: '2000-01-01' }, fields: [], errors: {}, can_correct: true } };
async function render(component, props, actor = user) {
    let view;
    const result = await createInertiaApp({
        page: { component: 'Buyer/Test', props: { auth: { user: actor }, flash: {}, cartCount: 0, notificationSummary: { available: true, unread: 1 }, ...props }, url: '/buyer/profile', version: null },
        resolve: () => component,
        setup: ({ App, props }) => { view = createElement(App, props); return view; },
        render: () => renderToStaticMarkup(view),
    });
    return result.body;
}

test('buyer application is a closable buyer-styled dialog with a required adult date and useful actions', async () => {
    const html = await render(ui.ApplicationModal, { data: application, open: true, onClose() {} });
    assert.match(html, /role="dialog"/);
    assert.match(html, /Your application is under review/);
    assert.match(html, /Close application/);
    assert.match(html, /Check status/);
    assert.match(html, /Continue browsing/);
    assert.match(html, /max="2008-10-15"/);
    assert.match(html, /name="birthday"[^>]*required=""/);
    assert.match(html, /You must be at least 18/);
    assert.doesNotMatch(html, /Date of birth \(optional\)|Shopping Bag.*need an active approved/);
});

test('an approved but suspended buyer sees a restriction instead of an invitation to checkout', () => {
    assert.match(ui.buyerApplicationLabel({ ...application, user: { ...user, status: 'suspended', kyc_status: 'approved' } }), /restricted/);
    assert.match(ui.buyerApplicationLabel({ ...application, can_use_portal: true }), /approved/);
});

test('complete buyer notifications keep the marketplace shell and highlight unread updates', async () => {
    const html = await render(ui.Notifications, { portal: 'buyer', available: true, accountUrl: '/pending-approval', filter: 'all', notices: { data: [{
        id: 'owned-notice', type: 'governance-event', data: { title: 'Application approved', body: 'Your review is complete.' },
        read_at: null, created_at: '2026-10-15T00:00:00Z', href: '/buyer/profile?tab=account',
    }], current_page: 1, last_page: 1 } });
    assert.match(html, /Marketplace/);
    assert.match(html, /Search 14 departments/);
    assert.match(html, /Shopping Bag/);
    assert.match(html, /Application approved/);
    assert.match(html, /aria-label="Unread"/);
    assert.match(html, /border-rose-300 bg-rose-50/);
    assert.match(html, /View update/);
    assert.doesNotMatch(html, />My account<|min-h-screen bg-\[#FFFAFB\]/);
});

test('empty buyer notifications explain the absence of updates inside the buyer shell', async () => {
    const html = await render(ui.Notifications, { portal: 'buyer', available: true, accountUrl: '/pending-approval', filter: 'unread', notices: { data: [], current_page: 1, last_page: 1 } });
    assert.match(html, /Marketplace/);
    assert.match(html, /You have no unread updates/);
});

test('opening an update acknowledges it with an Inertia visit instead of navigating straight past its read action', () => {
    const original = router.patch;
    let call;
    router.patch = (...args) => { call = args; };
    try {
        ui.openNotice('owned-notice');
        assert.equal(call[0], '/notifications/owned-notice/read');
        assert.deepEqual(call[1], { open: true });
        assert.equal(call[2].preserveScroll, true);
    } finally { router.patch = original; }
});

test('ordinary buyer status and notification controls do not force browser reloads', () => {
    for (const file of ['resources/js/Components/BuyerApplicationModal.tsx', 'resources/js/Components/NotificationMenu.tsx', 'resources/js/Pages/Notifications/Index.tsx']) {
        assert.doesNotMatch(readFileSync(resolve(import.meta.dirname, '../..', file), 'utf8'), /window\.location\.(?:reload|assign)|location\.href\s*=/);
    }
});
