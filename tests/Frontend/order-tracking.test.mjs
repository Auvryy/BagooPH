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
        export { buyerMilestones, originalBuyerJourney, buyerJourneyEntries, buyerJourneyException } from '@/utils/buyerParcelProgress';
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

test('five buyer milestones group recorded shipping without inventing preparation or losing Mother Hub visits', () => {
    const history = [
        { id: 1, type: 'courier_pickup', label: 'Collected', location: 'Shop', created_at: null },
        { id: 2, type: 'arrived_at_mother_hub', label: 'Mother Hub received', location: 'Origin Mother Hub', created_at: null },
        { id: 3, type: 'arrived_at_mother_hub', label: 'Mother Hub received', location: 'Destination Mother Hub', created_at: null },
    ];
    const data = { commercial_status: 'at_sorting_center', parcel_status: 'arrived_at_mother_hub', history };
    const steps = ui.buyerMilestones(data);
    assert.deepEqual(steps.map(step => step.key), ['placed', 'packing', 'shipped', 'received', 'review']);
    assert.equal(steps.find(step => step.current)?.key, 'shipped');
    assert.equal(steps.find(step => step.key === 'packing').complete, false);
    assert.equal(steps.find(step => step.key === 'packing').missing, true);
    assert.equal(steps.find(step => step.key === 'received').recorded, false);
    assert.equal(ui.originalBuyerJourney(data).length, 3);
    assert.equal(ui.originalBuyerJourney({ ...data, history: [...history, history[1]] }).length, 3);
});

test('cancellation, failed attempts and returns never imply delivery or receipt', () => {
    for (const state of [
        { commercial_status: 'cancelled', parcel_status: 'cancelled', exception: 'Order cancelled' },
        { commercial_status: 'delivery_failed', parcel_status: 'delivery_failed', exception: 'Delivery issue' },
        { commercial_status: 'delivery_failed', parcel_status: 'return_in_transit', exception: 'Return in progress' },
        { commercial_status: 'returned', parcel_status: 'returned', exception: 'Returned to seller' },
    ]) {
        const steps = ui.buyerMilestones({ ...state, history: [] });
        assert.equal(steps.length, 5);
        assert.equal(steps.find(step => step.current), undefined);
        assert.equal(ui.buyerJourneyException(state), state.exception);
        assert.equal(steps.find(step => step.key === 'received').recorded, false);
        assert.equal(steps.find(step => step.key === 'review').complete, false);
    }
    const pickup = ui.buyerMilestones({ commercial_status: 'sorted', parcel_status: 'ready_for_hub_pickup', delivery_type: 'hub_self_pickup', history: [] });
    assert.equal(pickup.find(step => step.current).key, 'shipped');
    assert.equal(pickup.find(step => step.key === 'received').recorded, false);
});

test('the buyer rail has circular current-step markup without restyling the shared default panel', () => {
    const data = { commercial_status: 'picked_up', parcel_status: 'picked_up', status_label: 'Picked up', stage_label: 'Collected from seller', history: [
        { id: 1, type: 'courier_pickup', label: 'Collected from seller', location: 'Bagoo shop', created_at: null },
    ] };
    const buyer = renderToStaticMarkup(createElement(ui.Panel, { tracking: data, variant: 'buyer' }));
    assert.match(buyer, /aria-label="Order milestones"/);
    assert.equal((buyer.match(/data-milestone=/g) ?? []).length, 5);
    assert.match(buyer, /aria-current="step"/);
    assert.match(buyer, /rounded-full/);
    assert.match(buyer, /bg-amber-300/);
    assert.match(buyer, /<summary[^>]*>[\s\S]*Collected from seller[\s\S]*<\/summary>/);
    assert.match(buyer, /Journey checkpoints \(1\)/);
    assert.match(buyer, /Checkpoint not recorded|Not recorded/);
    assert.doesNotMatch(buyer, /Recorded parcel tracking|Next step/);
    assert.doesNotMatch(renderToStaticMarkup(createElement(ui.Panel, { tracking: data })), /aria-label="Order milestones"/);
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

test('buyer detail groups actual custody and Mother Hub history under the compact five-step progress', async () => {
    const html = await render(ui.Detail, detailProps);
    assert.match(html, />Confirmed</);
    assert.doesNotMatch(html, /Confirmed \/ packed/);
    assert.match(html, /Pickup not assigned/);
    assert.equal((html.match(/data-milestone=/g) ?? []).length, 5);
    assert.match(html, /Recorded parcel holder/);
    assert.match(html, /Seller \(handover recorded\)/);
    assert.match(html, /Received at Mother Hub/);
    assert.match(html, /Laguna Mother Hub/);
    assert.match(html, /Refresh tracking/);
    assert.doesNotMatch(html, /Awaiting Courier|Received at Doorstep|Live Logistics Tracking/);
    assert.doesNotMatch(html, /Next step|Assigned Courier Fleet|Order notices/);
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

const point = (id, type, label = type, location = 'Bagoo sorting hub') => ({
    id, type, label, location, created_at: `2026-10-11T0${id}:00:00Z`,
});
const parcelNotice = (id, checkpoint, date = '2026-10-11T03:00:00Z') => ({
    id, type: 'parcel-event', checkpoint_id: checkpoint, milestone: null,
    data: { title: 'The parcel reached a sorting hub' }, read_at: null, created_at: date,
});

test('pickup assignment is preparation rather than proof of shipment', () => {
    const data = { ...tracking, placed_at: order.created_at, commercial_status: 'ready_for_pickup', parcel_status: 'assigned_pickup',
        history: [point(1, 'seller_pack'), point(2, 'assigned_pickup')] };
    const steps = ui.buyerMilestones(data);
    assert.equal(steps.length, 5);
    assert.equal(steps[0].complete, true);
    assert.equal(steps.find(step => step.current).key, 'packing');
    assert.equal(steps[2].recorded, false);
    assert.equal(steps[2].complete, false);
    const collected = ui.buyerMilestones({ ...data, commercial_status: 'picked_up', parcel_status: 'picked_up',
        history: [...data.history, point(3, 'picked_up')] });
    assert.equal(collected[1].complete, true);
    assert.equal(collected[2].current, true);
    assert.equal(collected[2].recorded, true);
    assert.equal(collected[3].complete, false);
});

test('buyer receipt and all item reviews are separate fourth and fifth milestone outcomes', () => {
    const events = [point(1, 'confirmed'), point(2, 'picked_up'), point(3, 'delivered')];
    const data = { ...tracking, placed_at: order.created_at, commercial_status: 'delivered', parcel_status: 'delivered', history: events };
    const delivered = ui.buyerMilestones(data);
    assert.equal(delivered[3].current, true);
    assert.equal(delivered[3].complete, false);
    assert.equal(delivered[4].current, false);
    const completed = { ...data, commercial_status: 'completed', history: [...events, point(4, 'buyer_completed')] };
    const incompleteReview = { ...order, status: 'completed', items: [{ id: 1, review: { id: 10 } }, { id: 2, review: null }] };
    const waiting = ui.buyerMilestones(completed, incompleteReview);
    assert.equal(waiting[3].complete, true);
    assert.equal(waiting[4].current, true);
    assert.equal(waiting[4].complete, false);
    const reviewed = ui.buyerMilestones(completed, { ...incompleteReview, items: [{ id: 1, review: { id: 10 } }, { id: 2, review: { id: 11 } }] });
    assert.equal(reviewed.every(step => step.complete), true);
    assert.equal(reviewed.some(step => step.current), false);
    assert.equal(reviewed[4].label, 'Review saved');
    assert.equal(ui.buyerMilestones(completed, { ...order, items: [] })[4].complete, false);
    assert.equal(ui.buyerMilestones({ ...data, commercial_status: 'completed' }, incompleteReview)[3].complete, false);
    const legacy = ui.buyerMilestones({ ...completed, history: [] }, { ...incompleteReview, items: [{ id: 1, review: { id: 10 } }] });
    assert.equal(legacy[2].complete, false);
    assert.equal(legacy[2].missing, true);
});

test('counter collection follows the same five stages and still waits for explicit buyer receipt', () => {
    const data = { ...tracking, delivery_type: 'hub_self_pickup', commercial_status: 'delivered', parcel_status: 'customer_collected',
        history: [point(1, 'picked_up'), point(2, 'ready_for_hub_pickup'), point(3, 'customer_collected', 'Collected at hub counter')] };
    const steps = ui.buyerMilestones(data);
    assert.equal(steps.length, 5);
    assert.equal(steps[3].recorded, true);
    assert.equal(steps[3].current, true);
    assert.equal(steps[3].complete, false);
    assert.equal(steps[4].current, false);
    assert.equal(ui.originalBuyerJourney(data).at(-1).label, 'Collected at hub counter');
});

test('exact notice sources merge into their own repeated hub visits without changing checkpoint order or read state', () => {
    const points = [point(1, 'arrived_at_mother_hub', 'Mother Hub received', 'Origin Mother Hub'),
        point(2, 'arrived_at_mother_hub', 'Mother Hub received', 'Destination Mother Hub')];
    const notices = [parcelNotice('notice-b', 2), parcelNotice('notice-a', 1), parcelNotice('notice-a', 1)];
    const before = JSON.stringify({ points, notices });
    const entries = ui.buyerJourneyEntries({ ...tracking, history: [...points, points[0]] }, notices);
    assert.equal(entries.length, 2);
    assert.deepEqual(entries.map(entry => entry.location), ['Origin Mother Hub', 'Destination Mother Hub']);
    assert.deepEqual(entries.map(entry => entry.notices.map(notice => notice.id)), [['notice-a'], ['notice-b']]);
    assert.equal(entries.flatMap(entry => entry.notices).every(notice => notice.read_at === null), true);
    assert.equal(JSON.stringify({ points, notices }), before);
});

test('order notices with a unique original milestone share one row while ambiguous and legacy updates stay retained', () => {
    const ready = point(3, 'ready_for_pickup', 'Parcel ready for pickup');
    const notice = { ...parcelNotice('ready', null), type: 'order-event', milestone: 'ready_for_pickup',
        data: { title: 'Your order is ready for pickup' } };
    const merged = ui.buyerJourneyEntries({ ...tracking, history: [ready] }, [notice]);
    assert.equal(merged.length, 1);
    assert.equal(merged[0].checkpoint.id, 3);
    assert.equal(merged[0].notices[0].id, 'ready');
    const legacy = { ...notice, id: 'legacy', type: 'parcel-event', milestone: 'arrived_at_mother_hub' };
    const events = [point(1, 'arrived_at_mother_hub'), point(2, 'arrived_at_mother_hub')];
    assert.equal(ui.buyerJourneyEntries({ ...tracking, history: events }, [legacy]).length, 3);
    assert.equal(ui.buyerJourneyEntries({ ...tracking, history: [ready, { ...ready, id: 4 }] }, [notice]).length, 3);
    assert.equal(ui.buyerJourneyEntries({ ...tracking, history: [ready] }, [{ ...notice, milestone: 'constructor' }]).length, 2);
});

test('journey opens by default and completed notice-only updates are red without inventing parcel movement', () => {
    const data = { ...tracking, commercial_status: 'ready_for_pickup', parcel_status: 'assigned_pickup',
        stage_label: 'Pickup rider assigned', history: [point(1, 'ready_for_pickup')] };
    const notice = { ...parcelNotice('confirmed', null), type: 'order-event', milestone: 'confirmed',
        data: { title: 'Your order was confirmed' } };
    const html = renderToStaticMarkup(createElement(ui.Panel, { tracking: data, variant: 'buyer', notices: [notice] }));
    assert.match(html, /<details open="" class="group\/journey/);
    assert.doesNotMatch(html, /See more/);
    const summary = html.match(/<summary[^>]*>([\s\S]*?)<\/summary>/)[1];
    assert.match(summary, /Pickup rider assigned/);
    assert.match(summary, /1 unread/);
    assert.ok(html.indexOf('Your order was confirmed') > html.indexOf('</summary>'));
    assert.match(html, /Mark read/);
    const past = html.match(/<li[^>]*data-journey-entry="notice-confirmed"[\s\S]*?<\/li>/)[0];
    assert.match(past, /border-\[#E00D42\] bg-\[#E00D42\]/);
    assert.doesNotMatch(past, /border-slate-300 bg-white/);
    const current = html.match(/<li[^>]*data-journey-entry="checkpoint-1"[\s\S]*?<\/li>/)[0];
    assert.match(current, /border-amber-500 bg-amber-300/);
    assert.equal(ui.buyerMilestones(data)[2].recorded, false);
});

test('zero to five journey entries stay visible without an unnecessary See more control', () => {
    for (const count of [0, 1, 5]) {
        const history = Array.from({ length: count }, (_, index) => point(index + 1, 'arrived_at_mother_hub', `Hub visit ${index + 1}`));
        const html = renderToStaticMarkup(createElement(ui.Panel, { tracking: { ...tracking, history }, variant: 'buyer' }));
        assert.match(html, /<details open="" class="group\/journey/);
        assert.doesNotMatch(html, /See more|Earlier parcel journey/);
        assert.equal((html.match(/data-journey-entry=/g) ?? []).length, count);
        if (count === 0) assert.match(html, /No journey checkpoints or order updates are recorded yet/);
    }
});

test('See more retains older visits and unread actions while the latest five updates and current scan stay open', () => {
    for (const count of [6, 8]) {
        const history = Array.from({ length: count }, (_, index) => point(index + 1, 'arrived_at_mother_hub', `Hub visit ${index + 1}`));
        const notices = [parcelNotice('first-visit', 1), parcelNotice('last-visit', count)];
        const html = renderToStaticMarkup(createElement(ui.Panel, { tracking: { ...tracking, history }, notices, variant: 'buyer' }));
        const earlier = html.match(/<details class="group\/earlier"[\s\S]*?<\/details>/)[0];
        assert.doesNotMatch(earlier, /<details[^>]*\bopen(?:=|>)/);
        assert.match(earlier, /See more/);
        assert.match(earlier, /Mark read/);
        assert.equal((earlier.match(/data-journey-entry=/g) ?? []).length, count - 5);
        const recent = html.match(/<ol aria-label="Original parcel journey and order updates"[^>]*>[\s\S]*?<\/ol>/)[0];
        assert.equal((recent.match(/data-journey-entry=/g) ?? []).length, 5);
        assert.match(recent, new RegExp(`start="${count - 4}"`));
        assert.match(recent, new RegExp(`data-journey-entry="checkpoint-${count}"`));
        assert.match(recent, /border-amber-500 bg-amber-300/);
        assert.match(recent, /Mark read/);
        for (let id = 1; id <= count; id++) assert.equal((html.match(new RegExp(`data-journey-entry="checkpoint-${id}"`, 'g')) ?? []).length, 1);
        assert.ok(html.indexOf(earlier) < html.indexOf(recent));
        assert.equal(notices.every(notice => notice.read_at === null), true);
    }
});

test('completed circles are red, the current circle is yellow and a pending product review has one accessible action', () => {
    const data = { ...tracking, placed_at: order.created_at, commercial_status: 'completed', parcel_status: 'delivered',
        history: [point(1, 'confirmed'), point(2, 'picked_up'), point(3, 'delivered'), point(4, 'buyer_completed')] };
    const pending = { ...order, status: 'completed', items: [{ id: 1, review: null }] };
    const html = renderToStaticMarkup(createElement(ui.Panel, { tracking: data, buyerOrder: pending, variant: 'buyer', onReview() {} }));
    const rail = html.match(/<ol aria-label="Order milestones"[^>]*>([\s\S]*?)<\/ol>/)[1];
    assert.equal((rail.match(/border-\[#E00D42\] bg-\[#E00D42\] text-white/g) ?? []).length, 4);
    assert.equal((rail.match(/border-amber-500 bg-amber-300/g) ?? []).length, 1);
    assert.match(rail, /aria-current="step" data-milestone="review"/);
    assert.match(rail, /Write a product review/);
    assert.equal((rail.match(/<button /g) ?? []).length, 1);
    assert.match(renderToStaticMarkup(createElement(ui.Panel, { tracking: data, buyerOrder: pending, variant: 'buyer', onReview() {}, refreshing: true })), /<button[^>]*disabled=""/);
});

test('tracking precedes package items with red prices and grouped courier, full addresses and notes', async () => {
    const html = await render(ui.Detail, { ...detailProps, order: { ...order,
        items: [{ id: 10, quantity: 2, unit_price: 300, color: 'Blue', size: 'Large', product: { name: 'Everyday Bag', slug: 'everyday-bag' } }],
        delivery: { ...order.delivery, logistics_partner: 'Bagoo Dispatch', pickup_store_name: 'Bagoo Crafts', pickup_address: '12 Mabini Street, Manila',
            delivery_recipient_name: 'Ana Santos', delivery_address: '45 Sampaguita Street, Pasig', courier: { name: 'Marco Cruz' }, courier_notes: 'Please call on arrival.' } } });
    const start = html.indexOf('<section aria-label="Package items"');
    const end = html.indexOf('</section>', start);
    const packageItems = html.slice(start, end);
    const progress = html.indexOf('<section aria-label="Order progress"');
    assert.ok(progress > -1 && start > progress && end > start);
    for (const text of ['Everyday Bag', 'Bagoo Dispatch', 'Marco Cruz', '12 Mabini Street, Manila', '45 Sampaguita Street, Pasig', 'Please call on arrival.', 'BGO-PARCEL', 'Blue / Large']) assert.ok(packageItems.includes(text), text);
    assert.match(packageItems, /<summary[^>]*>[\s\S]*Delivery details[\s\S]*Bagoo Dispatch[\s\S]*<\/summary>/);
    assert.match(packageItems, /<p class="[^"]*text-\[#E00D42\][^"]*">₱600\.00<\/p>/);
    assert.match(packageItems, /Total amount<\/span><span class="[^"]*text-\[#E00D42\][^"]*">₱650\.00/);
    assert.doesNotMatch(packageItems, /truncate|src=""/);
    assert.doesNotMatch(html, /Assigned Courier Fleet|Next step/);
});

test('only approved completed purchases expose the fifth-step review action and saved items retain their edit controls', async () => {
    const item = { id: 10, product_id: 3, quantity: 1, unit_price: 300, product: { name: 'Everyday Bag', slug: 'everyday-bag' } };
    const completed = { ...order, status: 'completed', items: [item], tracking: { ...tracking, commercial_status: 'completed' } };
    assert.match(await render(ui.Detail, { ...detailProps, order: completed }), /Write a product review/);
    assert.doesNotMatch(await render(ui.Detail, { ...detailProps, order: completed, canUsePortal: false }), /Write a product review/);
    const earlier = { ...completed, status: 'delivered', tracking: { ...tracking, commercial_status: 'delivered' } };
    assert.doesNotMatch(await render(ui.Detail, { ...detailProps, order: earlier }), /Write a product review/);
    const reviewed = { ...completed, items: [{ ...item, review: { id: 1, edit_url: '/buyer/reviews/1', rating: 4, comment: 'Arrived safely.' } }] };
    const saved = await render(ui.Detail, { ...detailProps, order: reviewed });
    assert.match(saved, /Review saved/);
    assert.match(saved, /Edit review/);
    assert.doesNotMatch(saved, /Write a product review/);
});
