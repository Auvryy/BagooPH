import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { buildSync } from 'esbuild';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createInertiaApp } from '@inertiajs/react';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-finance-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const bundle = buildSync({
    stdin: { contents: `export * from '@/utils/finance'; export { default as Overview } from '@/Pages/Finance/Overview'; export { default as Evidence } from '@/Pages/Finance/Evidence'; export { default as SettlementDetail } from '@/Pages/Finance/SettlementDetail'; export { default as SellerVouchers } from '@/Pages/Seller/Vouchers';`, resolveDir: resolve(import.meta.dirname, '../..'), loader: 'tsx' },
    bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', loader: { '.css': 'empty' }, write: false,
});
const path = join(temporary, 'finance.mjs');
writeFileSync(path, bundle.outputFiles[0].text);
const { financeMoney, financeDate, Overview, Evidence, SettlementDetail, SellerVouchers } = await import(pathToFileURL(path).href);
globalThis.route = (name) => name ? `/${name.replaceAll('.', '/')}` : { current: () => false, has: () => true };
after(() => delete globalThis.route);
async function render(Component, name, props) {
    let view;
    const result = await createInertiaApp({
        page: { component: name, props: { auth: { user: { id: 1, name: 'Ana Santos', role: 'admin' } }, flash: {}, errors: {}, ...props }, url: '/financial-oversight', version: null },
        resolve: () => Component, setup: ({ App, props }) => { view = createElement(App, props); return view; }, render: () => renderToStaticMarkup(view),
    });
    return result.body;
}

test('finance formats exact cent strings beyond JavaScript integer precision', () => {
    assert.equal(financeMoney('900719925474099301'), '₱9,007,199,254,740,993.01');
    assert.equal(financeMoney('-900719925474099301'), '-₱9,007,199,254,740,993.01');
});
test('zero and individual cent amounts retain two decimal places', () => {
    assert.equal(financeMoney('0'), '₱0.00');
    assert.equal(financeMoney(5), '₱0.05');
    assert.equal(financeMoney(-5), '-₱0.05');
    assert.equal(financeMoney('10005'), '₱100.05');
});
test('missing malformed and unsafe money never appears as a genuine zero', () => {
    for (const value of [null, undefined, '', '1.10', 'NaN', 1.5, Number.MAX_SAFE_INTEGER + 1, Infinity]) {
        assert.equal(financeMoney(value), 'Unavailable');
    }
});
test('financial dates use Philippine time and reject invalid dates', () => {
    assert.match(financeDate('2026-10-08T16:00:00Z'), /Oct 9, 2026.*12:00 AM/);
    assert.equal(financeDate('invalid'), 'Date unavailable');
});
test('a source error remains unavailable without a misleading empty or zero balance', async () => {
    const html = await render(Overview, 'Finance/Overview', {
        records: { data: [], total: 0 }, totals: [{ key: 'settled', label: 'Seller proceeds paid', amount_cents: null, source_count: 0, definition: 'Recorded payment evidence.', url: '/financial-oversight?metric=settled' }],
        error: 'Financial sources could not be verified.', filters: {}, mode: 'admin', stateOptions: [], companies: [], recipients: [],
    });
    assert.match(html, /role="alert"/);
    assert.match(html, /Unavailable/);
    assert.doesNotMatch(html, /No records match|₱0\.00|Review 0 source/);
});
test('a true zero links to its source filter and retains Philippine currency', async () => {
    const html = await render(Overview, 'Finance/Overview', {
        records: { data: [], total: 0, current_page: 1, last_page: 1 }, totals: [{ key: 'settled', label: 'Seller proceeds paid', amount_cents: '0', source_count: 0, definition: 'Recorded payment evidence.', url: '/financial-oversight?metric=settled' }],
        error: null, filters: {}, mode: 'seller', stateOptions: [], companies: [], recipients: [],
    });
    assert.match(html, /₱0\.00/);
    assert.match(html, /href="\/financial-oversight\?metric=settled"/);
    assert.match(html, /No records match these filters/);
});
test('evidence keeps original and corrected private receipts visible without mutation controls', async () => {
    const base = { reference: 'PAYMENT-ORIGINAL', event_type: 'payment_recorded', amount_cents: 9004, actor_name: 'Ana Santos', actor_role: 'admin', reason: 'The actual seller payment was checked.', created_at: '2026-10-09T00:00:00Z', payment_reference: 'RECEIPT-ORIGINAL', proof_url: '/seller-settlements/7/proof/1', context: { previous_reference: null } };
    const html = await render(Evidence, 'Finance/Evidence', { mode: 'admin', record: {
        order_number: 'BGO-ORIGINAL', recorded_at: '2026-10-09T00:00:00Z', date_basis: 'Original cash collection', cash: null,
        proceeds: { seller_name: 'Maria Santos', shop_name: 'Bagoo Crafts', status: 'settled', product_cents: 10005, seller_cents: 9004, commission_cents: 1001, shipping_cents: 500, discount_cents: 100, blockers: [], history: [base,
            { ...base, reference: 'PAYMENT-CORRECTION', event_type: 'payment_reference_corrected', amount_cents: 0, source_reference: base.reference, payment_reference: 'RECEIPT-CORRECTED', proof_url: '/seller-settlements/7/proof/2', context: { previous_reference: base.payment_reference } }] },
    } });
    assert.match(html, /RECEIPT-ORIGINAL/);
    assert.match(html, /RECEIPT-CORRECTED/);
    assert.match(html, /href="#PAYMENT-ORIGINAL"/);
    assert.match(html, /href="\/seller-settlements\/7\/proof\/1"/);
    assert.match(html, /href="\/seller-settlements\/7\/proof\/2"/);
    assert.doesNotMatch(html, /<form|<input|<textarea|Record payment|Authorize release/);
});

test('funded settlement distinguishes gross shares, issuer costs and the net payment amount', async () => {
    const record = {
        order_id: 7, order_number: 'BGO-FUNDED', seller_name: 'Maria Santos', shop_name: 'Bagoo Crafts', status: 'authorized',
        version: 1, product_cents: 100000, seller_cents: 90000, commission_cents: 10000, shipping_cents: 5000, discount_cents: 15000,
        seller_promotion_cents: 10000, platform_promotion_cents: 5000, net_seller_cents: 80000, net_commission_cents: 5000,
        promotion_accounting: 'verified', promotion_snapshot: [], blockers: [], history: [],
    };
    const html = await render(SettlementDetail, 'Finance/SettlementDetail', { can_release: true, requestToken: 'test-token', record });
    assert.match(html, /Seller product share[\s\S]*?₱900\.00/);
    assert.match(html, /Net seller payout[\s\S]*?₱800\.00/);
    assert.match(html, /Bagoo promotional cost[\s\S]*?₱50\.00/);
    assert.match(html, /Confirm the existing ₱800\.00 payment/);
    assert.doesNotMatch(html, /Confirm the existing ₱900\.00 payment/);
    const evidence = await render(Evidence, 'Finance/Evidence', { mode: 'admin', record: {
        order_number: 'BGO-FUNDED', recorded_at: '2026-10-11T00:00:00Z', date_basis: 'Original cash collection',
        proceeds: record, cash: null,
    } });
    assert.match(evidence, /Seller share \(90%\)[\s\S]*?₱900\.00/);
    assert.match(evidence, /Net seller payout[\s\S]*?₱800\.00/);
    assert.doesNotMatch(evidence, /Record an existing seller payment|<form/);
});

test('seller voucher page makes draft and archived status clear without an invented shipping campaign', async () => {
    const base = { code: 'SHOP_DRAFT', name: 'Shop draft', discount_type: 'fixed', discount_value: 100,
        min_spend: 0, used_count: 0, is_active: false, current_revision_id: null, archived_at: null };
    const html = await render(SellerVouchers, 'Seller/Vouchers', { shop: { id: 1 }, vouchers: { data: [
        { ...base, id: 1 }, { ...base, id: 2, code: 'OLDER_SHOP', used_count: 7, archived_at: '2026-10-11T00:00:00Z' },
    ] } });
    assert.match(html, /Save Draft/);
    assert.match(html, /New vouchers are drafts/);
    assert.match(html, /disabled=""[^>]*>Draft/);
    assert.match(html, /disabled=""[^>]*>Archived/);
    assert.match(html, /7 Claims/);
    assert.doesNotMatch(html, /FREESHIP|Network standard active|Publish Promotion/);
});
