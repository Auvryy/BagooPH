import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { buildSync } from 'esbuild';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createInertiaApp, router } from '@inertiajs/react';
import axios from 'axios';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-settings-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const bundle = buildSync({
    stdin: { contents: `export * from '@/utils/accountRequests';
        export { default as Buyer } from '@/Pages/Buyer/Profile';
        export { default as Seller } from '@/Pages/Seller/Profile';
        export { default as Courier } from '@/Pages/Courier/Profile';`,
        resolveDir: resolve(import.meta.dirname, '../..'), loader: 'tsx' },
    bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', write: false,
    loader: { '.css': 'empty' },
});
const path = join(temporary, 'settings.mjs');
writeFileSync(path, bundle.outputFiles[0].text);
const ui = await import(pathToFileURL(path).href);
globalThis.route = (name) => name ? `/${name.replaceAll('.', '/')}` : { current: () => false, has: () => true };
after(() => delete globalThis.route);
const subject = { id: 7, name: 'Ana Santos', role: 'buyer', shop_id: null, source_token: 'a'.repeat(64), values: { name: 'Ana Santos', birthday: null }, fields: [{ key: 'name', label: 'Name', type: 'text', options: [] }], requests: [], required_documents: ['id'], provenance: { source: 'recorded_review', kyc_decision_id: 1, shop_review_decision_id: null } };
const account = { id: 7, name: 'Ana Santos', email: 'buyer@bagoo.test', role: 'buyer', kyc_status: 'approved', phone: '+639171234567', birthday: null, sex: 'Female' };
const emailSettings = { original: account.email, can_manage: true, addresses: [{ id: 1, email: account.email, is_original: true, verified: true, preferred: true }, { id: 2, email: 'contact@bagoo.test', is_original: false, verified: true, preferred: false }] };
async function render(Component, name, props, role) {
    let view;
    const result = await createInertiaApp({
        page: { component: name, props: { auth: { user: { ...account, role } }, flash: {}, cartCount: 0, ...props, emailSettings, identityCorrection: { subject: { ...subject, role }, categories: [] } }, url: '/account/settings', version: null },
        resolve: () => Component,
        setup: ({ App, props }) => { view = createElement(App, props); return view; },
        render: () => renderToStaticMarkup(view),
    });
    return result.body;
}
function assertNoNestedForms(html) {
    let open = false;
    for (const match of html.matchAll(/<\/?form\b[^>]*>/g)) {
        if (match[0].startsWith('</')) { assert.ok(open); open = false; }
        else { assert.ok(!open, 'Settings must not nest correction and profile forms'); open = true; }
    }
    assert.equal(open, false);
}

function htmlElements(html) {
    const stack = [], elements = [], voidTags = new Set(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr']);
    for (const match of html.matchAll(/<(\/?)([a-z][\w:-]*)([^>]*)>/gi)) {
        const [, closing, tag, attributes] = match;
        if (closing) { const node = stack.pop(); assert.equal(node?.tag, tag); node.end = match.index + match[0].length; continue; }
        const node = { tag, attributes: Object.fromEntries([...attributes.matchAll(/([\w:-]+)="([^"]*)"/g)].map(item => [item[1].toLowerCase(), item[2]])), parent: stack.at(-1), start: match.index, end: match.index + match[0].length };
        elements.push(node); if (!voidTags.has(tag) && !attributes.endsWith('/')) stack.push(node);
    }
    return elements;
}
const within = (node, parent) => { for (let current = node.parent; current; current = current.parent) if (current === parent) return true; return false; };
const buyerSettingsProps = { user: account, initialTab: 'account', addresses: [], wallet: { available: false, balance: null, currency: 'PHP', recent_transactions: [] }, orders: { data: [], current_page: 1, last_page: 1, per_page: 12, total: 0 }, orderCounts: { all: 0 }, currentOrderStatus: 'all', ordersCount: 0 };

test('buyer settings group verification within identity correction before login activity with unique anchors', async () => {
    const html = await render(ui.Buyer, 'Buyer/Profile', buyerSettingsProps, 'buyer');
    const nodes = htmlElements(html), byId = id => nodes.find(node => node.attributes.id === id);
    const ids = nodes.map(node => node.attributes.id).filter(Boolean);
    assert.equal(new Set(ids).size, ids.length, 'An anchor must identify exactly one section');
    const ordered = ['account-personal', 'account-emails', 'account-password', 'account-identity', 'login-activity'].map(byId);
    assert(ordered.every(Boolean));
    for (let i = 1; i < ordered.length; i++) { assert(ordered[i - 1].start < ordered[i].start); assert.equal(ordered[i - 1].parent, ordered[i].parent); }
    assert(within(byId('account-verification'), byId('account-identity')));
    assert(within(byId('identity-correction'), byId('account-identity')));
    assert.equal(html.match(/>Identity correction</g)?.length, 2, 'One sidebar label and one group heading');
    assert.doesNotMatch(html, />Identity details</);
    assert.doesNotMatch(html, /unlimited Cash on Delivery|instant priority order dispatch|100% Cash on Delivery protection/);
    assertNoNestedForms(html);
});

test('buyer content headings include icons and correction fields fit the existing settings controls', async () => {
    const html = await render(ui.Buyer, 'Buyer/Profile', buyerSettingsProps, 'buyer');
    const nodes = htmlElements(html);
    for (const title of ['Personal Information', 'Email and recovery', 'Account Password &amp; Security', 'Identity correction', 'Identity verification', 'Request a correction', 'Requests and decisions']) {
        const heading = nodes.find(node => node.attributes['data-account-section-heading'] === title);
        assert(heading, `Missing content heading: ${title}`); assert(nodes.some(node => node.tag === 'svg' && within(node, heading)), `Missing content icon: ${title}`);
    }
    const correction = nodes.find(node => node.attributes.id === 'identity-correction');
    const form = nodes.find(node => node.tag === 'form' && within(node, correction));
    const controls = nodes.filter(node => ['input', 'select', 'textarea'].includes(node.tag) && within(node, form));
    assert(controls.some(node => node.attributes.type === 'date'));
    for (const control of controls) { assert.match(control.attributes.class, /rounded-xl/); assert.match(control.attributes.class, /min-h-11/); assert.match(control.attributes.class, /border-slate-300/); }
    assert.equal(controls.find(node => node.tag === 'textarea').attributes.maxlength, '1000');
    assert.match(controls.find(node => node.attributes.type === 'file').attributes.accept, /\.pdf/);
    assert.match(html.slice(form.start, form.end), /PDF.*5 MB|5 MB.*PDF/);
});

test('pending verification and correction forms remain separate and the upload trigger supports a keyboard', async () => {
    const html = await render(ui.Buyer, 'Buyer/Profile', { ...buyerSettingsProps, user: { ...account, kyc_status: 'rejected', kyc_feedback: 'Upload a clearer document.' } }, 'buyer');
    const nodes = htmlElements(html), verification = nodes.find(node => node.attributes.id === 'account-verification');
    const upload = nodes.find(node => node.tag === 'button' && node.attributes.type === 'button' && within(node, verification) && /Upload New Government ID/.test(html.slice(node.start, node.end)));
    assert(upload); assert.match(html, /Upload a clearer document\./); assertNoNestedForms(html);
});
test('buyer account embeds corrections and emails with an empty, read-only missing birth date', async () => {
    const html = await render(ui.Buyer, 'Buyer/Profile', { user: account, initialTab: 'account', addresses: [], wallet: { available: false, balance: null, currency: 'PHP', recent_transactions: [] }, orders: { data: [], current_page: 1, last_page: 1, per_page: 12, total: 0 }, orderCounts: { all: 0 }, currentOrderStatus: 'all', ordersCount: 0 }, 'buyer');
    assert.match(html, /id="identity-correction"/);
    assert.match(html, /data-settings-theme="buyer"/);
    assert.match(html, /type="date"[^>]*readonly=""[^>]*value=""/);
    assert.doesNotMatch(html, /2000-01-15|href="\/account\/identity-corrections"/);
    assert.match(html, /href="#identity-correction"/);
    assert.match(html, /contact@bagoo.test/);
    assertNoNestedForms(html);
});
test('seller account embeds the same reviewed workflow in merchant settings', async () => {
    const html = await render(ui.Seller, 'Seller/Profile', { user: { ...account, role: 'seller' }, shop: { id: 5, name: 'Bagoo Crafts', root_category_id: 2 } }, 'seller');
    assert.match(html, /data-settings-theme="seller"/);
    assert.match(html, /id="identity-correction"/);
    assert.doesNotMatch(html, /href="\/account\/identity-corrections"/);
    assertNoNestedForms(html);
});
test('buyer saves and order actions expose shared feedback exactly once', async () => {
    const html = await render(ui.Buyer, 'Buyer/Profile', {
        user: account, initialTab: 'account', addresses: [], orders: { data: [], current_page: 1, last_page: 1, per_page: 12, total: 0 }, orderCounts: { all: 0 }, currentOrderStatus: 'all', ordersCount: 0,
        wallet: { available: false, balance: null, currency: 'PHP', recent_transactions: [] },
        flash: { success: 'Your profile was saved.', error: 'This order changed. Try again.' },
    }, 'buyer');
    assert.equal(html.split('Your profile was saved.').length - 1, 1);
    assert.equal(html.split('This order changed. Try again.').length - 1, 1);
    assert.match(html, /role="status"/);
    assert.match(html, /role="alert"/);
});
test('courier settings keep reviewed name read-only and local eight pixel panels', async () => {
    const html = await render(ui.Courier, 'Courier/Profile', { rider: { name: account.name, email: account.email, phone: account.phone, account_status: 'active', kyc_status: 'approved', email_verified_at: '2026-10-08' }, assignment: { company: null, hub: null, hub_code: null, barangay: null }, vehicle: { type: null, model: null, plate_number: null, fleet_status: null, license_number: null, registration_status: null }, isOnline: false, initialTab: 'edit' }, 'courier');
    assert.match(html, /id="rider-name"[^>]*readonly=""/);
    assert.match(html, /data-settings-theme="courier"/);
    assert.match(html, /courier-panel rounded-\[8px\]/);
    assertNoNestedForms(html);
});
test('saved actions remain pending until refreshed server props arrive', async () => {
    const original = router.reload;
    let callbacks;
    router.reload = (options) => { callbacks = options; };
    try {
        let settled = false;
        const pending = ui.refreshPage().then(() => { settled = true; });
        await Promise.resolve(); assert.equal(settled, false);
        callbacks.onSuccess(); await pending;
        assert.equal(settled, true); callbacks.onFinish();
    } finally { router.reload = original; }
});
test('a failed refresh distinguishes a saved action from a failed save', async () => {
    const original = router.reload;
    router.reload = (options) => options.onFinish();
    try { await assert.rejects(ui.refreshPage(), /change was saved.*updated view could not load/); }
    finally { router.reload = original; }
});
test('request errors expose field feedback and session recovery without resubmitting a mutation', () => {
    const error = new axios.AxiosError('Failed');
    error.response = { status: 422, data: { errors: { phone: ['Enter a valid number.'] } } };
    assert.deepEqual(ui.requestErrors(error), { phone: 'Enter a valid number.' });
    error.response = { status: 419, data: {} };
    assert.match(ui.requestErrors(error).request, /session expired/);
    error.response = { status: 409, data: { message: 'The record changed. Reload first.' } };
    assert.equal(ui.requestErrors(error).request, 'The record changed. Reload first.');
});
