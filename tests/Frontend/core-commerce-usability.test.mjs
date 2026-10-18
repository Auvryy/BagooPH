import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-core-commerce-'));
after(() => { rmSync(temporary, { recursive: true, force: true }); delete globalThis.coreCommerceHarness; delete globalThis.route; });
const result = await build({
    stdin: { contents: `export { default as Checkout } from '@/Pages/Checkout/Index'; export { default as Detail } from '@/Pages/Buyer/OrderDetail'; export { default as SellerOrders } from '@/Pages/Seller/Orders'; export { default as BuyAgain } from '@/Components/BuyAgainButton'; export { default as Bag } from '@/Pages/Cart/Index';`, loader: 'tsx', resolveDir: resolve(import.meta.dirname, '../..') },
    bundle: true, packages: 'external', platform: 'node', format: 'esm', jsx: 'automatic', write: false,
    plugins: [{ name: 'controlled-page-hooks', setup(builder) {
        builder.onResolve({ filter: /^react-dom$/ }, () => ({ path: 'portal', namespace: 'page-portal' }));
        builder.onLoad({ filter: /.*/, namespace: 'page-portal' }, () => ({ loader: 'js', contents: `export const createPortal = children => children;` }));
        builder.onResolve({ filter: /^react$/ }, () => ({ path: 'react', namespace: 'page-hooks' }));
        builder.onLoad({ filter: /.*/, namespace: 'page-hooks' }, () => ({ loader: 'js', contents: `
            export const useState = value => globalThis.coreCommerceHarness.state(value);
            export const useRef = value => globalThis.coreCommerceHarness.ref(value);
            export const useEffect = () => {};
            export const useLayoutEffect = () => {};
            export const useCallback = value => value;
            export const useMemo = callback => callback();
            export default {};
        ` }));
        builder.onResolve({ filter: /^@inertiajs\/react$/ }, () => ({ path: 'inertia', namespace: 'page-inertia' }));
        builder.onLoad({ filter: /.*/, namespace: 'page-inertia' }, () => ({ loader: 'js', contents: `
            export const useForm = value => globalThis.coreCommerceHarness.form(value);
            export const usePage = () => ({ props: { auth: { user: {} }, flash: {} } });
            export const router = { post: (...args) => globalThis.coreCommerceHarness.request('post', args), reload: (...args) => globalThis.coreCommerceHarness.request('reload', args) };
            export const Head = () => null;
            export const Link = () => null;
        ` }));
        builder.onResolve({ filter: /Layouts\/(BuyerLayout|BuyerOrderAccessLayout|DashboardLayout)$/ }, () => ({ path: 'layout', namespace: 'page-layout' }));
        builder.onLoad({ filter: /.*/, namespace: 'page-layout' }, () => ({ loader: 'js', contents: `export default function BuyerLayout() {}` }));
        builder.onResolve({ filter: /hooks\/usePurchaseQuote$/ }, () => ({ path: 'quote', namespace: 'page-quote' }));
        builder.onLoad({ filter: /.*/, namespace: 'page-quote' }, () => ({ loader: 'js', contents: `
            export default input => { globalThis.coreCommerceHarness.quoteInput = input; return { quote: globalThis.coreCommerceHarness.quote, current: true, pending: false, ready: true, failure: '', offers: [] }; }
        ` }));
    } }],
});
const bundlePath = join(temporary, 'checkout.mjs');
writeFileSync(bundlePath, result.outputFiles[0].text);
const { Checkout, Detail, SellerOrders, BuyAgain, Bag } = await import(pathToFileURL(bundlePath).href);
globalThis.route = name => '/' + name.replaceAll('.', '/');
const address = { id: 1, recipient_name: 'Ana Santos', phone: '+639171234567', street: '10 Mabini Street', city: 'Santa Cruz', province: 'Laguna', barangay: 'Poblacion', postal_code: '4009', latitude: 14.28, longitude: 121.42, landmark: 'Beside the municipal hall' };
const props = { user: { name: 'Ana Santos', phone: '+639171234567' }, addresses: [address], cart: { id: 1 }, items: [{ id: 1, quantity: 1, product: { id: 1, name: 'Everyday Bag', price: 10 } }], checkoutToken: 'isolated-checkout', kycStatus: 'approved', pickupHubs: [{ id: 3, name: 'Santa Cruz Bayan Hub', code: 'BAYAN-3', address: '20 Hub Street', city_municipality: 'Santa Cruz', province: 'Laguna' }] };
function harness(overrides = {}) {
    const slots = [], forms = [], requests = []; let cursor = 0, formCursor = 0;
    const h = {
        quote: { items: [], shops: [], vouchers: [], total: 10, subtotal: 10, doorstep_shipping_fee: 50, shipping_fee: 0, shipping_discount: 0 },
        state(initial) { const index = cursor++; if (!(index in slots)) slots[index] = typeof initial === 'function' ? initial() : initial; return [slots[index], value => { slots[index] = typeof value === 'function' ? value(slots[index]) : value; }]; },
        ref(initial) { const [value] = this.state(() => ({ current: initial })); return value; },
        form(initial) {
            const index = formCursor++;
            forms[index] ??= { data: initial, errors: {}, processing: false };
            const form = forms[index];
            return { ...form, setData(key, value) { form.data = typeof key === 'function' ? key(form.data) : { ...form.data, [key]: value }; }, reset() {}, transform(callback) { form.transform = callback; }, post(url, options) { requests.push({ url, data: structuredClone(form.transform ? form.transform(form.data) : form.data), options }); } };
        },
        render() { return this.renderPage(Checkout, { ...props, ...overrides }); },
        renderPage(Component, properties) { cursor = 0; formCursor = 0; return Component(properties); },
        request(method, args) { requests.push({ method, args }); },
        get data() { return forms[1].data; },
        set errors(value) { forms[1].errors = value; },
        formErrors(index, value) { forms[index].errors = value; },
        requests,
    };
    globalThis.coreCommerceHarness = h;
    return h;
}
function all(node, predicate, found = []) {
    if (Array.isArray(node)) node.forEach(child => all(child, predicate, found));
    else if (node && typeof node === 'object') { if (predicate(node)) found.push(node); all(node.props?.children, predicate, found); }
    return found;
}
const field = (tree, id) => all(tree, node => node.props?.id === id)[0];
const text = node => Array.isArray(node) ? node.map(text).join(' ') : node && typeof node === 'object' ? text(node.props?.children) : typeof node === 'string' || typeof node === 'number' ? String(node) : '';
const radio = (tree, value) => all(tree, node => node.type === 'input' && node.props.type === 'radio' && node.props.value === value)[0];

test('Buy again sends one direct Bag mutation and retains server feedback for a retry', () => {
    const h = harness();
    const properties = { url: '/buyer/orders/20/buy-again', token: 'owned-request' };
    let tree = h.renderPage(BuyAgain, properties);
    const button = all(tree, node => node.type === 'button')[0];
    button.props.onClick(); button.props.onClick();
    assert.equal(h.requests.length, 1);
    assert.deepEqual(h.requests[0].data, { mode: 'purchase', request_token: 'owned-request' });
    assert.equal(h.requests[0].options.preserveScroll, false);
    h.formErrors(0, { items: 'This selection is out of stock. Nothing was added.' });
    h.requests[0].options.onFinish(); tree = h.renderPage(BuyAgain, properties);
    assert.match(text(all(tree, node => node.props?.role === 'alert')), /out of stock/);
    all(tree, node => node.type === 'button')[0].props.onClick();
    assert.equal(h.requests.length, 2);
    assert.deepEqual(h.requests[1].data, h.requests[0].data);
});

test('the Bag selects the returned repurchase lines instead of an unrelated most recent item', () => {
    const h = harness();
    const row = (id, date, available = true) => ({ id, product_id: id, quantity: 1, unit_price: 100, updated_at: date, available_for_purchase: available,
        product: { id, name: `Bag item ${id}`, stock: 5, price: 100, shop: { id: 1, name: 'Bagoo Crafts' } } });
    const tree = h.renderPage(Bag, { cart: { id: 1 }, total: 300, items: [row(1, '2026-10-19'), row(2, '2026-10-18'), row(3, '2026-10-17'), row(4, '2026-10-16', false)], selectedBagItemIds: [2, 3, 4, 999] });
    const checkboxes = all(tree, node => node.type === 'input' && node.props.type === 'checkbox');
    assert.equal(checkboxes.filter(node => node.props.checked).length, 2);
    assert.match(text(tree), /Select available.*2.*3\s+items/s);
    assert.deepEqual(h.quoteInput.item_ids, [2, 3]);
});

test('self-pickup keeps every required address field editable and never replaces buyer address or coordinates with the hub', () => {
    const h = harness(); let tree = h.render(); const original = structuredClone(h.data);
    radio(tree, 'hub_self_pickup').props.onChange(); tree = h.render();
    for (const id of ['shipping_address', 'destination_barangay', 'shipping_city', 'shipping_province', 'shipping_postal_code']) {
        const input = field(tree, `checkout-${id}`);
        assert.ok(input, `${id} remains present for self-pickup`); assert.equal(input.props.required, true); assert.equal(input.props.disabled, undefined);
    }
    field(tree, 'checkout-pickup-hub').props.onChange({ target: { value: '3' } }); tree = h.render();
    assert.deepEqual(h.data, { ...original, delivery_type: 'hub_self_pickup', pickup_hub_id: '3' });
    assert.equal(h.quoteInput.shipping_city, 'Santa Cruz');
    assert.equal(h.quoteInput.pickup_hub_id, '3');
    assert.match(text(tree), /hub serves your area/);
    assert.match(text(tree), /Pay the amount due in cash at the destination hub/);
    assert.doesNotMatch(text(tree), /SMS|100% Escrow|guaranteed delivery|Mon–Sat 8:00/);
    radio(tree, 'doorstep').props.onChange(); tree = h.render();
    assert.equal(h.quoteInput.pickup_hub_id, ''); assert.equal(h.data.shipping_address, address.street);
    assert.equal(h.data.shipping_latitude, address.latitude); assert.equal(h.data.shipping_longitude, address.longitude);
});

test('a buyer without saved addresses can supply missing self-pickup fields instead of being blocked by hidden requirements', () => {
    const h = harness({ addresses: [] }); let tree = h.render();
    radio(tree, 'hub_self_pickup').props.onChange(); tree = h.render();
    for (const id of ['shipping_address', 'destination_barangay', 'shipping_city', 'shipping_province', 'shipping_postal_code']) field(tree, `checkout-${id}`).props.onChange({ target: { value: id === 'shipping_postal_code' ? '4009' : address[{ shipping_address: 'street', destination_barangay: 'barangay', shipping_city: 'city', shipping_province: 'province' }[id]] } });
    field(tree, 'checkout-pickup-hub').props.onChange({ target: { value: '3' } }); tree = h.render();
    all(tree, node => node.type === 'form')[0].props.onSubmit({ preventDefault() {} }); tree = h.render();
    assert.equal(all(tree, node => typeof node.props?.open === 'boolean')[0].props.open, true);
    assert.equal(h.data.shipping_postal_code, '4009'); assert.equal(h.data.destination_barangay, 'Poblacion');
});

test('final order confirmation accepts one pending write, retains safe details and permits retry only after completion', () => {
    const h = harness(); let tree = h.render();
    all(tree, node => node.type === 'form')[0].props.onSubmit({ preventDefault() {} }); tree = h.render();
    const agreement = all(tree, node => node.type === 'input' && node.props.type === 'checkbox').at(-1);
    agreement.props.onChange({ target: { checked: true } }); tree = h.render();
    const confirm = all(tree, node => node.type === 'button' && text(node) === 'Confirm & Place Order')[0];
    confirm.props.onClick(); confirm.props.onClick();
    assert.equal(h.requests.length, 1); assert.equal(h.requests[0].data.checkout_token, 'isolated-checkout');
    assert.equal(h.requests[0].options.preserveScroll, true);
    h.requests[0].options.onFinish(); tree = h.render();
    assert.equal(all(tree, node => typeof node.props?.open === 'boolean')[0].props.open, false);
    assert.equal(h.data.shipping_address, address.street);
    assert.equal(h.data.shipping_postal_code, '4009');
    all(tree, node => node.type === 'form')[0].props.onSubmit({ preventDefault() {} }); tree = h.render();
    assert.equal(all(tree, node => node.type === 'input' && node.props.type === 'checkbox').at(-1).props.checked, false);
    all(tree, node => node.type === 'input' && node.props.type === 'checkbox').at(-1).props.onChange({ target: { checked: true } }); tree = h.render();
    all(tree, node => node.type === 'button' && text(node) === 'Confirm & Place Order')[0].props.onClick();
    assert.equal(h.requests.length, 2);
});

test('checkout fields expose server-matching limits, persistent labels and associated rejection text', () => {
    const h = harness(); h.render(); h.errors = { destination_barangay: 'Choose a supported barangay.', shipping_province: 'Enter a province.', shipping_postal_code: 'Use four digits.' };
    const tree = h.render();
    for (const [name, minimum, maximum] of [['shipping_address', 5, 500], ['destination_barangay', 2, 100], ['shipping_city', 2, 100], ['shipping_province', 2, 100], ['shipping_postal_code', 4, 4], ['landmark', 5, 255]]) {
        const input = field(tree, `checkout-${name}`);
        assert.equal(input.props.minLength, minimum); assert.equal(input.props.maxLength, maximum);
        assert.ok(all(tree, node => node.type === 'label' && node.props.htmlFor === input.props.id).length);
    }
    for (const name of Object.keys(h.data).filter(name => ['destination_barangay', 'shipping_province', 'shipping_postal_code'].includes(name))) {
        const input = field(tree, `checkout-${name}`);
        assert.equal(input.props['aria-invalid'], true); assert.equal(text(field(tree, input.props['aria-describedby'])), ({ destination_barangay: 'Choose a supported barangay.', shipping_province: 'Enter a province.', shipping_postal_code: 'Use four digits.' })[name]);
    }
});


test('receipt confirmation prevents a duplicate request, blocks refresh while saving and exposes rejection before a safe retry', () => {
    const h = harness();
    const detailProps = { order: { id: 20, order_number: 'BGO-RECEIPT', status: 'delivered', items: [], created_at: '2026-10-01', total_amount: 10 }, canUsePortal: true, canConfirmReceipt: true, pickupClaim: null, pickupHub: null, pickupCodeUrl: null, orderNotices: [] };
    const previousConfirm = globalThis.confirm;
    globalThis.confirm = () => true;
    try {
        let tree = h.renderPage(Detail, detailProps);
        const receipt = all(tree, node => node.type === 'button' && text(node).trim() === 'Confirm Order Received')[0];
        receipt.props.onClick(); receipt.props.onClick();
        assert.equal(h.requests.length, 1); assert.equal(h.requests[0].method, 'post');
        tree = h.renderPage(Detail, detailProps);
        const pending = all(tree, node => node.type === 'button' && text(node).trim() === 'Confirming receipt…')[0];
        assert.equal(pending.props.disabled, true);
        all(tree, node => node.props?.onRefresh)[0].props.onRefresh();
        assert.equal(h.requests.length, 1);
        const options = h.requests[0].args[2];
        assert.equal(options.preserveScroll, true);
        options.onError({ order: 'This order is not ready for receipt confirmation.' }); options.onFinish();
        tree = h.renderPage(Detail, detailProps);
        assert.match(text(tree), /This order is not ready for receipt confirmation/);
        const retry = all(tree, node => node.type === 'button' && text(node).trim() === 'Confirm Order Received')[0];
        assert.equal(retry.props.disabled, false); retry.props.onClick();
        assert.equal(h.requests.length, 2);
    } finally { globalThis.confirm = previousConfirm; }
});


test('seller tracking copy waits for the clipboard result and keeps readable recovery feedback on rejection', async () => {
    const h = harness();
    const sellerProps = { shop: { id: 1, name: 'Bagoo Crafts' }, shopEligible: true, returnReceiptToken: 'return-test', currentStatus: 'all', counts: {}, cancellationReasons: ['Other reason'], batchLimit: 50, orders: { data: [{ id: 20, order_number: 'BGO-COPY', status: 'picked_up', items: [], delivery: { tracking_number: 'BGO-TEST-WAYBILL' } }], total: 1, current_page: 1, last_page: 1 } };
    const previousNavigator = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
    const previousTimeout = globalThis.setTimeout;
    let resolveCopy, rejectCopy;
    let copyPromise = new Promise((resolve, reject) => { resolveCopy = resolve; rejectCopy = reject; });
    Object.defineProperty(globalThis, 'navigator', { configurable: true, value: { clipboard: { writeText: text => { assert.equal(text, 'BGO-TEST-WAYBILL'); return copyPromise; } } } });
    globalThis.setTimeout = () => 1;
    try {
        let tree = h.renderPage(SellerOrders, sellerProps);
        const button = () => all(tree, node => node.type === 'button' && node.props.title === 'Click to copy tracking number')[0];
        const iconNames = () => all(button(), node => typeof node.type === 'object').map(node => node.type.displayName);
        const pending = button().props.onClick();
        tree = h.renderPage(SellerOrders, sellerProps); assert.ok(iconNames().includes('Copy')); assert.ok(!iconNames().includes('Check'));
        rejectCopy(new Error('Permission denied')); await pending;
        tree = h.renderPage(SellerOrders, sellerProps);
        assert.match(text(tree), /tracking code could not be copied/); assert.ok(iconNames().includes('Copy')); assert.ok(!iconNames().includes('Check'));
        copyPromise = new Promise(resolve => { resolveCopy = resolve; });
        const retry = button().props.onClick(); resolveCopy(); await retry;
        tree = h.renderPage(SellerOrders, sellerProps);
        assert.ok(iconNames().includes('Check')); assert.doesNotMatch(text(tree), /tracking code could not be copied/);
    } finally {
        if (previousNavigator) Object.defineProperty(globalThis, 'navigator', previousNavigator); else delete globalThis.navigator;
        globalThis.setTimeout = previousTimeout;
    }
});


test('a delayed clipboard write cannot close or mark a different seller order after the QR selection changes', async () => {
    const h = harness();
    const order = id => ({ id, order_number: `BGO-ORDER-${id}`, status: 'preparing', can_print_waybill: true, items: [], delivery: { tracking_number: `BGO-WAYBILL-${id}` } });
    const sellerProps = { shop: { id: 1, name: 'Bagoo Crafts' }, shopEligible: true, returnReceiptToken: 'return-test', currentStatus: 'all', counts: {}, cancellationReasons: ['Other reason'], batchLimit: 50, orders: { data: [order(20), order(21)], total: 2, current_page: 1, last_page: 1 } };
    const previousNavigator = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
    const previousDocument = globalThis.document, previousWindow = globalThis.window;
    let finishCopy;
    Object.defineProperty(globalThis, 'navigator', { configurable: true, value: { clipboard: { writeText: () => new Promise(resolve => { finishCopy = resolve; }) } } });
    globalThis.document = { body: {} }; globalThis.window = { location: { origin: 'https://example.invalid' } };
    try {
        let tree = h.renderPage(SellerOrders, sellerProps);
        const openers = () => all(tree, node => node.type === 'button' && node.props.title === 'Show QR Code for Courier Scan & Tracking');
        openers()[0].props.onClick(); tree = h.renderPage(SellerOrders, sellerProps);
        const pending = all(tree, node => node.type === 'button' && text(node).trim() === 'Copy Code')[0].props.onClick();
        openers()[1].props.onClick(); tree = h.renderPage(SellerOrders, sellerProps);
        assert.match(text(tree), /Order # BGO-ORDER-21/);
        finishCopy(); await pending; tree = h.renderPage(SellerOrders, sellerProps);
        assert.match(text(tree), /Order # BGO-ORDER-21/);
        assert.ok(all(tree, node => node.type === 'button' && text(node).trim() === 'Copy Code').length);
        assert.doesNotMatch(text(tree), /tracking code could not be copied/);
    } finally {
        if (previousNavigator) Object.defineProperty(globalThis, 'navigator', previousNavigator); else delete globalThis.navigator;
        if (previousDocument === undefined) delete globalThis.document; else globalThis.document = previousDocument;
        if (previousWindow === undefined) delete globalThis.window; else globalThis.window = previousWindow;
    }
});

test('a previously rendered receipt handler cannot act after fresh props remove permission or change the order', () => {
    const h = harness();
    const detailProps = { order: { id: 20, order_number: 'BGO-RECEIPT', status: 'delivered', items: [], created_at: '2026-10-01', total_amount: 10 }, canUsePortal: true, canConfirmReceipt: true, pickupClaim: null, pickupHub: null, pickupCodeUrl: null, orderNotices: [] };
    const previousConfirm = globalThis.confirm;
    globalThis.confirm = () => true;
    try {
        const tree = h.renderPage(Detail, detailProps);
        const stale = all(tree, node => node.type === 'button' && text(node).trim() === 'Confirm Order Received')[0].props.onClick;
        h.renderPage(Detail, { ...detailProps, canConfirmReceipt: false }); stale(); assert.equal(h.requests.length, 0);
        h.renderPage(Detail, { ...detailProps, order: { ...detailProps.order, id: 21 } }); stale(); assert.equal(h.requests.length, 0);
    } finally { globalThis.confirm = previousConfirm; }
});
