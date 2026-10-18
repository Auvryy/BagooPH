import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createInertiaApp, router } from '@inertiajs/react';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-order-tracking-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const bundle = await build({
    stdin: { contents: `export { default as Panel, trackingDate } from '@/Components/OrderTrackingPanel';
        export { buyerMilestones, originalBuyerJourney } from '@/utils/buyerParcelProgress';
        export { default as Detail } from '@/Pages/Buyer/OrderDetail';
        export { default as RestrictedOrders } from '@/Pages/Buyer/Orders';
        export { default as PublicTracking } from '@/Pages/Public/Tracking';
        export { default as SellerOrders } from '@/Pages/Seller/Orders';
        export { claimWindow, nextClaimBoundary, currentOrderSelection, currentCourierAction } from '@/utils/orderTracking';`,
        resolveDir: resolve(import.meta.dirname, '../..'), loader: 'tsx' },
    bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', loader: { '.css': 'empty' }, write: false,
    plugins: [{ name: 'retained-seller-selection', setup(builder) {
        builder.onResolve({ filter: /OrderTrackingPanel$/ }, args => args.importer.endsWith('/Pages/Buyer/OrderDetail.tsx')
            ? { path: 'refresh-panel', namespace: 'refresh-panel' } : undefined);
        builder.onLoad({ filter: /.*/, namespace: 'refresh-panel' }, () => ({ contents: `
            import { createElement } from 'react';
            import Panel from '@/Components/OrderTrackingPanel';
            export * from '@/Components/OrderTrackingPanel';
            export default function Capture(props) { globalThis.orderTrackingRefresh = props.onRefresh; return createElement(Panel, props); }
        `, resolveDir: resolve(import.meta.dirname, '../..') }));
        builder.onResolve({ filter: /^react$/ }, args => args.namespace === 'selection-hooks'
            ? { path: 'react', external: true } : { path: 'selection-hooks', namespace: 'selection-hooks' });
        builder.onLoad({ filter: /.*/, namespace: 'selection-hooks' }, () => ({ contents: `
            import React from 'react'; export * from 'react'; export default React;
            export const useState = initial => React.useState(() => {
                const retained = globalThis.orderTrackingSelection;
                const index = retained ? retained.index++ : -1;
                return retained && index < 5 ? retained.values[index] : (typeof initial === 'function' ? initial() : initial);
            });
        ` }));
        builder.onResolve({ filter: /^react-dom$/ }, args => args.namespace === 'selection-portal'
            ? { path: 'react-dom', external: true } : { path: 'selection-portal', namespace: 'selection-portal' });
        builder.onLoad({ filter: /.*/, namespace: 'selection-portal' }, () => ({ contents: "export * from 'react-dom'; export const createPortal = children => children;" }));
    } }],
});
const path = join(temporary, 'tracking.mjs');
writeFileSync(path, bundle.outputFiles[0].text);
const ui = await import(pathToFileURL(path).href);
globalThis.route = (name, parameter) => name ? `/${name.replaceAll('.', '/')}${parameter ? `/${typeof parameter === 'object' ? parameter.id : parameter}` : ''}` : { current: () => false, has: () => true };
after(() => delete globalThis.route);
after(() => delete globalThis.orderTrackingRefresh);

const now = Date.now();
const iso = time => new Date(time).toISOString();

test('buyer milestones show only recorded scans and retain every Mother Hub checkpoint', () => {
    const history = [
        { id: 1, type: 'courier_pickup', label: 'Collected', location: 'Shop', created_at: null },
        { id: 2, type: 'arrived_at_mother_hub', label: 'Mother Hub received', location: 'Origin Mother Hub', created_at: null },
        { id: 3, type: 'arrived_at_mother_hub', label: 'Mother Hub received', location: 'Destination Mother Hub', created_at: null },
    ];
    const data = { commercial_status: 'at_sorting_center', parcel_status: 'arrived_at_mother_hub', history };
    const steps = ui.buyerMilestones(data);
    assert.equal(steps.find(step => step.current)?.key, 'mother');
    assert.equal(steps.find(step => step.key === 'origin').recorded, false);
    assert.equal(steps.find(step => step.key === 'handover').recorded, false);
    assert.equal(ui.originalBuyerJourney(data).length, 3);
    assert.equal(ui.originalBuyerJourney({ ...data, history: [...history, history[1]] }).length, 3);
});

test('cancellation, failed attempts and returns never imply delivery or receipt', () => {
    for (const state of [
        { commercial_status: 'cancelled', parcel_status: 'cancelled', current: 'cancelled' },
        { commercial_status: 'delivery_failed', parcel_status: 'delivery_failed', current: 'issue' },
        { commercial_status: 'delivery_failed', parcel_status: 'return_in_transit', current: 'returning' },
        { commercial_status: 'returned', parcel_status: 'returned', current: 'returned' },
    ]) {
        const steps = ui.buyerMilestones({ ...state, history: [] });
        assert.equal(steps.find(step => step.current)?.key, state.current);
        assert.equal(steps.find(step => step.key === 'handover').recorded, false);
        assert.equal(steps.find(step => step.key === 'receipt').recorded, false);
    }
    const pickup = ui.buyerMilestones({ commercial_status: 'sorted', parcel_status: 'ready_for_hub_pickup', delivery_type: 'hub_self_pickup', history: [] });
    assert.equal(pickup.find(step => step.key === 'last_mile').label, 'Hub collection');
    assert.equal(pickup.find(step => step.key === 'handover').label, 'Collected');
});

test('the buyer rail has circular current-step markup without restyling the shared default panel', () => {
    const data = { commercial_status: 'picked_up', parcel_status: 'picked_up', status_label: 'Picked up', stage_label: 'Collected from seller', history: [
        { id: 1, type: 'courier_pickup', label: 'Collected from seller', location: 'Bagoo shop', created_at: null },
    ] };
    const buyer = renderToStaticMarkup(createElement(ui.Panel, { tracking: data, variant: 'buyer' }));
    assert.match(buyer, /aria-label="Parcel milestones"/);
    assert.match(buyer, /aria-current="step"/);
    assert.match(buyer, /rounded-full/);
    assert.match(buyer, /Last recorded location/);
    assert.match(buyer, /Journey checkpoints \(1\)/);
    assert.match(buyer, /Checkpoint not recorded|Not recorded/);
    assert.doesNotMatch(renderToStaticMarkup(createElement(ui.Panel, { tracking: data })), /aria-label="Parcel milestones"/);
});
const claim = { reference: 'PUC-TRACKING', status: 'ready', expires_at: iso(now + 60_000), locked_until: null, code_issued_at: null };
const tracking = { commercial_status: 'preparing', status_label: 'Seller preparing', stage_label: 'Pickup not assigned', tone: 'progress',
    custodian: 'Seller (handover recorded)', custody_recorded_at: '2026-10-11T00:00:00Z',
    next_step: 'The seller must finish preparation and mark the parcel ready for pickup.', responsible: 'Seller', notice: null,
    observed_at: '2026-10-11T03:00:00Z', failure: null,
    history: [{ id: 1, label: 'Seller preparation recorded', location: 'Bagoo Crafts', created_at: '2026-10-11T00:00:00Z' },
        { id: 2, label: 'Received at Mother Hub', location: 'Laguna Mother Hub', created_at: '2026-10-11T01:00:00Z' }] };
const order = { id: 40, buyer_id: 7, order_number: 'BGO-TRACKING', status: 'preparing', total_amount: 650, subtotal: 600, shipping_fee: 50,
    payment_method: 'cod', payment_status: 'pending', created_at: '2026-10-11T00:00:00Z', items: [], tracking,
    buyer: { id: 7, name: 'Ana Santos' }, delivery: { id: 20, status: 'unassigned', tracking_number: 'BGO-PARCEL' } };

async function render(Component, props, role = 'buyer', retained = null) {
    const previousDocument = globalThis.document;
    if (retained) {
        globalThis.orderTrackingSelection = { index: 0, values: retained };
        globalThis.document = { body: { style: {} } };
    }
    try {
        let view;
        const response = await createInertiaApp({
            page: { component: 'OrderTracking', props: { auth: { user: { id: 7, name: 'Ana Santos', role, email: `${role}@bagoo.test` } },
                flash: {}, errors: {}, cartCount: 0, ...props }, url: '/buyer/orders/40', version: null },
            resolve: () => Component, setup: ({ App, props }) => { view = createElement(App, props); return view; }, render: () => renderToStaticMarkup(view),
        });
        return response.body;
    } finally {
        delete globalThis.orderTrackingSelection;
        if (previousDocument === undefined) delete globalThis.document;
        else globalThis.document = previousDocument;
    }
}

const detailProps = { order, canUsePortal: true, canConfirmReceipt: false, pickupClaim: null, pickupHub: null, pickupCodeUrl: '/buyer/orders/40/pickup-code', pickupCodeAvailable: true, orderNotices: [] };
const sellerProps = rows => ({ orders: { data: rows, current_page: 1, last_page: 1, per_page: 10, total: rows.length },
    shop: { id: 5, name: 'Bagoo Crafts' }, shopEligible: true, returnReceiptToken: 'receipt-token', currentStatus: 'all', counts: { all: rows.length }, cancellationReasons: ['Other reason'], batchLimit: 50 });

test('buyer detail shows preparation, actual recorded custody and the Mother Hub journey without an inferred milestone bar', async () => {
    const html = await render(ui.Detail, detailProps);
    assert.match(html, /Seller preparing/);
    assert.match(html, /finish preparation and mark the parcel ready/);
    assert.match(html, /Recorded parcel holder/);
    assert.match(html, /Seller \(handover recorded\)/);
    assert.match(html, /Received at Mother Hub/);
    assert.match(html, /Laguna Mother Hub/);
    assert.match(html, /Refresh tracking/);
    assert.doesNotMatch(html, /Awaiting Courier|Received at Doorstep|Live Logistics Tracking/);
});

test('buyer refresh requests current portal access, receipt and code actions and ignores a duplicate click', async () => {
    await render(ui.Detail, detailProps);
    const previousReload = router.reload;
    const visits = [];
    router.reload = options => visits.push(options);
    try {
        globalThis.orderTrackingRefresh();
        globalThis.orderTrackingRefresh();
        assert.equal(visits.length, 1);
        for (const key of ['order', 'canUsePortal', 'buyAgainUrl', 'canConfirmReceipt', 'pickupClaim', 'pickupCodeAvailable', 'pickupCodeUrl', 'orderNotices', 'auth']) {
            assert.ok(visits[0].only.includes(key), `Refresh must include ${key}`);
        }
        visits[0].onFinish();
        globalThis.orderTrackingRefresh();
        assert.equal(visits.length, 2);
    } finally { router.reload = previousReload; }
});

test('exception tracking retains history and names the next actor without restarting ordinary preparation', () => {
    const html = renderToStaticMarkup(createElement(ui.Panel, { tracking: { ...tracking, commercial_status: 'delivery_failed', status_label: 'Delivery issue',
        stage_label: 'Travelling on the return route', tone: 'attention', custodian: 'Hub transport (handover recorded)',
        responsible: 'Receiving hub', next_step: 'Await the next recorded return receipt.',
        failure: { attempt_number: 2, reason: 'Customer unreachable', attempted_at: '2026-10-11T02:00:00Z', retry_at: null } } }));
    assert.match(html, /Delivery issue/);
    assert.match(html, /Receiving hub/);
    assert.match(html, /Recorded attempt 2: Customer unreachable/);
    assert.match(html, /Received at Mother Hub/);
    assert.doesNotMatch(html, /Approved retry|Waiting for Merchant/);
});

test('a scheduled retry displays its retained date in Philippine time', () => {
    const html = renderToStaticMarkup(createElement(ui.Panel, { tracking: { ...tracking,
        failure: { attempt_number: 1, reason: 'Customer unreachable', attempted_at: null, retry_at: '2026-10-11T04:30:00Z' } } }));
    assert.match(html, /Approved retry: Oct 11, 2026, 12:30 PM/);
    assert.match(html, /Philippine time/);
    assert.equal(ui.trackingDate('invalid'), 'Not recorded');
    assert.equal(ui.trackingDate(null), 'Not recorded');
});

test('refresh errors and missing records remain visible without fabricated journey rows', () => {
    const html = renderToStaticMarkup(createElement(ui.Panel, { onRefresh: () => {}, refreshing: true, error: 'Unable to refresh recorded tracking.' }));
    assert.match(html, /Unable to refresh recorded tracking/);
    assert.match(html, /Tracking details are not loaded/);
    assert.match(html, /disabled=""/);
    assert.doesNotMatch(html, /Original parcel journey/);
});

test('the one-time buyer code appears only for a current ready and unissued claim', async () => {
    const html = await render(ui.Detail, { ...detailProps, pickupClaim: claim });
    assert.match(html, /Show my code once/);
    const issued = await render(ui.Detail, { ...detailProps, pickupClaim: { ...claim, code_issued_at: iso(now) }, pickupCodeAvailable: false });
    assert.match(issued, /Your code was already shown/);
    assert.doesNotMatch(issued, /Show my code once/);
});

for (const [name, changes, message] of [
    ['elapsed expiry', { expires_at: iso(now - 1) }, /collection deadline has passed/],
    ['future lock', { locked_until: iso(now + 30_000) }, /Counter verification is locked/],
    ['recorded collection', { status: 'collected' }, /Collection is recorded/],
    ['recorded expiry', { status: 'expired' }, /collection deadline has passed/],
    ['invalid expiry', { expires_at: 'invalid' }, /collection deadline has passed/],
]) test(`${name} suppresses code access even when earlier props claimed availability`, async () => {
    const html = await render(ui.Detail, { ...detailProps, pickupClaim: { ...claim, ...changes } });
    assert.match(html, message);
    assert.doesNotMatch(html, /Show my code once/);
});

test('completed counter orders acknowledge both collection and confirmation without asking for receipt again', async () => {
    const html = await render(ui.Detail, { ...detailProps, order: { ...order, status: 'completed', tracking: { ...tracking,
        commercial_status: 'completed', status_label: 'Buyer confirmed receipt', stage_label: 'Collected at hub counter' } }, pickupClaim: { ...claim, status: 'collected' } });
    assert.match(html, /Collection and your receipt confirmation are recorded/);
    assert.match(html, /Collected at hub counter/);
    assert.doesNotMatch(html, /Confirm receipt after checking your items|Received at Doorstep/);
});

test('claim boundary checks handle expiry and unlock at the exact current clock', () => {
    assert.equal(ui.claimWindow(claim, now + 60_000), 'expired');
    assert.equal(ui.claimWindow({ ...claim, locked_until: iso(now + 30_000) }, now + 30_000), 'ready');
    assert.equal(ui.nextClaimBoundary({ ...claim, locked_until: iso(now - 1) }, now + 59_000), 1001);
    assert.equal(ui.nextClaimBoundary({ ...claim, locked_until: iso(now + 30_000) }, now), 30_001);
    assert.equal(ui.nextClaimBoundary({ ...claim, status: 'collected' }, now), null);
});

test('a seller detail selection renders the refreshed order and closes when it leaves the page', async () => {
    const old = { id: order.id, order: { ...order, order_number: 'STALE-ORDER', tracking: { ...tracking, stage_label: 'STALE-STAGE' } } };
    const retained = [null, null, null, old, null];
    const html = await render(ui.SellerOrders, sellerProps([order]), 'seller', retained);
    assert.match(html, /Recorded parcel tracking/);
    assert.match(html, /Order Details #BGO-TRACKING/);
    assert.match(html, /Pickup not assigned/);
    assert.doesNotMatch(html, /STALE-ORDER|STALE-STAGE/);
    const gone = await render(ui.SellerOrders, sellerProps([]), 'seller', retained);
    assert.doesNotMatch(gone, /Recorded parcel tracking|Order Details #/);
});

test('restricted buyer history uses the shared label and refresh while retaining narrow order access', async () => {
    const html = await render(ui.RestrictedOrders, { orders: { data: [{ ...order, tracking: { ...tracking, status_label: 'Delivery issue' } }], current_page: 1, last_page: 1, total: 1 },
        canUsePortal: false, currentOrderStatus: 'delivery_failed', orderCounts: { delivery_failed: 1 } });
    assert.match(html, /Delivery issue/);
    assert.match(html, /Refresh tracking/);
    assert.doesNotMatch(html, /Delivery Address Book|My Account &amp; Security|Confirm Received/);
});

for (const [status, label] of [['preparing', 'Seller preparing'], ['delivered', 'Delivered · awaiting buyer confirmation']]) {
    test(`public ${status} tracking uses server vocabulary and observed time with masked recipient details`, async () => {
        const html = await render(ui.PublicTracking, { parcel: { tracking_number: 'BGO-PARCEL', status, delivery_recipient_name: 'A*** S***',
            delivery_phone: 'Phone protected', delivery_address: 'Delivery area protected', estimated_delivery_at: null, delivered_at: null,
            checkpoints: [{ checkpoint_type: 'customer_collected', location_name: 'Destination Bayan Hub counter', created_at: '2026-10-11T01:00:00Z' }] },
            searchedNumber: 'BGO-PARCEL', notFound: false, validationMessage: null, observedAt: '2026-10-11T03:00:00Z',
            statusLabels: { [status]: label }, checkpointLabels: { customer_collected: 'Collected at hub counter' } });
        assert.ok(html.includes(label));
        assert.match(html, /Checked Oct 11, 2026, 11:00 AM/);
        assert.match(html, /Refresh tracking/);
        assert.match(html, /Collected at hub counter/);
        assert.match(html, /Phone protected/);
        assert.doesNotMatch(html, /private\/proof|Show my code once|Received at Doorstep/);
    });
}

test('a stale seller pack selection cannot retain a confirmation after the refreshed action is removed', async () => {
    const old = { id: order.id, order_id: order.id, order: { ...order, can_accept_and_pack: true } };
    const html = await render(ui.SellerOrders, sellerProps([{ ...order, can_accept_and_pack: false, can_pack: false }]), 'seller', [null, null, old, null, null]);
    assert.doesNotMatch(html, /Confirm &amp; Pack|Accept &amp; Pack Order/);
    assert.equal(ui.currentOrderSelection([], old), null);
});

test('rider outcome checks use the current task and reject removed, advanced or inconsistent assignments', () => {
    const tasks = [{ id: 2, nextAction: 'complete_delivery', tracking: { notice: null } }];
    assert.equal(ui.currentCourierAction({ id: 2, status: 'delivered' }, [], tasks), true);
    assert.equal(ui.currentCourierAction({ id: 2, status: 'delivery_failed' }, [], tasks), true);
    assert.equal(ui.currentCourierAction({ id: 2, status: 'out_for_delivery' }, [], tasks), false);
    assert.equal(ui.currentCourierAction({ id: 2, status: 'delivered' }, [], []), false);
    assert.equal(ui.currentCourierAction({ id: 2, status: 'delivered' }, [], [{ ...tasks[0], tracking: { notice: 'Handover needs review' } }]), false);
    assert.equal(ui.currentCourierAction({ id: 2, status: 'picked_up' }, [{ id: 2, nextAction: 'confirm_pickup' }], []), true);
});
