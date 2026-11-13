import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createInertiaApp } from '@inertiajs/react';

const root = resolve(import.meta.dirname, '../..');
const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-shipment-finance-'));
after(() => { rmSync(temporary, { recursive: true, force: true }); delete globalThis.shipmentHarness; delete globalThis.route; });
const probe = /Pages\/(?:Finance\/Shipment(?:Review|Outcome)|Returns\/Liabilities)\.tsx$/;
const bundle = await build({ stdin: { contents: `export { default as Review } from '@/Pages/Finance/ShipmentReview'; export { default as Outcome } from '@/Pages/Finance/ShipmentOutcome'; export { default as Queue } from '@/Pages/Returns/Liabilities';`, resolveDir: root, loader: 'tsx' },
    bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', write: false, loader: { '.css': 'empty' }, plugins: [{ name: 'shipment-controls', setup(builder) {
        builder.onResolve({ filter: /^@inertiajs\/react$/ }, args => probe.test(args.importer) ? { path: 'inertia', namespace: 'shipment-probe' } : undefined);
        builder.onLoad({ filter: /^inertia$/, namespace: 'shipment-probe' }, () => ({ contents: `export * from '@inertiajs/react'; import {useForm as real} from '@inertiajs/react'; export const useForm=initial=>globalThis.shipmentHarness?globalThis.shipmentHarness.useForm(initial):real(initial);`, resolveDir: root }));
        builder.onResolve({ filter: /^react$/ }, args => probe.test(args.importer) ? { path: 'react', namespace: 'shipment-probe' } : undefined);
        builder.onLoad({ filter: /^react$/, namespace: 'shipment-probe' }, () => ({ contents: `export * from 'react'; import React from 'react'; export const useRef=initial=>globalThis.shipmentHarness?globalThis.shipmentHarness.ref(initial):React.useRef(initial);`, resolveDir: root }));
        builder.onResolve({ filter: /^react\/jsx-runtime$/ }, args => probe.test(args.importer) ? { path: 'jsx', namespace: 'shipment-probe' } : undefined);
        builder.onLoad({ filter: /^jsx$/, namespace: 'shipment-probe' }, () => ({ contents: `import {jsx as real,jsxs as many,Fragment} from 'react/jsx-runtime';export {Fragment};const track=(fn,type,props,key)=>{if(globalThis.shipmentHarness&&(props.onSubmit||props.onChange||props.onClick))globalThis.shipmentHarness.handlers.push({type,props});return fn(type,props,key);};export const jsx=(...args)=>track(real,...args);export const jsxs=(...args)=>track(many,...args);`, resolveDir: root }));
    } }] });
const path = join(temporary, 'shipment.mjs'); writeFileSync(path, bundle.outputFiles[0].text); const ui = await import(pathToFileURL(path).href);
globalThis.route = name => name ? '/'+name.replaceAll('.', '/') : { current: () => false, has: () => true };
async function render(Component, supplied, role = 'admin') {
    if (globalThis.shipmentHarness) { globalThis.shipmentHarness.cursor = 0; globalThis.shipmentHarness.handlers = []; }
    let view;
    const response = await createInertiaApp({ page: { component: Component === ui.Review ? 'Finance/ShipmentReview' : Component === ui.Outcome ? 'Finance/ShipmentOutcome' : 'Returns/Liabilities',
        props: { auth: { user: { id: 7, role, name: 'Original participant', email: role+'@bagoo.test', status: 'active', kyc_status: 'approved', canManageReturnLiabilities: role !== 'buyer' } }, flash: {}, ...supplied }, url: '/shipment-finances/11', version: null },
        resolve: () => Component, setup: ({ App, props }) => { view = createElement(App, props); return view; }, render: () => renderToStaticMarkup(view) });
    return response.body;
}
class Harness {
    cursor = 0; refs = []; handlers = []; posts = []; form;
    ref(initial) { return this.refs[this.cursor++] ??= { current: initial }; }
    useForm(initial) { return this.form ??= { data: initial, errors: {}, processing: false, transform: fn => { this.transform = fn; },
        setData: (key, value) => { if (typeof key === 'object') this.form.data = key; else this.form.data[key] = value; },
        clearErrors: () => { this.form.errors = {}; }, setError: (key, value) => { this.form.errors[key] = value; },
        post: (url, options) => { this.posts.push({ url, options, data: structuredClone(this.transform(this.form.data)) }); this.form.processing = true; } }; }
    control(test) { const item = this.handlers.find(test); assert.ok(item, 'expected actual rendered control'); return item.props; }
    submit() { this.control(node => node.type === 'form').onSubmit({ preventDefault() {} }); }
}
const amounts = { actual_buyer_paid_cents: 55000, original_merchandise_cents: 50000, buyer_outbound_shipping_cents: 5000, reverse_fee_cents: 4000, reverse_fee_withheld_cents: 4000, buyer_refund_cents: 46000, buyer_liability_cents: 0 };
const reviewProps = { order: { id: 30, order_number: 'BGO-ORIGINAL' }, source: { payment_method: 'wallet', attempt_reference: 'DAT-ORIGINAL', return_route_reference: 'DRR-ORIGINAL', seller_receipt_reference: 'DRE-ORIGINAL', original_payment_reference: 'WPA-ORIGINAL', attempt_proof_url: '/exceptions/attempt/4/proof' }, preview: null, blocker: null };
const preview = { review_token: 'a'.repeat(64), input: { reverse_fee: '40.00', cost_reference: 'ACTUAL-COST' }, amounts };
const plan = { id: 11, reference: 'ORIGINAL-REVIEW', version: 2, kind: 'buyer_refusal', status: 'shipping_due', mode: 'wallet', order: reviewProps.order,
    amounts, state: { assessed: true, due_cents: 10000, received_cents: 0, remaining_cents: 10000, pending_offer_cents: 0 }, actions: ['pay-wallet-shipping'],
    review_reason: 'Independent review of the actual conforming parcel and reverse charge.', cost_reference: 'ACTUAL-COST', review_proof_url: null,
    wallet_balance_cents: 45000, receivers: [], pending_offers: [], history: [] };

test('refusal preview posts only the original cost fields and preserves private evidence and retry identity for approval', async () => {
    const h = globalThis.shipmentHarness = new Harness();
    try {
        await render(ui.Review, reviewProps);
        h.form.data.reverse_fee = '40.00'; h.form.data.cost_reference = 'ACTUAL-COST'; h.form.data.reason = 'Reviewed actual conformity and the original reverse invoice.';
        const proof = h.form.data.proof = { type: 'image/png', size: 4000 }; h.form.data.conforming_shipment_confirmed = true;
        const token = h.form.data.request_token;
        h.control(node => node.type === 'button' && node.props.type === 'button').onClick();
        assert.equal(h.posts[0].url, '/shipment-finances/orders/30/preview');
        assert.deepEqual(h.posts[0].data, { reverse_fee: '40.00', cost_reference: 'ACTUAL-COST' });
        h.form.processing = false; h.posts[0].options.onFinish();
        const html = await render(ui.Review, { ...reviewProps, preview });
        assert.match(html, /Proposed buyer refund/); assert.match(html, /460\.00/); assert.match(html, /Actual reverse shipping/);
        assert.equal(h.form.data.proof, proof); assert.equal(h.form.data.request_token, token);
        h.submit(); h.submit(); assert.equal(h.posts.length, 2);
        assert.equal(h.posts[1].url, '/shipment-finances/orders/30/approve'); assert.equal(h.posts[1].data.review_token, preview.review_token);
        assert.equal(h.posts[1].data.request_token, token);
        h.form.processing = false; h.posts[1].options.onFinish(); h.submit(); assert.equal(h.posts[2].data.request_token, token);
    } finally { delete globalThis.shipmentHarness; }
});

test('changed preview amounts and oversized evidence cannot reach approval while the saved form remains available', async () => {
    const h = globalThis.shipmentHarness = new Harness();
    try {
        await render(ui.Review, reviewProps); h.form.data.reverse_fee = '41.00'; h.form.data.cost_reference = 'ACTUAL-COST';
        const stale = await render(ui.Review, { ...reviewProps, preview }); assert.match(stale, /Amounts changed/); h.submit(); assert.equal(h.posts.length, 0);
        h.form.data.reverse_fee = '40.00'; h.form.data.proof = { type: 'image/png', size: 5242881 };
        await render(ui.Review, { ...reviewProps, preview }); h.submit(); assert.equal(h.posts.length, 0); assert.equal(h.form.data.reverse_fee, '40.00');
        const blocked = await render(ui.Review, { ...reviewProps, source: null, blocker: 'Complete the actual original return first.' });
        assert.match(blocked, /Complete the actual original return first/); assert.doesNotMatch(blocked, /<form/);
    } finally { delete globalThis.shipmentHarness; }
});

test('a refreshed saved preview restores only the reviewed cost fields and asks again for private evidence and consent', async () => {
    const h = globalThis.shipmentHarness = new Harness();
    try {
        await render(ui.Review, { ...reviewProps, preview });
        assert.equal(h.form.data.reverse_fee, '40.00'); assert.equal(h.form.data.cost_reference, 'ACTUAL-COST');
        assert.equal(h.form.data.proof, null); assert.equal(h.form.data.reason, ''); assert.equal(h.form.data.conforming_shipment_confirmed, false);
    } finally { delete globalThis.shipmentHarness; }
});

test('separate Wallet shipping payment excludes cash receipts, refund flags and client entitlement fields', async () => {
    const h = globalThis.shipmentHarness = new Harness();
    try {
        await render(ui.Outcome, { financialPlan: plan }, 'buyer'); h.form.data.action = 'pay-wallet-shipping';
        const html = await render(ui.Outcome, { financialPlan: plan }, 'buyer');
        assert.match(html, /My available virtual credits/); assert.match(html, /Virtual credits to pay/); assert.doesNotMatch(html, /type="file"|Receiving admin/);
        h.form.data.amount = '100.00'; h.form.data.payment_confirmed = true; h.form.data.wallet_refund_confirmed = true; h.form.data.cash_reference = 'STALE';
        h.form.data.reason = 'I approve a separate payment of my own available virtual credits.';
        h.submit(); h.submit(); assert.equal(h.posts.length, 1); const sent = h.posts[0].data;
        assert.equal(sent.payment_confirmed, true); assert.equal(sent.amount, '100.00'); assert.equal(sent.expected_version, 2);
        for (const key of ['wallet_refund_confirmed', 'cash_reference', 'proof', 'recipient_id', 'source_event_id', 'buyer_refund_cents']) assert.equal(key in sent, false);
    } finally { delete globalThis.shipmentHarness; }
});

test('incoming cash receipt selects only the named original offer and posts no buyer-entered amount', async () => {
    const h = globalThis.shipmentHarness = new Harness();
    try {
        const supplied = { financialPlan: { ...plan, mode: 'cod', actions: ['receive-shipping-payment'], wallet_balance_cents: null,
            pending_offers: [{ id: 17, cash_reference: 'ACTUAL-BUYER-017', amount_cents: 9000 }] } };
        await render(ui.Outcome, supplied); h.form.data.action = 'receive-shipping-payment';
        const html = await render(ui.Outcome, supplied); assert.match(html, /ACTUAL-BUYER-017/); assert.doesNotMatch(html, /Actual transfer amount/);
        h.form.data.source_event_id = '17'; h.form.data.payment_confirmed = true; h.form.data.reason = 'I received the named original buyer transfer and checked its actual receipt.';
        h.form.data.proof = { type: 'application/pdf', size: 5242880 }; h.submit();
        assert.equal(h.posts[0].data.source_event_id, '17'); assert.equal('amount' in h.posts[0].data, false); assert.equal('recipient_id' in h.posts[0].data, false);
        assert.equal(h.posts[0].options.forceFormData, true);
    } finally { delete globalThis.shipmentHarness; }
});

test('zero buyer refunds retain the financial journal without presenting a buyer Wallet credit', async () => {
    const html = await render(ui.Outcome, { financialPlan: { ...plan, actions: [], amounts: { ...amounts, reverse_fee_cents: 60000, reverse_fee_withheld_cents: 50000, buyer_refund_cents: 0, buyer_liability_cents: 10000 },
        history: [{ id: 5, event_type: 'zero-refund-assessed', amount_cents: 0, reason: 'Reviewed zero entitlement and retained spent shipping.', created_at: '2026-11-13T00:00:00Z', wallet_reference: 'WLT-ACTUAL-SHIPPING', no_buyer_payment: true }] } }, 'buyer');
    assert.match(html, /Recorded zero buyer refund/); assert.match(html, /Financial journal: WLT-ACTUAL-SHIPPING/); assert.doesNotMatch(html, /Buyer Wallet credited|Refund paid/i);
});

test('undelivered finance queue links to approved outcomes and gives admins the concrete refusal review entry', async () => {
    const page = { data: [{ id: 11, reference: 'REVIEW-ORIGINAL', status: 'shipping_due', order_number: 'BGO-ORIGINAL', mode: 'cod', refund_cents: 0, outstanding_cents: 9000, kind: 'shipment', href: '/shipment-finances/11' }], current_page: 1, last_page: 1, prev_page_url: null, next_page_url: null };
    const html = await render(ui.Queue, { cases: page, filters: { search: '', status: '', view: 'shipments' }, reviewCandidates: { ...page, data: [{ id: 30, order_number: 'BGO-TO-REVIEW' }] } });
    assert.match(html, /href="\/shipment-finances\/11"/); assert.match(html, /href="\/shipment-finances\/orders\/30\/review"/);
    assert.match(html, /Shipping balance due/); assert.match(html, /Undelivered parcels/); assert.match(html, /maxLength="80"/i);
});


test('order financial history keeps its exact order filter across review types', async () => {
    const cases = { data: [], current_page: 1, last_page: 1, prev_page_url: null, next_page_url: null };
    const html = await render(ui.Queue, { cases, filters: { search: '', status: '', view: 'shipments', order_id: 41 } });
    for (const view of ['returns', 'shipments', 'losses']) assert.ok(html.includes(`view=${view}&amp;order_id=41`));
    assert.match(html, /Show all orders/);
});
