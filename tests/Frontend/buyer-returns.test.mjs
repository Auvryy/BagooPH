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
const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-buyer-returns-'));
after(() => { rmSync(temporary, { recursive: true, force: true }); delete globalThis.returnHarness; delete globalThis.route; });
const result = await build({ stdin: { contents: `export { default as Create } from '@/Pages/Returns/Create'; export { default as Show } from '@/Pages/Returns/Show'; export { default as Index } from '@/Pages/Returns/Index'; export { default as Custody } from '@/Components/Returns/ReturnCustodyPanel'; export { default as Money } from '@/Components/Returns/ReturnMoneyPanel'; export { default as Manifests } from '@/Pages/Hub/Manifests';`, resolveDir: root, loader: 'tsx' },
    bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', write: false, loader: { '.css': 'empty' }, plugins: [{ name: 'return-controls', setup(builder) {
        builder.onResolve({ filter: /\.css$/ }, args => ({ path: args.path, namespace: 'return-styles' }));
        builder.onLoad({ filter: /.*/, namespace: 'return-styles' }, () => ({ contents: '', loader: 'js' }));
        builder.onResolve({ filter: /^@inertiajs\/react$/ }, args => /(?:Pages\/Returns\/(Create|Show)|Components\/Returns\/(?:ReturnCustodyPanel|ReturnMoneyPanel)|Pages\/Hub\/Manifests)\.tsx$/.test(args.importer) ? { path: 'inertia', namespace: 'return-probe' } : undefined);
        builder.onLoad({ filter: /^inertia$/, namespace: 'return-probe' }, () => ({ contents: `export * from '@inertiajs/react'; import { useForm as real } from '@inertiajs/react'; export const useForm = initial => globalThis.returnHarness ? globalThis.returnHarness.useForm(initial) : real(initial);`, resolveDir: root }));
        builder.onResolve({ filter: /^react$/ }, args => /(?:Pages\/Returns\/(Create|Show)|Components\/Returns\/(?:ReturnCustodyPanel|ReturnMoneyPanel)|Pages\/Hub\/Manifests)\.tsx$/.test(args.importer) ? { path: 'react', namespace: 'return-probe' } : undefined);
        builder.onLoad({ filter: /^react$/, namespace: 'return-probe' }, () => ({ contents: `export * from 'react'; import React from 'react'; export const useRef = initial => globalThis.returnHarness ? globalThis.returnHarness.ref(initial) : React.useRef(initial);`, resolveDir: root }));
        builder.onResolve({ filter: /^react\/jsx-runtime$/ }, args => /(?:Pages\/Returns\/(Create|Show)|Components\/Returns\/(?:ReturnCustodyPanel|ReturnMoneyPanel)|Pages\/Hub\/Manifests|Components\/Logistics\/VehiclePicker)\.tsx$/.test(args.importer) ? { path: 'jsx', namespace: 'return-probe' } : undefined);
        builder.onLoad({ filter: /^jsx$/, namespace: 'return-probe' }, () => ({ contents: `import { jsx as real, jsxs as many, Fragment } from 'react/jsx-runtime'; export { Fragment }; const track = (fn,type,props,key) => { if(globalThis.returnHarness && (props.onSubmit || props.onChange || props.onClick)) globalThis.returnHarness.handlers.push({type,props}); return fn(type,props,key); }; export const jsx=(...args)=>track(real,...args);export const jsxs=(...args)=>track(many,...args);`, resolveDir: root }));
    } }] });
const path = join(temporary, 'returns.mjs'); writeFileSync(path, result.outputFiles[0].text); const ui = await import(pathToFileURL(path).href);
globalThis.route = name => name ? `/${name.replaceAll('.', '/')}` : { current: () => false, has: () => true };
const user = role => ({ id: 7, role, name: 'Ana Santos', email: `${role}@bagoo.test`, status: 'active', kyc_status: 'approved' });
const props = { order: { id: 30, order_number: 'BGO-RETURN' }, requestToken: 'signed-original-order-token', requestUntil: '2026-10-26T00:00:00Z', reasons: { damaged: 'Item damaged', wrong_item: 'Wrong item' },
    items: [{ id: 50, name: 'Everyday travel bag', color: 'Blue', size: 'Medium', quantity: 2, available_quantity: 1, unit_price: '250.00' }, { id: 51, name: 'Already requested item', quantity: 1, available_quantity: 0, unit_price: '150.00' }] };
const caseData = { id: 11, reference: 'BR-OWNED', version: 2, status: 'rejected', reason: 'damaged', description: 'The delivered item fastening is broken.', order: props.order, actions: ['escalate', 'withdraw'],
    response_until: '2026-10-22T00:00:00Z', appeal_until: '2026-10-23T00:00:00Z', dropoff_until: null, items: [{ id: 1, quantity: 1, purchase: { name: 'Everyday travel bag', color: 'Blue', size: 'Medium', unit_price: '250.00' } }],
    evidence: [{ id: 2, url: '/buyer-returns/11/evidence/2', bytes: 12000, mime: 'image/png' }], history: [{ reference: 'BRE-DECISION', event_type: 'reject', actor_role: 'seller', reason: 'The photo does not show the purchased item label. Please provide its original label.', target_status: 'rejected', created_at: '2026-10-20T00:00:00Z' }] };
async function render(Component, supplied, role = 'buyer') {
    if (globalThis.returnHarness) { globalThis.returnHarness.cursor = 0; globalThis.returnHarness.handlers = []; }
    let view; const response = await createInertiaApp({ page: { component: 'Returns/Show', props: { auth: { user: user(role) }, flash: {}, cartCount: 0, ...supplied }, url: '/buyer-returns/11', version: null },
        resolve: () => Component, setup: ({ App, props }) => { view = createElement(App, props); return view; }, render: () => renderToStaticMarkup(view) }); return response.body;
}
class Harness {
    cursor = 0; refs = []; handlers = []; posts = []; form;
    ref(initial) { return this.refs[this.cursor++] ??= { current: initial }; }
    useForm(initial) { return this.form ??= { data: initial, errors: {}, processing: false,
        setData: (key, value) => { if (typeof key === 'object') this.form.data = key; else this.form.data[key] = value; },
        setError: (key, value) => { this.form.errors[key] = value; }, clearErrors: (...keys) => { if (!keys.length) this.form.errors = {}; else keys.forEach(key => delete this.form.errors[key]); },
        post: (url, options) => { this.posts.push({ url, options, data: structuredClone(this.form.data) }); this.form.processing = true; } }; }
    control(test) { const match = this.handlers.find(test); assert.ok(match, 'expected actual rendered control'); return match.props; }
    submit() { this.control(node => node.type === 'form').onSubmit({ preventDefault() {} }); }
}

test('request form explains the selected window, original items, private photos and real upload limits', async () => {
    const html = await render(ui.Create, props);
    assert.match(html, /including after confirming receipt|Confirming receipt does not end this window/);
    assert.match(html, /One to five photos/); assert.match(html, /up to 5 MB each/); assert.match(html, /10–1,000 characters/);
    assert.match(html, /id="return-item-51"[^>]*disabled=""/); assert.match(html, /All purchased units already/);
    assert.doesNotMatch(html, /name="amount"|name="seller_id"|payment received|refund paid/i);
});

test('selecting an original unit uses its bounded quantity and rejects missing, excess or unsupported photos before posting', async () => {
    const h = globalThis.returnHarness = new Harness();
    try {
        await render(ui.Create, props); h.submit(); assert.match(h.form.errors.items, /at least one/);
        h.control(node => node.props.id === 'return-item-50').onChange({ target: { checked: true } });
        await render(ui.Create, props);
        const quantity = h.control(node => node.type === 'input' && node.props.type === 'number');
        assert.equal(quantity.min, '1'); assert.equal(quantity.max, 1); assert.deepEqual(h.form.data.items, [{ order_item_id: 50, quantity: 1 }]);
        for (const files of [[], Array(6).fill({ type: 'image/png', size: 1 }), [{ type: 'video/mp4', size: 1 }], [{ type: 'image/png', size: 5242881 }]]) {
            h.form.data.photos = files; h.submit(); assert.equal(h.posts.length, 0); assert.match(h.form.errors.photos, /one to five/);
        }
        h.form.data.photos = [{ name: 'damage.png', type: 'image/png', size: 5242880 }]; h.form.data.description = 'The fastening broke on arrival.'; h.form.data.reason = 'damaged';
        h.submit(); h.submit(); assert.equal(h.posts.length, 1); assert.equal(h.posts[0].url, '/buyer-returns/orders/30');
        assert.equal(h.posts[0].data.request_token, props.requestToken); assert.equal(h.posts[0].options.forceFormData, true);
        h.form.processing = false; h.posts[0].options.onFinish();
        h.form.errors = { description: 'Use meaningful plain text.', 'photos.0': 'Choose an actual image.' };
        const html = await render(ui.Create, props); assert.match(html, /Use meaningful plain text/); assert.match(html, /Choose an actual image/); assert.match(html, /damage.png/);
        assert.equal(h.form.data.photos.length, 1); h.submit(); assert.equal(h.posts.length, 2); assert.equal(h.posts[1].data.request_token, h.posts[0].data.request_token);
    } finally { delete globalThis.returnHarness; }
});

for (const role of ['buyer', 'seller', 'admin']) test(`${role} return history retains its portal, exact seller feedback and protected photo URL`, async () => {
    const html = await render(ui.Show, { returnCase: caseData }, role);
    assert.match(html, new RegExp(role === 'buyer' ? 'Marketplace' : role === 'seller' ? 'Seller Centre' : 'Admin Portal'));
    assert.match(html, /The photo does not show the purchased item label/); assert.match(html, /Ask admin to review/);
    assert.match(html, /href="\/buyer-returns\/11\/evidence\/2"/); assert.match(html, /Request admin review by/);
    assert.doesNotMatch(html, /buyer-return-evidence\/|creation_token_hash|Refund paid/);
});

test('decision submission retains the version and request token through recoverable errors and blocks double clicks', async () => {
    const h = globalThis.returnHarness = new Harness();
    try {
        await render(ui.Show, { returnCase: caseData });
        h.form.data.action = 'approve'; h.form.data.reason = 'An unauthorized action must not be posted.'; h.submit(); assert.equal(h.posts.length, 0);
        h.form.data.action = 'escalate'; h.form.data.reason = 'Please review the original label and purchase evidence.';
        h.submit(); h.submit(); assert.equal(h.posts.length, 1); assert.equal(h.posts[0].data.expected_version, 2);
        h.form.processing = false; h.posts[0].options.onFinish(); h.form.errors = { return: 'Reload the saved history before retrying.' };
        const html = await render(ui.Show, { returnCase: caseData }); assert.match(html, /Reload the saved history before retrying/);
        h.submit(); assert.equal(h.posts[1].data.request_token, h.posts[0].data.request_token);
        assert.equal(h.posts[1].data.reason, h.posts[0].data.reason);
    } finally { delete globalThis.returnHarness; }
});

test('empty return queues center their explanation and never fabricate a claim or repayment', async () => {
    const html = await render(ui.Index, { cases: { data: [], current_page: 1, last_page: 1 } });
    assert.match(html, /min-h-72 flex-col items-center justify-center/); assert.match(html, /No return requests/);
    assert.doesNotMatch(html, /BR-OWNED|Repaid|Sample return/);
});

const custody = { barcode: 'BRT-ABCDEFGHIJKLMNOPQRST', status: 'awaiting_dropoff', position: 0, weight_grams: null,
    custody: { kind: 'buyer' }, can_dropoff: true, current_hub_name: null, manifest_reference: null, manifest_url: null,
    hubs: [{ id: 3, name: 'Destination Bayan Hub', code: 'BH-DEST', address: '100 Destination Road', operating_hours: '09:00–17:00' },
        { id: 2, name: 'Mother Hub', code: 'MH', address: '100 Mother Road' }, { id: 1, name: 'Origin Bayan Hub', code: 'BH-ORIGIN', address: '100 Origin Road' }],
    actions: [{ action: 'intake', hub_id: 3, label: 'Accept physical return' }] };

test('return custody shows its own code, actual ordered hubs, intake weight and protected handover photo limits', async () => {
    const html = await render(ui.Custody, { custody, caseId: 11, version: 2 });
    assert.match(html, /BRT-ABCDEFGHIJKLMNOPQRST/); assert.match(html, /original delivery waybill cannot record this return/);
    assert.ok(html.indexOf('Destination Bayan Hub') < html.indexOf('Mother Hub')); assert.ok(html.indexOf('Mother Hub') < html.indexOf('Origin Bayan Hub'));
    assert.match(html, /Actual return custody/); assert.match(html, /Bring the approved items/);
    assert.doesNotMatch(html, /Current holder|Refund paid|payment received/);
    const expired = await render(ui.Custody, { custody: { ...custody, can_dropoff: false, actions: [] }, caseId: 11, version: 3 });
    assert.match(expired, /No current drop-off authority/); assert.doesNotMatch(expired, /Bring the approved items|<form/);
});

test('hub return guidance names the next handler and stages the parcel before asking for seller receipt', async () => {
    const received = { ...custody, status: 'at_hub', custody: { kind: 'hub' }, current_hub_name: 'Destination Bayan Hub', actions: [] };
    const onward = await render(ui.Custody, { custody: received, caseId: 11, version: 3 }, 'logistics');
    assert.match(onward, /Next hub: Mother Hub/);
    assert.match(onward, /sending handler prepares the next leg/);
    assert.match(onward, /Open transfers/);
    assert.ok(onward.indexOf('Next hub: Mother Hub') < onward.indexOf('Barcode and QR for this return'));
    assert.match(onward, /<details[^>]*><summary[^>]*>Barcode and QR for this return/);
    const prepared = await render(ui.Custody, { custody: { ...received, manifest_pending: true, manifest_url: '/hub/manifests/5', manifest_reference: 'MFT-ORIGINAL' }, caseId: 11, version: 4 }, 'logistics');
    assert.match(prepared, /MFT-ORIGINAL is already prepared/);
    assert.match(prepared, /href="\/hub\/manifests\/5"[^>]*>Continue transfer/);
    assert.doesNotMatch(prepared, /prepares the next leg|>Open transfers</);
    const travelling = await render(ui.Custody, { custody: { ...received, custody: { kind: 'manifest' }, status: 'in_transit', current_hub_name: null, manifest_url: '/hub/manifests/5', manifest_reference: 'MFT-ORIGINAL' }, caseId: 11, version: 5 }, 'logistics');
    assert.match(travelling, /Next: receipt at Mother Hub/);
    assert.match(travelling, /Mother Hub’s receiving handler/);
    assert.match(travelling, /href="\/hub\/manifests\/5"[^>]*>Open arrival manifest/);
    assert.doesNotMatch(travelling, />Open transfers<|>Continue transfer</);
    const atOrigin = { ...received, position: 2, current_hub_name: 'Origin Bayan Hub', actions: [{ action: 'stage', hub_id: 1, label: 'Stage for seller collection' }] };
    const staging = await render(ui.Custody, { custody: atOrigin, caseId: 11, version: 6 }, 'logistics');
    assert.match(staging, /Next: stage for seller collection/);
    assert.match(staging, /Origin Bayan Hub’s handler stages/);
    assert.doesNotMatch(staging, /Next: seller records receipt|Open transfers/);
    const ready = await render(ui.Custody, { custody: { ...atOrigin, status: 'ready_for_seller', actions: [] }, caseId: 11, version: 7 }, 'logistics');
    assert.match(ready, /Next: seller records receipt/);
    assert.doesNotMatch(ready, /Next: stage for seller collection|<form/);
    const buyer = await render(ui.Custody, { custody, caseId: 11, version: 2 });
    assert.doesNotMatch(buyer, /Barcode and QR for this return|Open transfers/);
});

test('actual handover validates its separate code and photo, submits the scoped hub and keeps a retry token', async () => {
    const h = globalThis.returnHarness = new Harness();
    try {
        await render(ui.Custody, { custody, caseId: 11, version: 2 });
        h.control(node => node.type === 'select').onChange({ target: { value: 'intake' } });
        await render(ui.Custody, { custody, caseId: 11, version: 2 });
        h.form.data.barcode = 'BGO-ORIGINAL'; h.submit(); assert.equal(h.posts.length, 0); assert.match(h.form.errors.barcode, /separate return barcode/);
        h.form.data.barcode = custody.barcode; h.form.data.weight_kg = '2.500'; h.form.data.condition = 'intact'; h.form.data.reason = 'The actual parcel and its selected units were checked at intake.';
        h.submit(); assert.match(h.form.errors.photo, /up to 5 MB/); assert.equal(h.posts.length, 0);
        h.form.data.photo = { type: 'image/png', size: 500, name: 'intake.png' };
        let transform;
        h.form.transform = fn => { transform = fn; };
        const originalPost = h.form.post;
        h.form.post = (url, options) => { const actual = transform(h.form.data); originalPost(url, options); h.posts.at(-1).data = structuredClone(actual); };
        h.submit(); h.submit(); assert.equal(h.posts.length, 1); assert.equal(h.posts[0].url, '/buyer-returns/11/custody');
        assert.equal(h.posts[0].data.hub_id, 3); assert.equal(h.posts[0].data.expected_version, 2); assert.equal(h.posts[0].data.weight_kg, '2.500');
        assert.equal(h.posts[0].options.forceFormData, true);
        h.form.processing = false; h.posts[0].options.onFinish(); h.form.errors = { custody: 'The handover could not be confirmed. Reload its history.' };
        const html = await render(ui.Custody, { custody, caseId: 11, version: 2 }); assert.match(html, /hand-over|handover could not be confirmed/);
        h.submit(); assert.equal(h.posts[1].data.request_token, h.posts[0].data.request_token);
    } finally { delete globalThis.returnHarness; }
});

test('manifest preparation selects an actual accepted buyer return and retains only its prescribed next leg', async () => {
    const h = globalThis.returnHarness = new Harness();
    const data = { manifests: { data: [], links: [] }, selectedManifest: null, canCreate: true, creationToken: 'creation-token', commandToken: 'command-token', basePath: '/hub/manifests',
        sourceHubIds: [3], hubs: [{ id: 3, name: 'Destination Bayan Hub', code: 'BH-DEST', tier: 'local_bayan_hub', logistics_company_id: 1, parent_hub_id: 2 }, { id: 2, name: 'Mother Hub', code: 'MH', tier: 'regional_mother_hub', logistics_company_id: 1 }],
        vehicles: [{ id: 5, hub_id: 3, plate_number: 'RET-TEST', vehicle_type: 'l300_van', driver_name: 'Assigned Driver' }],
        buyerReturns: [{ id: 11, reference: 'BR-OWNED', barcode: custody.barcode, source_hub_id: 3, destination_hub_id: 2, weight_grams: 2500 }] };
    try {
        await render(ui.Manifests, data, 'logistics');
        h.control(node => node.type === 'input' && node.props.name === 'transfer-direction' && node.props.value === 'buyer_return').onChange();
        let html = await render(ui.Manifests, data, 'logistics'); assert.match(html, /Accepted buyer return/); assert.match(html, /BR-OWNED/);
        h.control(node => node.type === 'input' && node.props.name === 'buyer-return-case' && String(node.props.value) === '11').onChange();
        html = await render(ui.Manifests, data, 'logistics');
        assert.equal(h.form.data.source_hub_id, '3'); assert.equal(h.form.data.destination_hub_id, '2'); assert.equal(h.form.data.buyer_return_case_id, '11');
        const hubs = [...html.matchAll(/<input\b[^>]*role="combobox"[^>]*>/g)].map(match => match[0]);
        assert.equal(hubs.length, 2); assert.ok(hubs.every(input => /\bdisabled(?:=|\s|>)/.test(input)));
        h.control(node => node.type === 'input' && node.props.name === 'transfer-vehicle' && String(node.props.value) === '5').onChange();
        h.submit();
        assert.equal(h.posts[0].url, '/hub/manifests'); assert.equal(h.posts[0].data.direction, 'buyer_return');
        assert.equal(h.posts[0].data.buyer_return_case_id, '11'); assert.equal(h.posts[0].data.creation_token, 'creation-token');
        assert.equal(h.posts[0].data.vehicle_id, '5');
    } finally { delete globalThis.returnHarness; }
});

const returnMoney = { adjustment: { gross_cents: 45000, commission_cents: 4500, shop_discount_cents: 0, platform_discount_cents: 0,
    buyer_cents: 45000, seller_cents: 40500, platform_cents: 4500 }, actions: ['record-repayment'], history: [],
    backing: { verified: true, available_cents: 9000, reconciled_cents: 95000, received_cents: 0, outgoing_cents: 0,
        seller_paid_cents: 81000, protected_seller_cents: 0, protected_shipping_cents: 5000 } };

test('repayment separates original entitlement, actual backing and transfer offers from received money', async () => {
    const html = await render(ui.Money, { value: returnMoney, caseId: 11, version: 8 }, 'admin');
    assert.match(html, /Buyer repayment due/); assert.match(html, /450\.00/); assert.match(html, /Seller already paid/);
    assert.match(html, /Awaiting confirmed backing/); assert.match(html, /Shipping protected/);
    assert.match(html, /Approval and transfer offers do not confirm money received/);
    assert.doesNotMatch(html, /Refund paid|<input[^>]+name="buyer_id"/);
});

test('repayment requires a bounded receipt, posts no client entitlement and preserves failed-request identity', async () => {
    const h = globalThis.returnHarness = new Harness();
    try {
        await render(ui.Money, { value: returnMoney, caseId: 11, version: 8 }, 'admin');
        h.control(node => node.type === 'select').onChange({ target: { value: 'record-repayment' } });
        await render(ui.Money, { value: returnMoney, caseId: 11, version: 8 }, 'admin');
        h.form.data.proof = { type: 'video/mp4', size: 20 }; h.submit(); assert.equal(h.posts.length, 0);
        h.form.data.proof = { type: 'image/png', size: 5242881 }; h.submit(); assert.equal(h.posts.length, 0);
        h.form.data.proof = { type: 'image/png', size: 5242880 }; h.form.data.reason = 'The actual transfer and original buyer were checked.';
        h.form.data.cash_reference = 'PAYMENT-ORIGINAL'; h.form.data.payment_confirmed = true;
        let transform; h.form.transform = fn => { transform = fn; };
        const post = h.form.post; h.form.post = (url, options) => { const actual = transform(h.form.data); post(url, options); h.posts.at(-1).data = structuredClone(actual); };
        h.submit(); h.submit(); assert.equal(h.posts.length, 1); assert.equal(h.posts[0].url, '/buyer-returns/11/money');
        const saved = h.posts[0].data; assert.equal(saved.expected_version, 8); assert.equal(saved.cash_reference, 'PAYMENT-ORIGINAL');
        for (const field of ['amount', 'recipient_id', 'buyer_id', 'seller_cents']) assert.equal(field in saved, false);
        h.form.processing = false; h.posts[0].options.onFinish(); h.submit();
        assert.equal(h.posts[1].data.request_token, saved.request_token);
    } finally { delete globalThis.returnHarness; }
});

test('incoming receipt selects the actual named offer while buyer confirmation uses only original recorded payment', async () => {
    const h = globalThis.returnHarness = new Harness();
    try {
        const value = { ...returnMoney, actions: ['receive-recovery'], pending_offers: [{ id: 19, event_type: 'offer-recovery', amount_cents: 40500, cash_reference: 'RECOVERY-019' }] };
        await render(ui.Money, { value, caseId: 11, version: 9 }, 'admin');
        h.control(node => node.type === 'select').onChange({ target: { value: 'receive-recovery' } });
        const html = await render(ui.Money, { value, caseId: 11, version: 9 }, 'admin');
        assert.match(html, /RECOVERY-019/); assert.match(html, /405\.00/); assert.doesNotMatch(html, /Actual transfer amount \(PHP\)/);
    } finally { delete globalThis.returnHarness; }
    const buyer = { ...returnMoney, actions: ['confirm-receipt', 'report-nonreceipt'], history: [{ id: 20, reference: 'BRM-20', event_type: 'record-repayment', amount_cents: 45000,
        cash_reference: 'PAY-20', reason: 'The transfer was made to the original buyer.', source_event_id: null, proof_url: '/buyer-returns/11/money/20/proof', created_at: '2026-10-19T00:00:00Z' }] };
    const html = await render(ui.Money, { value: buyer, caseId: 11, version: 10 });
    assert.match(html, /I received the repayment/); assert.match(html, /Report missing repayment/); assert.match(html, /Download private receipt/);
});
