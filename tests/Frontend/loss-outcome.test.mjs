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
const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-loss-outcome-'));
after(() => { rmSync(temporary, { recursive: true, force: true }); delete globalThis.route; });
const bundled = await build({ stdin: { contents: "export { default } from '@/Pages/Finance/LossOutcome'; export { default as Queue } from '@/Pages/Finance/LossQueue';", resolveDir: root, loader: 'tsx' },
    bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', write: false, loader: { '.css': 'empty' } });
const artifact = join(temporary, 'loss-outcome.mjs'); writeFileSync(artifact, bundled.outputFiles[0].text);
const ui = await import(pathToFileURL(artifact).href);
const Outcome = ui.default;
globalThis.route = name => name ? '/'+name.replaceAll('.', '/') : { current: () => false, has: () => true };
const loss = { id: 11, reference: 'ORIGINAL-LOSS-REVIEW', order: { id: 30, order_number: 'BGO-ORIGINAL' }, mode: 'cod',
    status: 'awaiting_payment', scope: 'undelivered', company_name: 'Original logistics company', amounts: { 'Buyer refund': 0 },
    review_reason: null, review_proof_url: null, source: null,
    history: [{ id: 1, reference: 'ORIGINAL-APPROVAL', event_type: 'approved', created_at: '2026-11-13T00:00:00Z', amount_cents: 0, reason: null, cash_reference: null, proof_url: null }] };
async function render(value, role = 'buyer', queue = false) {
    let view;
    const response = await createInertiaApp({ page: { component: 'Finance/LossOutcome', props: {
        auth: { user: { id: 7, role, name: 'Original participant', email: role+'@bagoo.test', status: 'active', kyc_status: 'approved' } }, flash: {}, ...(queue ? value : { loss: value }) },
        url: '/shipment-finances/11', version: null }, resolve: () => queue ? ui.Queue : Outcome,
        setup: ({ App, props }) => { view = createElement(App, props); return view; }, render: () => renderToStaticMarkup(view) });
    return response.body;
}

test('approved unpaid cash shows a pending financial review without claiming a refund or physical delivery', async () => {
    const html = await render(loss);
    assert.match(html, /Payment pending/); assert.match(html, /Approval alone does not confirm payment/);
    assert.match(html, /Cash on Delivery/); assert.match(html, /0\.00/);
    assert.doesNotMatch(html, /delivered|refund completed|Independent finding|Private investigation evidence|Company funding outstanding/);
});

test('actual Wallet outcome names virtual funding and retains scoped seller amounts without displaying a buyer payment', async () => {
    const html = await render({ ...loss, mode: 'wallet', status: 'financially_resolved', amounts: { 'Separate seller protection': 18000 },
        history: [{ ...loss.history[0], event_type: 'loss-resolve-wallet', amount_cents: 18000 }] }, 'seller');
    assert.match(html, /Virtual Wallet/); assert.match(html, /Financial outcome recorded/); assert.match(html, /180\.00/);
    assert.doesNotMatch(html, /Buyer refund|Company funding outstanding|Approval alone does not confirm payment|delivered/);
});

test('independent evidence and transfer receipts render as private downloads while saved reasons remain escaped', async () => {
    const html = await render({ ...loss, source: { observation_reference: 'OBS-ORIGINAL', manifest_reference: 'MAN-ORIGINAL', barcode: 'PARCEL-ORIGINAL' },
        review_reason: '<script>private finding</script>', review_proof_url: '/shipment-finances/11/proof',
        history: [{ ...loss.history[0], event_type: 'loss-offer-company', cash_reference: 'ACTUAL-TRANSFER', proof_url: '/shipment-finances/11/events/1/proof' }] }, 'admin');
    assert.match(html, /Independent finding/); assert.match(html, /MAN-ORIGINAL/); assert.match(html, /Private investigation evidence/);
    assert.match(html, /href="\/shipment-finances\/11\/events\/1\/proof"/); assert.match(html, /Company transfer offered/);
    assert.match(html, /&lt;script&gt;private finding&lt;\/script&gt;/); assert.doesNotMatch(html, /<script>private finding/);
});

test('loss queue has a concrete admin missing-source entry and keeps seller protection separate from buyer refunds', async () => {
    const page = { data: [], prev_page_url: null, next_page_url: null, current_page: 1, last_page: 1 };
    const common = { filters: { view: 'losses', search: '', status: '' }, cases: page, reviewCandidates: null };
    const admin = await render({ ...common, reviewCandidates: { ...page, data: [{ id: 7, reference: 'ACTUAL-MISSING', manifest_reference: 'MAN-ORIGINAL', scope: 'buyer_return', href: '/shipment-finances/missing/7/review' }] } }, 'admin', true);
    assert.match(admin, /href="\/shipment-finances\/missing\/7\/review"/); assert.match(admin, /Review actual loss/);
    assert.match(admin, /Buyer return parcel/); assert.match(admin, /view=losses/); assert.match(admin, /aria-current="page"/);
    const seller = await render({ ...common, cases: { ...page, data: [{ id: 11, reference: 'ORIGINAL-LOSS', order_number: 'BGO-ORIGINAL',
        mode: 'wallet', status: 'awaiting_payment', amount_label: 'Separate seller protection', amount_cents: 18000, outstanding_cents: null, href: '/shipment-finances/11' }] } }, 'seller', true);
    assert.match(seller, /Separate seller protection/); assert.match(seller, /180\.00/);
    assert.doesNotMatch(seller, /Buyer refund|Missing observations awaiting loss review|Company funding outstanding/);
});
