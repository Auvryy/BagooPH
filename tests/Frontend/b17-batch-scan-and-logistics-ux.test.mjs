import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createInertiaApp } from '@inertiajs/react';

const root = resolve(import.meta.dirname, '../..');
const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-b17-ux-'));
after(() => rmSync(temporary, { recursive: true, force: true }));

const interactiveBundle = await build({
    stdin: {
        contents: `
            export { default as Scanner } from '@/Pages/Hub/ScanStation';
        `,
        resolveDir: root,
        loader: 'tsx',
    },
    bundle: true,
    packages: 'external',
    platform: 'node',
    format: 'esm',
    jsx: 'automatic',
    loader: { '.css': 'empty' },
    write: false,
    plugins: [
        {
            name: 'b17-interactive-hooks',
            setup(builder) {
                builder.onResolve({ filter: /^react$/ }, () => ({ path: 'react', namespace: 'b17-hooks' }));
                builder.onLoad({ filter: /.*/, namespace: 'b17-hooks' }, () => ({
                    contents: `
                        export default {};
                        export const useState = initial => globalThis.b17Harness.state(initial);
                        export const useRef = initial => globalThis.b17Harness.ref(initial);
                        export const useEffect = (effect, deps) => globalThis.b17Harness.effect(effect, deps);
                        export const useCallback = (fn, deps) => globalThis.b17Harness.memo(fn, deps);
                        export const useMemo = (fn, deps) => globalThis.b17Harness.memoValue(fn, deps);
                    `,
                }));
                builder.onResolve({ filter: /^@inertiajs\/react$/ }, () => ({ path: 'inertia', namespace: 'b17-inertia' }));
                builder.onLoad({ filter: /.*/, namespace: 'b17-inertia' }, () => ({
                    contents: `
                        export const Head = () => null;
                        export const Link = 'a';
                        export const usePage = () => ({ props: globalThis.b17Harness.props });
                        export const usePoll = () => ({ start() {}, stop() {} });
                        export const router = {
                            on: () => () => {},
                            reload: options => { globalThis.b17Harness.reloads++; options?.onSuccess?.(); options?.onFinish?.(); },
                            get: (...args) => globalThis.b17Harness.visits.push(args),
                        };
                    `,
                }));
                builder.onResolve({ filter: /Layouts\/DashboardLayout$/ }, () => ({ path: 'layout', namespace: 'b17-layout' }));
                builder.onLoad({ filter: /.*/, namespace: 'b17-layout' }, () => ({ contents: 'export default props => props.children;' }));
            },
        },
    ],
});
const interactivePath = join(temporary, 'interactive.mjs');
writeFileSync(interactivePath, interactiveBundle.outputFiles[0].text);
const { Scanner } = await import(pathToFileURL(interactivePath).href);

const ssrBundle = await build({
    stdin: {
        contents: `
            export { default as Dashboard } from '@/Pages/Hub/Dashboard';
            export { default as Deliveries } from '@/Pages/Hub/Deliveries';
            export { default as CounterPickup } from '@/Pages/Hub/CounterPickup';
            export { default as MotherBags } from '@/Pages/Hub/MotherBags';
            export { default as Manifests } from '@/Pages/Hub/Manifests';
            export { default as CourierDeliveries } from '@/Pages/Courier/Deliveries';
        `,
        resolveDir: root,
        loader: 'tsx',
    },
    bundle: true,
    packages: 'external',
    platform: 'node',
    format: 'esm',
    jsx: 'automatic',
    write: false,
    plugins: [
        {
            name: 'b17-ignore-css',
            setup(builder) {
                builder.onResolve({ filter: /\.css$/ }, () => ({ path: 'empty.css', namespace: 'b17-empty-css' }));
                builder.onLoad({ filter: /.*/, namespace: 'b17-empty-css' }, () => ({ contents: '' }));
            },
        },
    ],
});
const ssrPath = join(temporary, 'ssr.mjs');
writeFileSync(ssrPath, ssrBundle.outputFiles[0].text);
const ssrPages = await import(pathToFileURL(ssrPath).href);

const tick = async () => {
    for (let i = 0; i < 20; i++) await Promise.resolve();
};

const HELPER_COMPONENTS = new Set([
    'ScanModeControls',
    'BatchQueueWorkbench',
    'GatePassDialog',
    'HubCategoryTabs',
]);

function nodes(tree) {
    if (!tree || typeof tree !== 'object') return [];
    if (typeof tree.type === 'function' && HELPER_COMPONENTS.has(tree.type.name)) {
        return [tree, ...nodes(tree.type(tree.props))];
    }
    const children = tree.props?.children;
    return [tree, ...[children].flat(Infinity).flatMap(nodes)];
}

function textOf(node) {
    if (node == null || typeof node === 'boolean') return '';
    if (typeof node === 'string' || typeof node === 'number') return String(node);
    if (Array.isArray(node)) return node.map(textOf).join('');
    return textOf(node.props?.children);
}

const activeHub = {
    id: 1,
    name: 'Calamba Bayan Hub',
    code: 'BH-CAL',
    tier: 'local_bayan_hub',
    city_municipality: 'Calamba',
    allows_self_pickup: true,
    operating_hours: '08:00 to 17:00',
};

const baseScannerProps = {
    auth: { user: { id: 10, name: 'Marco Reyes', role: 'logistics' } },
    flash: {},
    errors: {},
    activeHub,
    hubs: [activeHub],
    recentScans: [],
    pendingOriginIntake: [],
    counterPickups: [],
    counterTotal: 0,
    basePath: '/hub',
    stats: { parcels_in_hub: 2, ready_pickup: 0, dispatched_today: 1 },
};

function createScannerHarness(t, seedStorage = {}) {
    const originals = Object.fromEntries(
        ['window', 'document', 'navigator', 'fetch', 'route', 'localStorage'].map((name) => [
            name,
            Object.getOwnPropertyDescriptor(globalThis, name),
        ]),
    );

    const store = new Map(Object.entries(seedStorage));
    const localStorage = {
        getItem: (key) => (store.has(key) ? store.get(key) : null),
        setItem: (key, value) => store.set(key, String(value)),
        removeItem: (key) => store.delete(key),
        clear: () => store.clear(),
    };

    const slots = [];
    const effects = [];
    let cursor = 0;
    let queued = [];
    let disposed = false;

    const h = {
        props: baseScannerProps,
        reloads: 0,
        calls: [],
        visits: [],
        response: null,
        state(initial) {
            const index = cursor++;
            if (!(index in slots)) {
                slots[index] = typeof initial === 'function' ? initial() : initial;
            }
            return [
                slots[index],
                (value) => {
                    assert.equal(disposed, false);
                    slots[index] = typeof value === 'function' ? value(slots[index]) : value;
                },
            ];
        },
        ref(initial) {
            return this.state(() => ({ current: initial }))[0];
        },
        memo(fn, deps) {
            const index = cursor++;
            const old = slots[index];
            if (!old || deps.some((v, i) => !Object.is(v, old.deps[i]))) {
                slots[index] = { deps, value: fn };
            }
            return slots[index].value;
        },
        memoValue(fn, deps) {
            const index = cursor++;
            const old = slots[index];
            if (!old || deps.some((v, i) => !Object.is(v, old.deps[i]))) {
                slots[index] = { deps, value: fn() };
            }
            return slots[index].value;
        },
        effect(callback, deps) {
            const index = cursor++;
            const old = effects[index];
            if (!old || !deps || deps.some((v, i) => !Object.is(v, old.deps[i]))) {
                queued.push(() => {
                    old?.cleanup?.();
                    effects[index] = { deps, cleanup: callback() };
                });
            }
        },
        render() {
            cursor = 0;
            queued = [];
            this.tree = Scanner(this.props);
            const input = nodes(this.tree).find((n) => n.props?.id === 'barcode-field');
            if (input?.ref) input.ref.current = { focus() {} };
            const pending = queued;
            queued = [];
            pending.forEach((fn) => fn());
            cursor = 0;
            this.tree = Scanner(this.props);
            return this.tree;
        },
        button(pattern) {
            const regex = pattern instanceof RegExp ? pattern : new RegExp(pattern.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
            const match = nodes(this.tree).find((n) => n.type === 'button' && regex.test(textOf(n)));
            assert.ok(match, `Expected button matching ${regex}`);
            return match;
        },
        close() {
            if (disposed) return;
            effects.forEach((e) => e?.cleanup?.());
            disposed = true;
        },
        localStorage,
        store,
    };

    const navigator = { onLine: true, mediaDevices: undefined };
    const window = Object.assign(new EventTarget(), {
        location: Object.assign(new URL('https://hub.bagoo.test/hub/scan'), { reload() { h.reloads++; } }),
        localStorage,
    });
    const document = Object.assign(new EventTarget(), {
        visibilityState: 'visible',
        cookie: 'XSRF-TOKEN=b17-csrf',
        querySelector: () => ({ content: 'b17-csrf' }),
    });
    const fetch = async (path, options) => {
        const body = JSON.parse(options.body);
        h.calls.push({ path, body });
        if (h.response) return h.response(path, body);
        return Response.json({
            success: true,
            confirmed: false,
            message: 'Waybill verified.',
            prompt: {
                action: 'RECEIVE_FROM_PICKUP_RIDER',
                requires_confirmation: true,
                expected_status: 'picked_up',
            },
            delivery: {
                id: 11,
                tracking_number: body.barcode,
                status: 'picked_up',
                delivery_type: 'doorstep',
                destination_bin: null,
                buyer: { name: 'Mara Santos', barangay: 'Poblacion', city: 'Santa Cruz' },
                order: { total_amount: 450, payment_method: 'cod', items: [] },
                guidance: {
                    stage: 'Picked up',
                    order_status: 'Picked up',
                    custodian: 'Pickup Rider',
                    action: 'scan',
                    can_act: true,
                    next_step: 'Receive parcel at facility',
                    href: '/hub/scan?hub_id=1',
                    eligible_rider_ids: [],
                },
            },
        });
    };

    for (const [name, value] of Object.entries({ window, document, navigator, fetch, localStorage, route: () => ({ current: () => false }) })) {
        Object.defineProperty(globalThis, name, { value, configurable: true });
    }
    Object.assign(h, { window, document, navigator });
    globalThis.b17Harness = h;

    t.after(() => {
        h.close();
        delete globalThis.b17Harness;
        for (const [name, descriptor] of Object.entries(originals)) {
            if (descriptor) Object.defineProperty(globalThis, name, descriptor);
            else delete globalThis[name];
        }
    });

    return h;
}

async function submitBarcode(h, value) {
    const input = nodes(h.tree).find((n) => n.props?.id === 'barcode-field');
    assert.ok(input, 'Missing #barcode-field input');
    input.props.onChange({ target: { value } });
    h.render();
    const form = nodes(h.tree).find((n) => n.type === 'form' && nodes(n).some((c) => c.props?.id === 'barcode-field'));
    assert.ok(form, 'Missing barcode submit form');
    const result = form.props.onSubmit({ preventDefault() {} });
    await tick();
    return result;
}

test('ScanStation always boots in 2-Step Verify mode even with stale localStorage overrides', async (t) => {
    const h = createScannerHarness(t, {
        bagoo_scan_mode: 'direct',
        scan_mode: 'continuous',
        continuous: 'true',
        auto_advance: 'true',
    });

    h.render();
    const verifyRadio = h.button('2-Step Verify');
    assert.equal(verifyRadio.props['aria-checked'], true);

    await submitBarcode(h, 'BGO-VERIFY-01');
    h.render();

    assert.equal(h.calls.length, 1);
    assert.equal(h.calls[0].path, '/hub/scan');
    assert.equal(h.calls[0].body.mode, 'inspect');
    assert.equal(h.calls[0].body.barcode, 'BGO-VERIFY-01');
});

test('1-Scan Direct Confirm posts to batch endpoint for parcels, forces inspect on BAG-/RMB-/BRT-, and packs open Mother Bag', async (t) => {
    const h = createScannerHarness(t);
    h.response = (path, body) => {
        if (path === '/hub/scan/batch') {
            return Response.json({
                success: true,
                batch_reference: 'batch-ref-01',
                direct_mode: true,
                pinned_action: null,
                summary: { submitted: 1, confirmed: 1, duplicate_replay: 0, rejected: 0, elapsed_ms: 8, scans_per_sec: 125 },
                items: [
                    {
                        item_index: 0,
                        request_token: body.items[0].request_token,
                        barcode: body.items[0].barcode,
                        tracking_number: body.items[0].barcode,
                        delivery_id: 21,
                        outcome: 'confirmed',
                        action_applied: 'RECEIVE_FROM_PICKUP_RIDER',
                        status_before: 'picked_up',
                        status_after: 'arrived_at_origin_hub',
                        error: null,
                    },
                ],
                scan_result: {
                    success: true,
                    confirmed: true,
                    message: 'Custody action recorded.',
                    prompt: { action: 'DISPATCH_TO_FEEDER', requires_confirmation: false, expected_status: 'arrived_at_origin_hub' },
                    delivery: {
                        id: 21,
                        tracking_number: body.items[0].barcode,
                        status: 'arrived_at_origin_hub',
                        delivery_type: 'doorstep',
                        destination_bin: null,
                        buyer: { name: 'Mara Santos', barangay: 'Poblacion', city: 'Santa Cruz' },
                        order: { total_amount: 450, payment_method: 'cod', items: [] },
                        dws: {
                            is_baggable: true,
                            is_oversized: false,
                            length_cm: 24,
                            width_cm: 18,
                            height_cm: 12,
                            actual_weight_kg: 1.5,
                            volumetric_weight_kg: 1.1,
                            chargeable_weight_kg: 1.5,
                            volume_cbm: 0.0052,
                            chute: 'CHUTE-A1',
                        },
                        mother_bag_slotting: {
                            classification: 'mother_bag',
                            bagged: false,
                            weight_kg: 1.5,
                            parcel_count: 0,
                            max_parcels: 50,
                            capacity_pct: 0,
                            seal_number: null,
                        },
                        guidance: {
                            stage: 'Arrived at Origin Bayan Hub',
                            order_status: 'At Sorting Center',
                            custodian: activeHub.name,
                            action: 'manifest',
                            can_act: true,
                            next_step: 'Pack into open Mother Bag for Feeder Dispatch.',
                            href: '/hub/mother-bags?hub_id=1',
                            eligible_rider_ids: [],
                        },
                    },
                },
            });
        }
        if (path === '/hub/mother-bags/pack') {
            return Response.json({
                bag: { id: 'bag-uuid-1', reference: 'BAG-OPEN-01', status: 'open', version: 1, physical_tag: null },
                capacity: { count: 1 },
            });
        }
        return Response.json({
            success: true,
            confirmed: false,
            message: 'Bag inspected.',
            prompt: { action: 'INSPECT_BAG', requires_confirmation: false, expected_status: 'sealed' },
        });
    };

    h.render();
    h.button('1-Scan Direct').props.onClick();
    h.render();
    assert.equal(h.button('1-Scan Direct').props['aria-checked'], true);

    await submitBarcode(h, 'BGO-DIRECT-21');
    h.render();

    assert.equal(h.calls[0].path, '/hub/scan/batch');
    assert.equal(h.calls[0].body.direct_mode, true);
    assert.equal(h.calls[0].body.items[0].barcode, 'BGO-DIRECT-21');
    assert.match(h.calls[0].body.items[0].request_token, /^[0-9a-f-]{36}$/i);

    const packButton = h.button('Add to open Mother Bag');
    await packButton.props.onClick();
    h.render();

    assert.equal(h.calls[1].path, '/hub/mother-bags/pack');
    assert.equal(h.calls[1].body.barcode, 'BGO-DIRECT-21');
    assert.equal(h.calls[1].body.source_hub_id, 1);

    // Master bags, return bags, and buyer return codes NEVER direct-confirm even in direct mode.
    await submitBarcode(h, 'BAG-MASTER-01');
    await submitBarcode(h, 'RMB-RET-02');
    await submitBarcode(h, 'BRT-CASE-03');
    h.render();

    assert.equal(h.calls[2].path, '/hub/scan');
    assert.equal(h.calls[2].body.mode, 'inspect');
    assert.equal(h.calls[3].path, '/hub/scan');
    assert.equal(h.calls[3].body.mode, 'inspect');
    assert.equal(h.calls[4].path, '/hub/scan');
    assert.equal(h.calls[4].body.mode, 'inspect');
});

test('Offline buffering works automatically in any mode on network cutoff, syncs when online, and retains rejected items', async (t) => {
    const h = createScannerHarness(t);
    h.response = (_path, body) =>
        Response.json({
            success: true,
            batch_reference: 'batch-offline-01',
            direct_mode: false,
            pinned_action: null,
            summary: { submitted: 2, confirmed: 1, duplicate_replay: 0, rejected: 1, elapsed_ms: 12, scans_per_sec: 166.7 },
            items: [
                {
                    item_index: 0,
                    request_token: body.items[0].request_token,
                    barcode: 'BGO-OFF-1',
                    tracking_number: 'BGO-OFF-1',
                    delivery_id: 31,
                    outcome: 'confirmed',
                    action_applied: 'RECEIVE_FROM_PICKUP_RIDER',
                    status_before: 'picked_up',
                    status_after: 'arrived_at_origin_hub',
                    error: null,
                },
                {
                    item_index: 1,
                    request_token: body.items[1].request_token,
                    barcode: 'BGO-OFF-BAD',
                    tracking_number: null,
                    delivery_id: null,
                    outcome: 'rejected',
                    action_applied: null,
                    status_before: null,
                    status_after: null,
                    error: 'Parcel #BGO-OFF-BAD not found.',
                },
            ],
        });

    h.navigator.onLine = false;
    h.window.dispatchEvent(new Event('offline'));
    h.render();

    // Still in default 2-Step Verify mode — no manual Offline mode toggle needed!
    submitBarcode(h, 'BGO-OFF-1');
    h.render();

    // Even in 1-Scan Direct mode, offline scans buffer automatically instead of hitting the network.
    h.button('1-Scan Direct').props.onClick();
    h.render();
    submitBarcode(h, 'BGO-OFF-BAD');
    h.render();

    assert.equal(h.calls.length, 0, 'Offline queueing must not hit network.');
    const persisted = JSON.parse(h.store.get('bagoo_scan_batch_queue_v1:1') || '[]');
    assert.equal(persisted.length, 2);

    // Go back online and open the Batch queue workbench tab.
    h.navigator.onLine = true;
    h.window.dispatchEvent(new Event('online'));
    h.render();

    h.button(/^Batch queue/).props.onClick();
    h.render();

    await h.button('Sync 2 queued scans').props.onClick();
    await tick();
    h.render();

    assert.equal(h.calls.length, 1);
    assert.equal(h.calls[0].path, '/hub/scan/batch');
    assert.equal(h.calls[0].body.direct_mode, false);
    assert.equal(h.calls[0].body.items.length, 2);

    // Confirmed BGO-OFF-1 dropped from local queue; rejected BGO-OFF-BAD stays queued for operator review.
    const remaining = JSON.parse(h.store.get('bagoo_scan_batch_queue_v1:1') || '[]');
    assert.equal(remaining.length, 1);
    assert.equal(remaining[0].barcode, 'BGO-OFF-BAD');
});

async function renderPage(Component, name, pageProps, url) {
    let view;
    globalThis.route = (n) => (n ? `/${n.replaceAll('.', '/')}` : { current: () => false, has: () => true });
    try {
        const result = await createInertiaApp({
            page: {
                component: name,
                props: {
                    auth: { user: { id: 1, name: 'Ana Santos', role: 'logistics' } },
                    flash: {},
                    errors: {},
                    hubs: [activeHub],
                    ...pageProps,
                },
                url,
                version: null,
            },
            resolve: () => Component,
            setup: ({ App, props }) => {
                view = createElement(App, props);
                return view;
            },
            render: () => renderToStaticMarkup(view),
        });
        return result.body;
    } finally {
        delete globalThis.route;
    }
}

test('All Hub and Courier views render role guidance, category tabs, and calm non-emoji markup', async () => {
    const dashboardHtml = await renderPage(
        ssrPages.Dashboard,
        'Hub/Dashboard',
        {
            scope: {
                mode: 'hub',
                company_name: 'Bagoo Acceptance',
                company_code: 'ACCEPT',
                selected_hub_id: activeHub.id,
                hubs: [activeHub],
                can_view_company: true,
                can_scan: true,
            },
            stats: { parcels_in_custody: 5, ready_for_dispatch: 2, exceptions: 0, dispatched_today: 1 },
            attentionQueue: [
                { key: 'intake', label: 'Pickup Intake', count: 2, href: '/hub/scan', tone: 'info' },
            ],
            movement: [{ date: '2026-11-14', label: 'Nov 14', inbound: 4, outbound: 2 }],
            parcelFlow: { origin_hub: 2, mother_hub_transit: 1, destination_hub: 1, final_mile: 1, counter_pickup: 0 },
            facilities: [
                {
                    id: activeHub.id,
                    name: activeHub.name,
                    code: activeHub.code,
                    tier: activeHub.tier,
                    city_municipality: activeHub.city_municipality,
                    parcels: 5,
                    exceptions: 0,
                    active_fleet: 2,
                    capacity: 4,
                    utilization_rate: 50,
                },
            ],
            recentActivity: [],
        },
        '/hub/dashboard',
    );
    assert.doesNotMatch(dashboardHtml, /What to do next|Real-time parcel custody|SLA clocks, recovery|Parcel journey pipeline/);
    assert.match(dashboardHtml, /role="tablist"/);

    const deliveriesHtml = await renderPage(
        ssrPages.Deliveries,
        'Hub/Deliveries',
        {
            activeHub,
            deliveries: { data: [], current_page: 1, last_page: 1, total: 0, links: [] },
            counts: { all: 0, in_hub: 0, out_for_delivery: 0, ready_pickup: 0, handovers_today: 0 },
            filters: { search: '', status: 'all', delivery_type: 'all' },
            eligibleRiders: [],
            basePath: '/hub',
            canScan: true,
        },
        '/hub/deliveries',
    );
    assert.doesNotMatch(deliveriesHtml, /What to do next|Find a parcel, check who holds it/);
    assert.match(deliveriesHtml, /role="tablist"/);

    const counterHtml = await renderPage(
        ssrPages.CounterPickup,
        'Hub/CounterPickup',
        {
            activeHub,
            counterParcels: { data: [], current_page: 1, last_page: 1, total: 0, from: null, to: null, prev_page_url: null, next_page_url: null, first_page_url: '/hub/counter' },
            recentlyCollected: [],
            search: '',
            requestToken: 'tok-1',
            canConfigureHours: false,
            basePath: '/hub',
            canScan: true,
            counterCounts: { shown: 0, ready: 0, blocked: 0 },
        },
        '/hub/counter',
    );
    assert.doesNotMatch(counterHtml, /What to do next|Verified Station Handover|Counter release checks/);
    assert.match(counterHtml, /role="tablist"/);

    const bagsHtml = await renderPage(
        ssrPages.MotherBags,
        'Hub/MotherBags',
        {
            hubs: [{ ...activeHub, logistics_company_id: 1, canScan: true }],
            bags: { data: [], links: [] },
            selectedBag: null,
            manifests: [],
            creationToken: 'tok-bag',
            basePath: '/hub',
        },
        '/hub/mother-bags',
    );
    assert.doesNotMatch(bagsHtml, /What to do next|The parcel’s stored route/);
    assert.match(bagsHtml, /role="tablist"/);

    const manifestsHtml = await renderPage(
        ssrPages.Manifests,
        'Hub/Manifests',
        {
            hubs: [activeHub],
            vehicles: [],
            manifests: { data: [], links: [] },
            selectedManifest: null,
            canCreate: true,
            creationToken: 'tok-man',
            commandToken: 'tok-cmd',
            basePath: '/hub',
        },
        '/hub/manifests',
    );
    assert.doesNotMatch(manifestsHtml, /What to do next|Feeder runs use a closed van|Choose a hub pin or use the searchable fields/);
    assert.match(manifestsHtml, /role="tablist"/);

    const courierHtml = await renderPage(
        ssrPages.CourierDeliveries,
        'Courier/Deliveries',
        {
            auth: { user: { id: 9, name: 'Ben Rider', role: 'courier' } },
            scope: {
                mode: 'bayan',
                facilityName: 'Calamba Bayan Hub',
                hubCode: 'BH-CAL',
                companyName: 'Bagoo',
                barangay: 'Poblacion',
            },
            isOnline: true,
            stats: {
                availablePickups: 0,
                activePickups: 0,
                finalMile: 0,
                deliveredToday: 0,
                failedToday: 0,
                earningsTotal: 0,
            },
            queues: {
                pickupTasks: [],
                availablePickups: [],
                finalMileTasks: [],
                recentActivity: [],
            },
            recoveryRequestToken: 'tok-rec',
        },
        '/courier/deliveries',
    );
    assert.doesNotMatch(courierHtml, /Courier Rider · What to do next/);

    for (const html of [dashboardHtml, deliveriesHtml, counterHtml, bagsHtml, manifestsHtml, courierHtml]) {
        assert.doesNotMatch(html, /font-mono/);
        assert.doesNotMatch(html, /🛑|⚠️/);
    }
    const workspaceCss = readFileSync(join(root, 'resources/css/logistics-workspace.css'), 'utf8');
    assert.match(workspaceCss, /border-radius:\s*4px;/);
    assert.match(workspaceCss, /border-color:\s*#E7E5E4;/);
});

test('All touched Hub and Courier UI files keep zero font-mono and zero emoji characters in source', () => {
    const files = [
        'resources/js/Pages/Hub/ScanStation.tsx',
        'resources/js/Pages/Hub/Dashboard.tsx',
        'resources/js/Pages/Hub/Deliveries.tsx',
        'resources/js/Pages/Hub/CounterPickup.tsx',
        'resources/js/Pages/Hub/MotherBags.tsx',
        'resources/js/Pages/Hub/Manifests.tsx',
        'resources/js/Pages/Courier/Deliveries.tsx',
        'resources/js/Components/Logistics/ScanBatchPanel.tsx',
        'resources/js/Components/Logistics/Workspace.tsx',
        'resources/js/Components/Logistics/HubConveyorRail.tsx',
    ];

    for (const rel of files) {
        const source = readFileSync(join(root, rel), 'utf8');
        assert.doesNotMatch(source, /\bfont-mono\b/, `${rel} must not contain font-mono`);
        assert.doesNotMatch(source, /[\u{1F300}-\u{1FAFF}]/u, `${rel} must not contain emojis`);
    }
});
