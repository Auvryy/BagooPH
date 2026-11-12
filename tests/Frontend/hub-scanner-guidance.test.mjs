import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createInertiaApp } from '@inertiajs/react';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-scanner-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const bundle = await build({
    stdin: { contents: "export { default as Scanner } from '@/Pages/Hub/ScanStation'; export { default as Preview } from '@/Components/HubCounterPreview';", resolveDir: resolve(import.meta.dirname, '../..'), loader: 'tsx' },
    bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', loader: { '.css': 'empty' }, write: false,
    plugins: [{ name: 'recorded-scanner-result', setup(builder) {
        builder.onResolve({ filter: /^react$/ }, args => args.namespace === 'scanner-result'
            ? { path: 'react', external: true } : { path: 'scanner-result', namespace: 'scanner-result' });
        builder.onLoad({ filter: /.*/, namespace: 'scanner-result' }, () => ({ contents: `
            import React from 'react'; export * from 'react'; export default React;
            export const useState = value => {
                const state = React.useState(value);
                if (value === '' && globalThis.scannerResult.previewSearch && !globalThis.scannerResult.previewApplied) {
                    globalThis.scannerResult.previewApplied = true;
                    return [globalThis.scannerResult.previewSearch, state[1]];
                }
                if (value === null && ++globalThis.scannerResult.nullStates === 2 && !globalThis.scannerResult.applied) {
                    globalThis.scannerResult.applied = true;
                    return [globalThis.scannerResult.value, state[1]];
                }
                return state;
            };
        ` }));
    } }],
});
const path = join(temporary, 'scanner.mjs');
writeFileSync(path, bundle.outputFiles[0].text);
const { Scanner, Preview } = await import(pathToFileURL(path).href);
globalThis.route = name => name ? `/${name.replaceAll('.', '/')}` : { current: () => false, has: () => true };
after(() => delete globalThis.route);

const hub = { id: 2, name: 'Destination Bayan Hub', code: 'BH-2', tier: 'local_bayan_hub', province: 'Laguna',
    city_municipality: 'Santa Cruz', barangay: 'Poblacion III', allows_self_pickup: true };
const guidance = { stage: 'Received at destination Bayan Hub', order_status: 'At Sorting Center', custodian: hub.name, custody_recorded_at: null,
    next_step: 'Scan to stage the parcel for secure pickup', responsible: 'Assigned counter handler', blocker: null,
    action: 'scan', can_act: true, href: '/hub/scan?hub_id=2', eligible_rider_ids: [], ready_for_collection: false };
const result = { confirmed: false,
    prompt: { action: 'STAGE_FOR_PICKUP', prompt: 'Stage for secure pickup', expected_status: 'arrived_at_destination_hub',
        next_status: 'ready_for_hub_pickup', requires_confirmation: true, color: 'amber', manifest_url: null },
    delivery: { id: 5, tracking_number: 'BGO-SCANNER', status: 'arrived_at_destination_hub', delivery_type: 'hub_self_pickup',
        destination_bin: null, current_hub: hub, buyer: { name: 'Mara Santos', phone: '', barangay: 'Poblacion III', city: 'Santa Cruz' },
        order: { order_number: 'BGO-ORDER', total_amount: 950, payment_method: 'cod', items: [] }, guidance } };

async function render(value, basePath = '/hub', Component = Scanner, overrides = {}, previewSearch = null) {
    globalThis.scannerResult = { applied: false, nullStates: 0, value, previewSearch, previewApplied: false };
    try {
        let view;
        const response = await createInertiaApp({
            page: { component: 'Hub/ScanStation', props: { auth: { user: { id: 1, name: 'Ana Santos', role: 'logistics' } },
                flash: {}, errors: {}, activeHub: hub, hubs: [hub], recentScans: [], pendingOriginIntake: [], counterPickups: [],
                counterTotal: 0, basePath, stats: { parcels_in_hub: 1, ready_pickup: 0, dispatched_today: 0 }, ...overrides }, url: `${basePath}/scan`, version: null },
            resolve: () => Component, setup: ({ App, props }) => { view = createElement(App, props); return view; }, render: () => renderToStaticMarkup(view),
        });
        return response.body;
    } finally { delete globalThis.scannerResult; }
}

const corridor = { corridor_id: '2-3', source_hub_id: 2,
    destination_hub: { id: 3, name: 'Laguna Mother Hub', code: 'MH-LAG', tier: 'regional_mother_hub' },
    leg_type: 'feeder', sealed_bags_count: 1, open_bags_count: 0, loose_cargo_count: 0, unpacked_count: 0,
    unknown_readings_count: 0, total_containers: 1, total_parcels: 2, total_weight_kg: 7.125, total_cbm: 0.015,
    sizing: { required_type: 'l300_van', vehicle_name: 'Closed van', max_payload_kg: 100, max_cbm: null,
        payload_utilization_pct: 7.1, volume_utilization_pct: null, is_upgrade: false },
    matched_vehicle: { id: 9, plate_number: 'QA-VAN-901', driver_name: 'Mara Driver', capacity_kg: 100 },
    can_dispatch: true, blocker: null };

test('staging shows retained package values and an eligible vehicle without claiming departure', async () => {
    const html = await render(result, '/hub', Scanner, { corridorStaging: [corridor] });
    assert.match(html, /Outbound staging/);
    assert.match(html, /7\.125 kg/);
    assert.match(html, /QA-VAN-901/);
    assert.match(html, /Prepare manifest/);
    assert.doesNotMatch(html, /Auto-upgraded|Algorithmic payload|scheduled linehaul vehicle assignment/);
});

test('unknown readings render an actionable blocked state without a fabricated zero weight or capacity', async () => {
    const html = await render(result, '/hub', Scanner, { corridorStaging: [{ ...corridor, total_weight_kg: null,
        total_cbm: null, matched_vehicle: null, can_dispatch: false, unknown_readings_count: 1,
        sizing: { ...corridor.sizing, payload_utilization_pct: null }, blocker: 'Complete outer-package readings.' }] });
    assert.match(html, /Needs readings/);
    assert.match(html, /No eligible match/);
    assert.match(html, /Complete outer-package readings/);
    assert.match(html, /href="\/hub\/mother-bags\?hub_id=2"/);
    assert.match(html, /<button[^>]*disabled=""[^>]*>[\s\S]*?Prepare manifest/);
    assert.doesNotMatch(html, /NaN|0\.000 kg/);
});

test('local final-mile shelf is separate from a regional packing suggestion', async () => {
    const html = await render({ ...result, delivery: { ...result.delivery,
        next_hop: { hub_id: 2, recommended_bin: 'LOC-BIN-04' } } });
    assert.match(html, /Local delivery staging/);
    assert.match(html, /LOC-BIN-04/);
    assert.doesNotMatch(html, /Suggested packing|New bag required/);
});

test('actual scanner result separates arrival, commercial state, custody and missing shelf information', async () => {
    const html = await render(result);
    assert.match(html, /Shelf not recorded/);
    assert.match(html, /Order:<\/span> At Sorting Center/);
    assert.match(html, /With:<\/span> Destination Bayan Hub/);
    assert.match(html, /Scan to stage the parcel for secure pickup/);
    assert.match(html, /Confirm custody action/);
    assert.doesNotMatch(html, /SELF-PICKUP-SHELF|BIN: GENERAL/);
});

test('scanner blockers suppress unsupported confirmation even when an earlier prompt offered it', async () => {
    const html = await render({ ...result, delivery: { ...result.delivery, guidance: { ...guidance, can_act: false,
        next_step: 'Review missing custody evidence', custodian: 'Custody not established', blocker: 'No retained custody checkpoint establishes the parcel holder.' } } });
    assert.match(html, /No retained custody checkpoint/);
    assert.doesNotMatch(html, /Confirm custody action|Waybill verified; confirm the action/);
    assert.match(html, /follow the guidance above/);
});

test('sorting, assignment and supervised recovery have usable next-step links after a scan', async () => {
    for (const [action, label, href] of [['sort', 'Open parcel sorting', '/hub/deliveries?hub_id=2&search=BGO-SCANNER'],
        ['assign', 'Assign rider', '/hub/deliveries?hub_id=2&search=BGO-SCANNER'], ['recovery', 'Open recovery review', '/handover-issues/5'],
        ['manifest', 'Open manifests', '/hub/manifests/8?hub_id=2']]) {
        const html = await render({ ...result, prompt: { ...result.prompt, action: 'INSPECT_WAYBILL', requires_confirmation: false },
            delivery: { ...result.delivery, registry_url: '/hub/deliveries?hub_id=2&search=BGO-SCANNER', guidance: { ...guidance, action, href, next_step: label } } });
        assert.ok(html.includes(`href="${href.replaceAll('&', '&amp;')}"`));
        assert.match(html, /Check for updates/); assert.match(html, /Scan next parcel/);
        assert.doesNotMatch(html, /Confirm custody action/);
    }
});

test('staged scanner results preserve real bins and link to secure verification in the current portal', async () => {
    for (const basePath of ['/hub', '']) {
        const html = await render({ ...result, prompt: { ...result.prompt, action: 'AWAIT_BUYER_PICKUP', requires_confirmation: false },
            delivery: { ...result.delivery, destination_bin: 'COUNTER-B2', status: 'ready_for_hub_pickup', guidance: { ...guidance,
                stage: 'Staged for secure counter collection', order_status: 'Sorted', action: 'counter', ready_for_collection: true,
                next_step: 'Verify buyer identity, claim code and exact COD before release', href: `${basePath}/counter?hub_id=2&search=BGO-SCANNER` } } }, basePath);
        assert.match(html, /COUNTER-B2/);
        assert.match(html, /Staged for secure counter collection/);
        assert.ok(html.includes(`href="${basePath}/counter?hub_id=2&amp;search=BGO-SCANNER"`));
        assert.doesNotMatch(html, /Confirm custody action|Confirm Handover|Release to Buyer/);
    }
});

test('scanner preview phone searches filter the visible rows and carry the same query to the full counter', async () => {
    for (const basePath of ['/hub', '']) {
        const parcel = { id: 5, tracking_number: 'BGO-PHONE-MATCH', order_number: 'BGO-PHONE-ORDER', buyer_name: 'Mara Santos',
            buyer_phone: '09170000001', destination_bin: null, total_amount: 950, payment_method: 'cod', guidance };
        const html = await render(null, basePath, Preview, { parcels: [parcel, { ...parcel, id: 6, tracking_number: 'BGO-OTHER-PHONE', buyer_phone: '09170000002' }],
            total: 2, hubId: 2, basePath }, '09170000001');
        assert.match(html, /BGO-PHONE-MATCH/);
        assert.doesNotMatch(html, /BGO-OTHER-PHONE/);
        assert.ok(html.includes(`href="${basePath}/counter?hub_id=2&amp;search=09170000001"`));
    }
});
