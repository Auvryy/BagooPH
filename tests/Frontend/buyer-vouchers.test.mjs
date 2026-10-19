import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createInertiaApp } from '@inertiajs/react';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-buyer-vouchers-'));
after(() => {
    rmSync(temporary, { recursive: true, force: true });
    delete globalThis.route;
    delete globalThis.voucherQuoteState;
    delete globalThis.voucherQuoteInput;
    delete globalThis.voucherHookHarness;
});
const root = resolve(import.meta.dirname, '../..');
const pages = await build({
    stdin: { contents: `export { default as Checkout } from '@/Pages/Checkout/Index';
        export { default as Bag } from '@/Pages/Cart/Index';
        export { default as Offers } from '@/Components/VoucherOfferList';
        export { default as Collection } from '@/Components/Vouchers/BuyerVoucherCollection';
        export { VoucherChoiceCards as Choices } from '@/Components/Vouchers/BuyerVoucherPicker';
        export { default as Receipt } from '@/Components/Vouchers/BuyerVoucherReceipt';
        export { chooseVoucher, withoutVoucher, repairedVoucherSelection, isVoucherRequestCurrent } from '@/utils/voucherSelection';`, resolveDir: root, loader: 'tsx' },
    bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', write: false,
    loader: { '.css': 'empty' },
    plugins: [{ name: 'quote-state-for-page-checks', setup(builder) {
        builder.onResolve({ filter: /hooks\/usePurchaseQuote$/ }, args => args.importer.includes('/Pages/')
            ? { path: args.path, namespace: 'quote-state' } : undefined);
        builder.onLoad({ filter: /.*/, namespace: 'quote-state' }, () => ({ contents: `
            export default function usePurchaseQuote(input, quote, offers = []) {
                globalThis.voucherQuoteInput = input;
                return globalThis.voucherQuoteState ?? { quote, offers, current: true, pending: false, failure: '', ready: true };
            }`, loader: 'js' }));
    } }],
});
const pagesPath = join(temporary, 'pages.mjs');
writeFileSync(pagesPath, pages.outputFiles[0].text);
const ui = await import(pathToFileURL(pagesPath).href);
globalThis.route = name => name ? `/${name.replaceAll('.', '/')}` : { current: () => false, has: () => true };

const buyer = { id: 7, name: 'Maria Santos', email: 'buyer@bagoo.test', role: 'buyer', status: 'active', kyc_status: 'approved' };
const offer = { id: 3, revision_id: 3, code: 'BAGOO_SHIP', name: 'Bagoo shipping offer', description: null,
    discount_type: 'free_shipping', discount_value: '75.00', min_spend: '100.00', max_discount: '30.00',
    shop_id: null, shop_name: null, issuer: 'platform', expires_at: null, eligible: true, saving: 30 };
const quote = { subtotal: 2000, shipping_fee: 100, doorstep_shipping_fee: 100, shipping_discount: 30,
    discount: 30, total: 2070, voucher: offer, voucher_error: null, redemptions: [{ revision_id: 3, discount_cents: 3000, allocations: [{ shop_id: 1, product_discount_cents: 0, shipping_discount_cents: 1500 }, { shop_id: 2, product_discount_cents: 0, shipping_discount_cents: 1500 }] }], items: [{ id: 10, quantity: 1, unit_price: 1000, subtotal: 1000 }],
    shops: [{ shop_id: 1, shop_name: 'Bagoo shop one', subtotal: 1000, shipping: 50, doorstep_shipping: 50, discount: 15, shipping_discount: 15, total: 1035 },
        { shop_id: 2, shop_name: 'Bagoo shop two', subtotal: 1000, shipping: 50, doorstep_shipping: 50, discount: 15, shipping_discount: 15, total: 1035 }] };
const items = [{ id: 10, quantity: 1, unit_price: 1000, available_for_purchase: true, updated_at: '2026-10-11T01:00:00Z',
    product: { id: 1, name: 'Everyday bag', price: 1000, stock: 10, images: [], shop: { id: 1, name: 'Bagoo shop one' } } }];
async function render(Component, name, props = {}) {
    let view;
    const result = await createInertiaApp({
        page: { component: name, props: { auth: { user: buyer }, flash: {}, cartCount: 1, ...props }, url: '/buyer/checkout', version: null },
        resolve: () => Component,
        setup: ({ App, props }) => { view = createElement(App, props); return view; },
        render: () => renderToStaticMarkup(view),
    });
    return result.body;
}
const checkoutProps = { cart: { id: 1 }, items, subtotal: 1, shippingFee: 0, total: 1, quote, user: buyer,
    availableVouchers: [offer], kycStatus: 'approved', checkoutToken: 'test-confirmation',
    addresses: [{ id: 2, recipient_name: buyer.name, phone: '09171234567', street: '123 Mabini Street',
        city: 'Pasig', province: 'Metro Manila', barangay: 'San Antonio', postal_code: '1600', is_default: true }] };

// Run the request hook with a small in-memory hook harness; no DOM or browser is used.
const hookBundle = await build({
    entryPoints: [resolve(root, 'resources/js/hooks/usePurchaseQuote.ts')], bundle: true,
    platform: 'node', format: 'esm', packages: 'external', write: false,
    plugins: [{ name: 'isolated-hook-effects', setup(builder) {
        builder.onResolve({ filter: /^react$/ }, () => ({ path: 'react', namespace: 'hook-harness' }));
        builder.onLoad({ filter: /.*/, namespace: 'hook-harness' }, () => ({ contents: `
            export const useState = value => globalThis.voucherHookHarness.state(value);
            export const useEffect = (effect, deps) => globalThis.voucherHookHarness.effect(effect, deps);
        ` }));
        builder.onResolve({ filter: /^axios$/ }, () => ({ path: 'axios', namespace: 'hook-http' }));
        builder.onLoad({ filter: /.*/, namespace: 'hook-http' }, () => ({ contents: `
            export default { get: (...args) => globalThis.voucherHookHarness.request('get', args),
                post: (...args) => globalThis.voucherHookHarness.request('post', args) };
        ` }));
    } }],
});
const hookPath = join(temporary, 'hook.mjs');
writeFileSync(hookPath, hookBundle.outputFiles[0].text);
const { default: useQuote, emptyPurchaseQuote } = await import(pathToFileURL(hookPath).href);
function harness() {
    const states = [], effects = [], timers = new Map(), requests = [];
    let cursor = 0, timerId = 0;
    const previousWindow = globalThis.window;
    globalThis.window = { setTimeout(fn) { const id = ++timerId; timers.set(id, fn); return id; }, clearTimeout(id) { timers.delete(id); } };
    const h = {
        state(initial) {
            const index = cursor++;
            if (!(index in states)) states[index] = initial;
            return [states[index], value => { states[index] = typeof value === 'function' ? value(states[index]) : value; }];
        },
        effect(fn, deps) {
            const index = cursor++;
            const old = effects[index];
            if (!old || JSON.stringify(old.deps) !== JSON.stringify(deps)) {
                old?.cleanup?.(); effects[index] = { deps, fn, needsRun: true };
            }
        },
        request(method, args) {
            let resolve, reject;
            const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
            requests.push({ method, args, resolve, reject });
            return promise;
        },
        render(input) {
            cursor = 0;
            const output = useQuote(input, emptyPurchaseQuote);
            for (const effect of effects) if (effect?.needsRun) { effect.needsRun = false; effect.cleanup = effect.fn(); }
            return output;
        },
        flush() { for (const [id, fn] of timers) { timers.delete(id); fn(); } },
        requests,
        close() { for (const effect of effects) effect?.cleanup?.(); globalThis.window = previousWindow; delete globalThis.voucherHookHarness; },
    };
    globalThis.voucherHookHarness = h;
    return h;
}
const settle = () => new Promise(resolve => setImmediate(resolve));
const input = { item_ids: [10], delivery_type: 'doorstep', code: '', revision: 'quantity-one' };


test('checkout uses quoted gross shipping, one discount and actual savings without repeating the deduction', async () => {
    const html = await render(ui.Checkout, 'Checkout/Index', { ...checkoutProps, items: [{ ...items[0], unit_price: 1,
        product: { ...items[0].product, price: 900 } }] });
    const summary = html.slice(html.indexOf('Order Total Breakdown'));
    assert.match(summary, /Shipping before discounts[\s\S]*?₱100\.00/);
    assert.match(summary, /Bagoo shipping discount[\s\S]*?−₱30\.00/);
    assert.match(summary, /Total Payment:[\s\S]*?₱2,070\.00/);
    assert.match(summary, /Shipping payable[\s\S]*?₱70\.00/);
    assert.doesNotMatch(html, /Free Shipping Applied|₱75 Discount Applied/);
    assert.match(html, /Bagoo voucher/);
    assert.match(html, /Use best vouchers/);
    assert.match(html, /Bagoo shop one[\s\S]*?₱1,035\.00/);
    const selectedItems = html.slice(html.indexOf('Items In Your Shopping Bag'), html.indexOf('Logistics &amp; Delivery Carrier'));
    assert.match(selectedItems, /1 × ₱1,000\.00/);
    assert.doesNotMatch(selectedItems, /₱1\.00|₱900\.00/);
    assert.equal(globalThis.voucherQuoteInput.shipping_province, 'Metro Manila');
    assert.equal(globalThis.voucherQuoteInput.shipping_city, 'Pasig');
    assert.deepEqual(globalThis.voucherQuoteInput.item_ids, [10]);
});

test('checkout and Bag block continuation and show clear feedback while totals are stale or unavailable', async () => {
    for (const state of [{ pending: true, failure: '' }, { pending: false, failure: 'Could not update your total.' }]) {
        globalThis.voucherQuoteState = { ...state, quote, offers: [offer], current: false, ready: false };
        const checkout = await render(ui.Checkout, 'Checkout/Index', checkoutProps);
        assert.match(checkout, /<button[^>]*type="submit"[^>]*disabled=""/);
        assert.ok(checkout.includes(state.pending ? 'Updating…' : 'Unavailable'));
        const bag = await render(ui.Bag, 'Cart/Index', { cart: { id: 1 }, items, quote, total: 1 });
        assert.doesNotMatch(bag, /href="\/checkout\/index/);
        assert.match(bag, state.pending ? /Updating total…/ : /Total unavailable/);
    }
    delete globalThis.voucherQuoteState;
});

test('available offer controls show real terms, explain ineligibility and never promise full capped shipping', () => {
    const html = renderToStaticMarkup(createElement(ui.Offers, { offers: [offer,
        { ...offer, id: 4, code: 'BAGOO_PERCENT', discount_type: 'percent', discount_value: 25, max_discount: 50,
            eligible: false, unavailable_reason: 'Add ₱100.00 from this shop to use this voucher.' }], onSelect: () => {} }));
    assert.match(html, /Shipping discount up to ₱30\.00/);
    assert.match(html, /Save ₱30\.00/);
    assert.match(html, /25% off/);
    assert.match(html, /Maximum discount ₱50\.00/);
    assert.match(html, /disabled=""[\s\S]*?>Unavailable</);
    assert.match(html, /Add ₱100\.00 from this shop/);
    assert.doesNotMatch(html, /100% OFF SHIPPING|Claimed|Saved voucher/);
});

test('quote refresh cancels a previous selection and ignores a late response', async () => {
    const h = harness();
    try {
        assert.equal(h.render(input).ready, false); h.flush();
        const next = { ...input, item_ids: [11], revision: 'quantity-two' };
        assert.equal(h.render(next).pending, true); h.flush();
        assert.equal(h.requests[0].args[2].signal.aborted, true);
        h.requests[1].resolve({ data: { quote: { ...quote, voucher: null, discount: 0, shipping_discount: 0, total: 2100 } } });
        await settle();
        assert.equal(h.render(next).quote.total, 2100);
        assert.equal(h.render(next).ready, true);
        h.requests[0].resolve({ data: { quote } }); await settle();
        assert.equal(h.render(next).quote.total, 2100);
        assert.equal(h.render(next).current, true);
        assert.equal(h.requests[1].args[1].revision, undefined);
    } finally { h.close(); }
});

test('Buy Now keeps the requested quantity in checkout and every voucher quote request', async () => {
    await render(ui.Checkout, 'Checkout/Index', { ...checkoutProps, itemQuantities: { 10: 2 } });
    assert.deepEqual(globalThis.voucherQuoteInput.item_quantities, { 10: 2 });
    const h = harness();
    try {
        const selected = { ...input, item_quantities: { 10: 2 } };
        h.render(selected); h.flush();
        assert.deepEqual(h.requests[0].args[1].item_quantities, { 10: 2 });
        h.requests[0].resolve({ data: { quote } }); await settle();
        assert.equal(h.render(selected).ready, true);
        assert.equal(h.render({ ...selected, item_quantities: { 10: 1 } }).ready, false);
        h.flush();
        assert.deepEqual(h.requests[1].args[1].item_quantities, { 10: 1 });
    } finally { h.close(); }
});

test('a rejected voucher keeps the new base quote and its reason while blocking confirmation', async () => {
    const h = harness();
    try {
        const selected = { ...input, code: 'BAGOO_SHIP', delivery_type: 'hub_self_pickup', pickup_hub_id: 3 };
        h.render(selected); h.flush();
        h.requests[0].reject({ response: { status: 422, data: { quote: { ...quote, voucher: null, discount: 0, shipping_discount: 0,
            shipping_fee: 0, total: 2000, voucher_error: 'There is no shipping fee for this voucher to reduce.' }, availableVouchers: [] } } });
        await settle();
        const state = h.render(selected);
        assert.equal(state.current, true); assert.equal(state.ready, false); assert.equal(state.quote.total, 2000);
        assert.equal(state.quote.voucher, null); assert.match(state.quote.voucher_error, /no shipping fee/);
        assert.deepEqual(state.offers, []);
    } finally { h.close(); }
});

test('Bag refreshes through the owned selection route and an empty selection needs no request', async () => {
    const h = harness();
    try {
        const selected = { ...input, bag: true };
        h.render(selected); h.flush();
        assert.equal(h.requests[0].method, 'get');
        assert.equal(h.requests[0].args[0], '/cart/index');
        assert.deepEqual(h.requests[0].args[1].params, { item_ids: [10] });
        const empty = { ...selected, item_ids: [] };
        h.render(empty); h.flush();
        assert.equal(h.requests.length, 1);
        assert.equal(h.requests[0].args[1].signal.aborted, true);
        assert.equal(h.render(empty).quote.total, 0); assert.equal(h.render(empty).ready, false);
    } finally { h.close(); }
});

test('network errors and malformed responses leave totals unavailable instead of ready', async () => {
    for (const malformed of [false, true]) {
        const h = harness();
        try {
            h.render(input); h.flush();
            if (malformed) h.requests[0].resolve({ data: {} });
            else h.requests[0].reject({ response: { status: 422, data: { errors: { item_ids: ['Selected items are unavailable.'] } } } });
            await settle();
            const state = h.render(input);
            assert.equal(state.ready, false); assert.equal(state.pending, false); assert.ok(state.failure);
            assert.equal(state.quote.total, 0);
        } finally { h.close(); }
    }
});

test('changing quantities or delivery context invalidates the previous confirmation immediately', async () => {
    const h = harness();
    try {
        h.render(input); h.flush();
        h.requests[0].resolve({ data: { quote } }); await settle();
        assert.equal(h.render(input).ready, true);
        const changed = { ...input, revision: 'quantity-two', shipping_province: 'Metro Manila', shipping_city: 'Pasig',
            delivery_type: 'hub_self_pickup', pickup_hub_id: 3 };
        const stale = h.render(changed);
        assert.equal(stale.current, false); assert.equal(stale.ready, false); assert.equal(stale.pending, true);
        h.flush();
        assert.equal(h.requests[1].args[1].delivery_type, 'hub_self_pickup');
        assert.equal(h.requests[1].args[1].shipping_city, 'Pasig');
        assert.equal(h.requests[1].args[1].pickup_hub_id, 3);
    } finally { h.close(); }
});

test('stacked checkout shows each real saving and deducts the combined amount once', async () => {
    const shop = { ...offer, id: 8, revision_id: 12, code: 'SHOP_100', name: 'Shop offer', shop_id: 1,
        shop_name: 'Bagoo shop one', issuer: 'shop', discount_type: 'fixed', discount_value: 100 };
    const platform = { ...offer, revision_id: 13, code: 'BAGOO_50', discount_type: 'fixed', discount_value: 50 };
    const stacked = { ...quote, subtotal: 1000, shipping_fee: 50, shipping_discount: 0, discount: 150, total: 900,
        voucher: null, vouchers: [shop, platform], redemptions: [{ revision_id: 12, discount_cents: 10000, allocations: [{ shop_id: 1, product_discount_cents: 10000, shipping_discount_cents: 0 }] },
            { revision_id: 13, discount_cents: 5000, allocations: [{ shop_id: 1, product_discount_cents: 5000, shipping_discount_cents: 0 }] }], shops: [{ ...quote.shops[0], discount: 150, shipping_discount: 0, total: 900 }] };
    globalThis.voucherQuoteState = { quote: stacked, offers: [shop, platform], current: true, pending: false, ready: true, failure: '' };
    try {
        const html = await render(ui.Checkout, 'Checkout/Index', { ...checkoutProps, quote: stacked });
        assert.match(html, /SHOP_100/);
        assert.match(html, /BAGOO_50/);
        assert.match(html, /Shop discount[\s\S]*?−₱100\.00/);
        assert.match(html, /Bagoo product discount[\s\S]*?−₱50\.00/);
        assert.match(html, /Total Payment:[\s\S]*?₱900\.00/);
        assert.match(html, /No voucher/);
        assert.match(html, /Cancelled orders keep their voucher use/);
    } finally { delete globalThis.voucherQuoteState; }
});

test('changing one voucher slot preserves sibling and platform choices even with an older quote', () => {
    const first = { ...offer, revision_id: 10, issuer: 'shop', shop_id: 1 };
    const second = { ...offer, revision_id: 11, issuer: 'shop', shop_id: 2 };
    const platform = { ...offer, revision_id: 12 };
    const oldFirst = { ...first, revision_id: 9 };
    const selection = { voucher_code: '', platform_voucher_revision_id: 12, shop_voucher_revision_ids: { 1: 10, 2: 11 } };
    const changed = ui.chooseVoucher(selection, { ...first, revision_id: 14 }, [oldFirst, second, platform]);
    assert.deepEqual(changed, { ...selection, shop_voucher_revision_ids: { 1: 14, 2: 11 } });
    assert.deepEqual(ui.withoutVoucher(changed, 2, [oldFirst, second, platform]), {
        ...selection, shop_voucher_revision_ids: { 1: 14, 2: null },
    });
    assert.deepEqual(ui.withoutVoucher(changed, null, [oldFirst, second, platform]), { ...changed, platform_voucher_revision_id: null });
    assert.deepEqual(selection.shop_voucher_revision_ids, { 1: 10, 2: 11 });
});

test('a successfully applied code keeps its campaign when another slot is selected', () => {
    const platform = { ...offer, revision_id: 12 };
    const shop = { ...offer, revision_id: 13, issuer: 'shop', shop_id: 2 };
    const code = { voucher_code: platform.code, platform_voucher_revision_id: null, shop_voucher_revision_ids: {} };
    assert.deepEqual(ui.chooseVoucher(code, shop, [platform]), {
        voucher_code: '', platform_voucher_revision_id: 12, shop_voucher_revision_ids: { 2: 13 },
    });
});

test('quote refresh carries all revision selections and rejects stale totals after a slot changes', async () => {
    const h = harness();
    try {
        const selected = { ...input, platform_voucher_revision_id: 12, shop_voucher_revision_ids: { 1: 10, 2: 11 } };
        h.render(selected); h.flush();
        assert.deepEqual(h.requests[0].args[1].shop_voucher_revision_ids, { 1: 10, 2: 11 });
        assert.equal(h.requests[0].args[1].platform_voucher_revision_id, 12);
        h.requests[0].resolve({ data: { quote } }); await settle();
        assert.equal(h.render(selected).ready, true);
        const changed = { ...selected, shop_voucher_revision_ids: { 1: null, 2: 11 } };
        assert.equal(h.render(changed).ready, false); h.flush();
        assert.deepEqual(h.requests[1].args[1].shop_voucher_revision_ids, { 1: null, 2: 11 });
        assert.equal(h.requests[0].args[2].signal.aborted, true);
    } finally { h.close(); }
});


test('collection tabs use whole-query counts and retain an ended saved offer', async () => {
    const ended = { ...offer, saved_id: 91, eligible: false, unavailable_reason: 'This campaign has ended. Your saved terms remain available.' };
    const html = await render(ui.Collection, 'Vouchers', { collection: { tab: 'saved', counts: { available: 18, saved: 1, used: 3, expired: 0 },
        offers: { data: [ended], current_page: 1, last_page: 1, per_page: 12, total: 1 } } });
    for (const label of ['Available (18)', 'Saved (1)', 'Used (3)', 'Expired (0)']) assert.ok(html.includes(label));
    assert.match(html, /Your saved terms remain available/);
    assert.match(html, /Remove saved/);
    assert.doesNotMatch(html, />Use voucher</);
});

test('empty saved collection stays centered with a useful next step', async () => {
    const html = await render(ui.Collection, 'Vouchers', { collection: { tab: 'saved', counts: { available: 0, saved: 0, used: 0, expired: 0 },
        offers: { data: [], current_page: 1, last_page: 1, per_page: 12, total: 0 } } });
    assert.match(html, /items-center justify-center/);
    assert.match(html, /No saved vouchers/);
    assert.match(html, /Save a shop or Bagoo offer/);
});

test('picker choices are labelled radios with explicit No voucher and disabled explanations', async () => {
    const unavailable = { ...offer, id: 4, revision_id: 4, eligible: false, unavailable_reason: 'This voucher is not available yet.' };
    const html = await render(ui.Choices, 'VoucherChoices', { offers: [offer, unavailable], chosen: null, onChoose: () => {} });
    assert.match(html, /type="radio"[^>]*checked=""/);
    assert.match(html, /No voucher/);
    assert.match(html, /disabled=""/);
    assert.match(html, /This voucher is not available yet/);
    assert.match(html, /Save ₱30\.00 on this checkout/);
});

test('preview repair preserves valid siblings and explicit None without inventing another voucher', () => {
    const selection = { voucher_code: '', platform_voucher_revision_id: 9, shop_voucher_revision_ids: { 1: 3, 2: 4, 5: null } };
    const result = ui.repairedVoucherSelection(selection, { voucher_code: null, platform_voucher_revision_id: 9, shop_voucher_revision_ids: { 1: null, 2: 4, 5: null } });
    assert.deepEqual(result, { ...selection, shop_voucher_revision_ids: { 1: null, 2: 4, 5: null } });
    assert.deepEqual(ui.repairedVoucherSelection(selection), selection);
});

test('best-voucher responses cannot replace choices after a Bag, address or manual-choice change', () => {
    const requested = JSON.stringify({ items: [1], city: 'Pasig', shop: 2 });
    assert.equal(ui.isVoucherRequestCurrent(requested, requested, false), true);
    for (const current of [JSON.stringify({ items: [2], city: 'Pasig', shop: 2 }), JSON.stringify({ items: [1], city: 'Manila', shop: 2 }), JSON.stringify({ items: [1], city: 'Pasig', shop: null })]) {
        assert.equal(ui.isVoucherRequestCurrent(requested, current, false), false);
    }
    assert.equal(ui.isVoucherRequestCurrent(requested, requested, true), false);
});

test('receipt displays recorded product and partial shipping amounts with one final COD', async () => {
    const html = renderToStaticMarkup(createElement(ui.Receipt, { receipt: { merchandise: 1000, shipping_before: 50, shipping_after: 20, total_savings: 130, total: 920, legacy_discount: false,
        discounts: [{ issuer: 'shop', code: 'ORIGINAL_SHOP', name: 'Recorded', product_discount: 100, shipping_discount: 0 }, { issuer: 'platform', code: 'ORIGINAL_SHIP', name: 'Recorded', product_discount: 0, shipping_discount: 30 }] } }));
    assert.match(html, /ORIGINAL_SHOP[\s\S]*?−₱100\.00/);
    assert.match(html, /Shipping payable[\s\S]*?₱20\.00/);
    assert.match(html, /Total COD[\s\S]*?₱920\.00/);
    assert.match(html, /<div class="[^"]*text-slate-600"><dt>Total savings<\/dt><dd>₱130\.00<\/dd><\/div>/);
    assert.match(html, /Total COD<\/dt><dd class="text-\[#E00D42\]">₱920\.00<\/dd>/);
    assert.doesNotMatch(html, /Free shipping/);
});
