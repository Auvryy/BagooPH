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
const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-wallet-'));
after(() => { rmSync(temporary, { recursive: true, force: true }); delete globalThis.walletHarness; delete globalThis.route; });
const probe = /(?:Components\/(?:BuyerPaymentChoice|BuyerWalletPanel|Returns\/ReturnMoneyPanel)|Pages\/(?:Buyer\/WalletPayment|Admin\/WalletCredits|Finance\/WalletProceeds))\.tsx$/;
const result = await build({ stdin: { contents: `export { default as Choice } from '@/Components/BuyerPaymentChoice'; export { default as Wallet } from '@/Components/BuyerWalletPanel'; export { default as Payment } from '@/Pages/Buyer/WalletPayment'; export { default as Credits } from '@/Pages/Admin/WalletCredits'; export { default as Proceeds } from '@/Pages/Finance/WalletProceeds'; export { default as ReturnMoney } from '@/Components/Returns/ReturnMoneyPanel'; export * from '@/utils/wallet';`, resolveDir: root, loader: 'tsx' },
    bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', write: false, loader: { '.css': 'empty' }, plugins: [{ name: 'wallet-controls', setup(builder) {
        builder.onResolve({ filter: /^@inertiajs\/react$/ }, args => probe.test(args.importer) ? { path: 'inertia', namespace: 'wallet-probe' } : undefined);
        builder.onLoad({ filter: /^inertia$/, namespace: 'wallet-probe' }, () => ({ contents: `export * from '@inertiajs/react'; import { useForm as real } from '@inertiajs/react'; export const useForm = initial => globalThis.walletHarness ? globalThis.walletHarness.useForm(initial) : real(initial);`, resolveDir: root }));
        builder.onResolve({ filter: /^react$/ }, args => probe.test(args.importer) ? { path: 'react', namespace: 'wallet-probe' } : undefined);
        builder.onLoad({ filter: /^react$/, namespace: 'wallet-probe' }, () => ({ contents: `export * from 'react'; import React from 'react'; export const useRef = initial => globalThis.walletHarness ? globalThis.walletHarness.ref(initial) : React.useRef(initial); export const useState = initial => globalThis.walletHarness ? globalThis.walletHarness.state(initial) : React.useState(initial);`, resolveDir: root }));
        builder.onResolve({ filter: /^react\/jsx-runtime$/ }, args => probe.test(args.importer) ? { path: 'jsx', namespace: 'wallet-probe' } : undefined);
        builder.onLoad({ filter: /^jsx$/, namespace: 'wallet-probe' }, () => ({ contents: `import { jsx as real, jsxs as many, Fragment } from 'react/jsx-runtime'; export { Fragment }; const track=(fn,type,props,key)=>{ if(globalThis.walletHarness && (props.onSubmit || props.onChange || props.onClick)) globalThis.walletHarness.handlers.push({type,props}); return fn(type,props,key); }; export const jsx=(...args)=>track(real,...args); export const jsxs=(...args)=>track(many,...args);`, resolveDir: root }));
    } }] });
const output = join(temporary, 'wallet.mjs'); writeFileSync(output, result.outputFiles[0].text); const ui = await import(pathToFileURL(output).href);
globalThis.route = name => name ? `/${name.replaceAll('.', '/')}` : { current: () => false, has: () => true };
const buyer = { id: 7, role: 'buyer', name: 'Ana Santos', email: 'buyer@bagoo.test', status: 'active', kyc_status: 'approved' };
const empty = { data: [], current_page: 1, last_page: 1, links: [] };
const wallet = { available: true, balance_cents: 0, can_request: true, request_token: 'original-request', transactions: empty, credit_requests: [] };
const payment = { id: 15, reference: 'WPA-ORIGINAL', status: 'pending', amount_cents: 15025, order_id: 4, order_number: 'BGO-ORIGINAL', balance_cents: 20000,
    expires_at: new Date(Date.now() + 3600000).toISOString(), paid_at: null, can_pay: true, payment_reference: null, request_token: 'original-payment',
    items: [{ id: 1, name: 'Everyday bag', quantity: 1, unit_price: '100.25' }], last_attempt: null };
const credit = { id: 8, reference: 'WCR-ORIGINAL', amount_cents: 100000, reason: 'Demo credits for one purchase.', status: 'pending', buyer, created_at: '2026-10-19T12:00:00Z' };
const proceeds = { order_id: 4, order_number: 'BGO-ORIGINAL', shop_name: 'Bagoo shop', status: 'eligible', can_settle: true, can_refund_delivery: false,
    blockers: [], consent: 'original-consent', seller_cents: 9000, commission_cents: 1000, shipping_cents: 5000, promotion_cents: 0, refunded_cents: 0, settlement_reference: null };

async function render(Component, supplied, role = 'buyer') {
    const h = globalThis.walletHarness;
    if (h) { h.refCursor = 0; h.stateCursor = 0; h.handlers = []; }
    let view; const response = await createInertiaApp({ page: { component: 'Wallet/Test', props: { auth: { user: { ...buyer, role } }, flash: {}, cartCount: 0, ...supplied }, url: '/wallet', version: null },
        resolve: () => Component, setup: ({ App, props }) => { view = createElement(App, props); return view; }, render: () => renderToStaticMarkup(view) }); return response.body;
}
class Harness {
    refs = []; states = []; refCursor = 0; stateCursor = 0; handlers = []; posts = []; form;
    ref(initial) { return this.refs[this.refCursor++] ??= { current: initial }; }
    state(initial) { const i = this.stateCursor++; if (!(i in this.states)) this.states[i] = typeof initial === 'function' ? initial() : initial; return [this.states[i], value => { this.states[i] = typeof value === 'function' ? value(this.states[i]) : value; }]; }
    useForm(initial) { return this.form ??= { data: structuredClone(initial), errors: {}, processing: false,
        setData: (key, value) => { if (typeof key === 'object') this.form.data = key; else this.form.data[key] = value; },
        setError: (key, value) => { this.form.errors[key] = value; }, clearErrors: (...keys) => { if (!keys.length) this.form.errors = {}; else keys.forEach(key => delete this.form.errors[key]); },
        reset: (...keys) => { if (!keys.length) this.form.data = structuredClone(initial); else keys.forEach(key => this.form.data[key] = initial[key]); },
        transform: callback => { this.transform = callback; },
        post: (url, options) => { this.posts.push({ url, options, data: structuredClone(this.transform ? this.transform(this.form.data) : this.form.data) }); this.form.processing = true; } }; }
    control(predicate) { const node = this.handlers.find(predicate); assert.ok(node, 'expected actual rendered control'); return node.props; }
    submit() { this.control(node => node.type === 'form').onSubmit({ preventDefault() {} }); }
    click(text) { this.control(node => node.type === 'button' && node.props.children === text).onClick(); }
    finish() { this.form.processing = false; this.posts.at(-1).options.onFinish(); }
}
async function withHarness(run) { const h = globalThis.walletHarness = new Harness(); try { await run(h); } finally { delete globalThis.walletHarness; } }

test('credit input keeps exact centavos and rejects malformed, nonpositive and excessive amounts', () => {
    for (const value of ['1', '1.00', '1.01', '99999.99', '100000.00']) assert.equal(ui.creditAmountError(value), null, value);
    for (const value of ['', '0', '0.99', '100000.01', '1.001', '1e3', '-1', '01', ' 1', '1,000', '١٠']) assert.ok(ui.creditAmountError(value), value);
    assert.match(ui.walletMoney(15025), /150\.25/);
});

test('checkout retains COD selection, explains wallet confirmation and blocks unavailable credit sources', async () => {
    let chosen;
    await withHarness(async h => {
        const html = await render(ui.Choice, { value: 'cod', balanceCents: 10000, onChange: value => chosen = value });
        assert.equal(h.control(node => node.props.value === 'cod').checked, true); assert.match(html, /Cash on delivery/); assert.match(html, /one hour/); assert.match(html, /virtual/);
        h.control(node => node.props.value === 'wallet').onChange(); assert.equal(chosen, 'wallet');
        await render(ui.Choice, { value: 'cod', balanceCents: null, onChange() {} });
        assert.equal(h.control(node => node.props.value === 'wallet').disabled, true);
        assert.equal(h.control(node => node.props.value === 'cod').disabled, false);
    });
});

test('an empty wallet shows genuine zero credits, a centered history and no fabricated account number', async () => {
    const html = await render(ui.Wallet, { wallet });
    assert.match(html, /₱0\.00/); assert.match(html, /Admin-approved demo credits/); assert.match(html, /cannot be withdrawn or transferred/);
    assert.match(html, /min-h-40 flex-col items-center justify-center/); assert.match(html, /No wallet transactions yet/);
    assert.doesNotMatch(html, /account number|cash deposit|Add money instantly|WLT-000/i);
    const broken = await render(ui.Wallet, { wallet: { ...wallet, available: false, balance_cents: null, can_request: false } });
    assert.match(broken, /Unavailable/); assert.doesNotMatch(broken, /₱0\.00/);
});

test('credit requests validate before posting, prevent duplicates and retain their token for an uncertain retry', async () => withHarness(async h => {
    await render(ui.Wallet, { wallet });
    h.form.data.amount = '0.99'; h.form.data.reason = 'Demo credits for purchases.'; h.submit(); assert.equal(h.posts.length, 0);
    h.form.data.amount = '100.25'; h.form.data.reason = ' '; h.submit(); assert.equal(h.posts.length, 0);
    h.form.data.reason = 'Demo credits for purchases.'; h.submit(); h.submit(); assert.equal(h.posts.length, 1);
    assert.equal(h.posts[0].url, '/wallet/credits'); assert.deepEqual(Object.keys(h.posts[0].data).sort(), ['amount', 'reason', 'request_token']);
    h.finish(); h.submit(); assert.equal(h.posts[1].data.request_token, 'original-request');
}));

test('a pending credit request prevents another request and shows the actual admin feedback', async () => withHarness(async h => {
    const html = await render(ui.Wallet, { wallet: { ...wallet, credit_requests: [credit, { ...credit, id: 9, status: 'rejected', review_reason: 'Please explain which demonstration purchase you need.' }] } });
    assert.match(html, /awaiting admin review/); assert.match(html, /Please explain which demonstration purchase/);
    h.submit(); assert.equal(h.posts.length, 0);
}));

test('wallet payment requires explicit consent, posts no client price and prevents a duplicate click', async () => withHarness(async h => {
    const html = await render(ui.Payment, { payment, canUsePortal: true });
    assert.match(html, /150\.25/); assert.match(html, /no cash collection/); assert.match(html, /text-\[#E00D42\]/);
    h.submit(); assert.match(h.form.errors.payment_confirmed, /exact wallet amount/); assert.equal(h.posts.length, 0);
    h.control(node => node.props.type === 'checkbox').onChange({ target: { checked: true } }); await render(ui.Payment, { payment });
    h.submit(); h.submit(); assert.equal(h.posts.length, 1); assert.equal(h.posts[0].url, '/wallet/payments/15/pay');
    assert.deepEqual(h.posts[0].data, { payment_confirmed: true, request_token: 'original-payment' });
}));

test('failed payment offers Pay again while a saved payment or expired deadline cannot post another charge', async () => {
    const failed = await render(ui.Payment, { payment: { ...payment, last_attempt: { outcome: 'failed', message: 'Not enough credits. Request credits first.' } } });
    assert.match(failed, /Pay again/); assert.match(failed, /Not enough credits/);
    await withHarness(async h => {
        const paid = await render(ui.Payment, { payment: { ...payment, status: 'paid', can_pay: false, paid_at: '2026-10-19T13:00:00Z', payment_reference: 'WLT-SAVED' } });
        assert.match(paid, /Wallet payment recorded/); assert.match(paid, /WLT-SAVED/); assert.match(paid, /does not deduct more credits/);
        assert.equal(h.handlers.filter(node => node.type === 'form').length, 0);
    });
    const expired = await render(ui.Payment, { payment: { ...payment, can_pay: false, expires_at: new Date(Date.now() - 1000).toISOString() } });
    assert.match(expired, /deadline has passed/); assert.doesNotMatch(expired, />Confirm payment</);
});

test('cancelling confirmation uses its separate command and leaves the debit for explicit payment', async () => withHarness(async h => {
    await render(ui.Payment, { payment }); h.click('Cancel payment');
    assert.equal(h.posts[0].url, '/wallet/payments/15/cancel'); assert.equal(h.posts[0].data.payment_confirmed, false);
}));

test('admin credit review preserves the original amount and requires meaningful feedback plus demo acknowledgement', async () => withHarness(async h => {
    await render(ui.Credits, { creditRequests: { ...empty, data: [credit] }, currentStatus: 'pending', pendingCount: 1, requestToken: 'review-original' }, 'admin');
    h.click('Review request'); const html = await render(ui.Credits, { creditRequests: { ...empty, data: [credit] }, currentStatus: 'pending', pendingCount: 1, requestToken: 'review-original' }, 'admin');
    assert.match(html, /Review ₱1,000\.00 for Ana Santos/); assert.doesNotMatch(html, /name="amount"|cash deposit confirmed/);
    h.form.data.reason = 'Approved demo credits for an original purchase.'; h.submit(); assert.equal(h.posts.length, 0);
    h.form.data.demo_credit_confirmed = true; h.submit(); h.submit(); assert.equal(h.posts.length, 1);
    assert.equal(h.posts[0].url, '/wallet/credits/8'); assert.equal(h.posts[0].data.amount, undefined); assert.equal(h.posts[0].data.buyer_id, undefined);
}));

test('virtual proceeds are read-only for sellers and use separate admin consent without cash payment proof', async () => {
    const seller = await render(ui.Proceeds, { records: { ...empty, data: [proceeds] }, canSettle: false, virtualBalanceCents: 9000, requestToken: 'proceeds-request' }, 'seller');
    assert.match(seller, /no cash withdrawal or spending feature/); assert.doesNotMatch(seller, /Review virtual proceeds|Review returned-parcel refund/);
    await withHarness(async h => {
        const props = { records: { ...empty, data: [proceeds] }, canSettle: true, virtualBalanceCents: null, requestToken: 'proceeds-request' };
        await render(ui.Proceeds, props, 'admin'); h.click('Review virtual proceeds'); await render(ui.Proceeds, props, 'admin');
        h.form.data.reason = 'Reviewed buyer receipt and original seller proceeds.'; h.submit(); assert.equal(h.posts.length, 0);
        h.form.data.credit_proceeds_confirmed = true; h.submit(); h.submit(); assert.equal(h.posts.length, 1);
        assert.equal(h.posts[0].url, '/wallet/proceeds/4'); assert.equal(h.posts[0].data.consent, 'original-consent');
        assert.equal(h.posts[0].data.amount, undefined); assert.equal(h.posts[0].data.wallet_refund_confirmed, undefined);
    });
});

test('returned-parcel refund retains its original payment reference and submits no editable amount', async () => withHarness(async h => {
    const props = { records: { ...empty, data: [{ ...proceeds, can_settle: false, can_refund_delivery: true, payment_cents: 15025, payment_reference: 'WPA-ORIGINAL' }] }, canSettle: true, virtualBalanceCents: null, requestToken: 'returned-request' };
    await render(ui.Proceeds, props, 'admin'); h.click('Review returned-parcel refund'); const html = await render(ui.Proceeds, props, 'admin');
    assert.match(html, /Refund ₱150\.25 to the original buyer wallet/);
    h.form.data.reason = 'Reviewed the actual full seller return and original payment.'; h.submit();
    assert.equal(h.posts.length, 0); assert.match(h.form.errors.wallet_refund_confirmed, /original buyer wallet/);
    h.form.data.wallet_refund_confirmed = true; h.submit();
    assert.equal(h.posts[0].url, '/wallet/proceeds/4/refund-returned'); assert.equal(h.posts[0].data.payment_reference, 'WPA-ORIGINAL');
    assert.equal(h.posts[0].data.amount, undefined); assert.equal(h.posts[0].data.credit_proceeds_confirmed, undefined);
}));

test('wallet merchandise refunds show the saved credit reference without cash proof or transfer controls', async () => withHarness(async h => {
    const value = { mode: 'wallet', adjustment: { gross_cents: 10000, commission_cents: 1000, shop_discount_cents: 0, platform_discount_cents: 0, buyer_cents: 10000, seller_cents: 9000, platform_cents: 1000 }, actions: ['refund-wallet'], history: [] };
    await render(ui.ReturnMoney, { value, caseId: 8, version: 3 }, 'admin'); h.form.data.action = 'refund-wallet';
    const html = await render(ui.ReturnMoney, { value, caseId: 8, version: 3 }, 'admin');
    assert.match(html, /Wallet return refund/); assert.match(html, /original Bagoo Wallet once/); assert.doesNotMatch(html, /type="file"|Actual transfer reference|Actual repayment backing/);
    h.form.data.reason = 'Reviewed the returned units and original exact credits.'; h.form.data.wallet_refund_confirmed = true; h.submit(); h.submit();
    assert.equal(h.posts.length, 1); assert.equal(h.posts[0].data.action, 'refund-wallet'); assert.equal(h.posts[0].data.wallet_refund_confirmed, true);
    assert.equal(h.posts[0].data.cash_reference, undefined); assert.equal(h.posts[0].data.proof, undefined);
}));
