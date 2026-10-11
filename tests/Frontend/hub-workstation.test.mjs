import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { buildSync } from 'esbuild';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createInertiaApp } from '@inertiajs/react';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-workstation-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const bundle = buildSync({
    stdin: { contents: `export { default as Registry } from '@/Pages/Hub/Deliveries'; export { default as Counter } from '@/Pages/Hub/CounterPickup'; export { default as Preview } from '@/Components/HubCounterPreview';`, resolveDir: resolve(import.meta.dirname, '../..'), loader: 'tsx' },
    bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', loader: { '.css': 'empty' }, write: false,
});
const path = join(temporary, 'workstation.mjs');
writeFileSync(path, bundle.outputFiles[0].text);
const { Registry, Counter, Preview } = await import(pathToFileURL(path).href);
globalThis.route = name => name ? `/${name.replaceAll('.', '/')}` : { current: () => false, has: () => true };
after(() => delete globalThis.route);

const hub = { id: 2, name: 'Destination Bayan Hub', code: 'BH-2', tier: 'local_bayan_hub', city_municipality: 'Santa Cruz', operating_hours: '08:00 to 17:00' };
const guidance = { stage: 'Received at destination Bayan Hub', order_status: 'At Sorting Center', custodian: hub.name, custody_recorded_at: null,
    next_step: 'Sort to the recorded destination barangay bin', responsible: 'Assigned destination handler', blocker: null,
    action: 'sort', can_act: true, href: null, eligible_rider_ids: [], ready_for_collection: false };
const parcel = { id: 5, tracking_number: 'BGO-WORKSTATION', order_number: 'BGO-ORDER', buyer_name: 'Mara Santos', buyer_phone: 'N/A', buyer_id: 4,
    destination_barangay: 'Poblacion III', shipping_city: 'Santa Cruz', status: 'arrived_at_destination_hub', delivery_type: 'doorstep',
    destination_bin: null, current_hub: hub.name, awaiting_origin_intake: false, destination_hub: hub.name, rider_name: 'Unassigned',
    total_amount: 950, payment_method: 'cod', item_count: 1, items: [{ name: 'Bagoo daily bag', quantity: 1, price: 950 }],
    created_at: '2026-10-11', arrived_at: null, claim: null, guidance };

async function render(Component, name, props) {
    let view;
    const result = await createInertiaApp({
        page: { component: name, props: { auth: { user: { id: 1, name: 'Ana Santos', role: 'logistics' } }, flash: {}, errors: {}, hubs: [hub], ...props }, url: '/hub/deliveries', version: null },
        resolve: () => Component, setup: ({ App, props }) => { view = createElement(App, props); return view; }, render: () => renderToStaticMarkup(view),
    });
    return result.body;
}
function registry(data = [parcel], overrides = {}) {
    return { activeHub: hub, deliveries: { data, current_page: 1, last_page: 1, total: data.length, links: [] },
        counts: { all: data.length, in_hub: data.length, out_for_delivery: 0, ready_pickup: 0, handovers_today: 2 },
        filters: { search: '', status: 'all', delivery_type: 'all' }, eligibleRiders: [], basePath: '/hub', canScan: true, ...overrides };
}
function counter(data = [], overrides = {}) {
    return { activeHub: hub, counterParcels: { data, current_page: 1, last_page: 1, total: data.length, from: data.length ? 1 : null, to: data.length || null,
        prev_page_url: null, next_page_url: null, first_page_url: '/hub/counter?hub_id=2&page=1' }, recentlyCollected: [], search: '', requestToken: 'REQUEST', canConfigureHours: false,
        basePath: '/hub', canScan: true, counterCounts: { shown: data.length, ready: 0, blocked: data.length }, ...overrides };
}
function releaseButton(html) {
    const button = [...html.matchAll(/<button\b[^>]*>[\s\S]*?<\/button>/g)].map(match => match[0]).find(value => value.includes('<span>Release</span>'));
    assert.ok(button, 'The counter release control must be present.');
    return button;
}

test('registry separates parcel stage, order state and actual custodian while showing recorded handovers', async () => {
    const html = await render(Registry, 'Hub/Deliveries', registry());
    assert.match(html, /Received at destination Bayan Hub/);
    assert.match(html, /Order:<\/span> At Sorting Center/);
    assert.match(html, /Recorded custodian:<\/span> Destination Bayan Hub/);
    assert.match(html, /Recorded Handovers Today/);
    assert.match(html, /Today, Asia\/Manila/);
    assert.match(html, /Sort to Bin/);
    assert.doesNotMatch(html, /100% Fulfilled|No action|STAGE: UNASSIGNED/);
});
test('missing evidence has a visible blocker and no sorting or assignment control', async () => {
    const html = await render(Registry, 'Hub/Deliveries', registry([{ ...parcel, guidance: { ...guidance, can_act: false, custodian: 'Custody not established',
        next_step: 'Review missing custody evidence', blocker: 'No retained custody checkpoint establishes the parcel holder.' } }]));
    assert.match(html, /Review missing custody evidence/);
    assert.match(html, /No retained custody checkpoint/);
    assert.doesNotMatch(html, /Sort to Bin|Select area rider/);
});
test('assignment offers only the server-authorized riders and does not label them as current custodians', async () => {
    const html = await render(Registry, 'Hub/Deliveries', registry([{ ...parcel, status: 'sorted_to_barangay_bin', guidance: { ...guidance,
        action: 'assign', eligible_rider_ids: [11], next_step: 'Assign an eligible final-mile rider' } }], { eligibleRiders: [
        { id: 11, name: 'Eligible area rider', assigned_barangay: 'Poblacion III', vehicle_type: 'motorcycle' },
        { id: 12, name: 'Unavailable area rider', assigned_barangay: 'Poblacion III', vehicle_type: 'motorcycle' },
    ], canScan: false }));
    assert.match(html, /<option value="11">Eligible area rider/);
    assert.doesNotMatch(html, /Unavailable area rider|Floor Scanner/);
    assert.match(html, /Recorded custodian:<\/span> Destination Bayan Hub/);
});
test('an empty queue remains truthful and zero counts do not become a success percentage', async () => {
    const html = await render(Registry, 'Hub/Deliveries', registry([], { counts: { all: 0, in_hub: 0, out_for_delivery: 0, ready_pickup: 0, handovers_today: 0 }, canScan: false }));
    assert.match(html, /No waybills found/);
    assert.doesNotMatch(html, /100%|Station Active|Floor Scanner/);
});
test('arrived counter parcels show staging guidance and a disabled release control', async () => {
    const html = await render(Counter, 'Hub/CounterPickup', counter([{ ...parcel, delivery_type: 'hub_self_pickup', guidance: { ...guidance,
        action: 'scan', href: '/hub/scan?hub_id=2', next_step: 'Scan to stage the parcel for secure pickup' } }]));
    assert.match(html, /Awaiting staging/);
    assert.match(html, /Scan to stage the parcel/);
    assert.match(html, /href="\/hub\/scan\?hub_id=2"/);
    assert.match(releaseButton(html), /disabled=""/);
    assert.doesNotMatch(html, /100% Verified|SMS Claim|Open For Claims|SHELF-A1/);
});
test('ready counter parcels permit handler verification but respect the current collection deadline', async () => {
    const ready = { ...parcel, delivery_type: 'hub_self_pickup', status: 'ready_for_hub_pickup',
        claim: { reference: 'PUC-5', status: 'ready', expires_at: '2099-10-11T12:00:00Z', locked_until: null },
        guidance: { ...guidance, action: 'counter', ready_for_collection: true, next_step: 'Verify buyer identity, claim code and exact COD before release' } };
    const html = await render(Counter, 'Hub/CounterPickup', counter([ready], { counterCounts: { shown: 1, ready: 1, blocked: 0 } }));
    assert.match(html, /Verify buyer identity, claim code and exact COD/);
    assert.doesNotMatch(releaseButton(html), /disabled=/);
    const expired = await render(Counter, 'Hub/CounterPickup', counter([{ ...ready, claim: { ...ready.claim, expires_at: '2000-01-01T00:00:00Z' } }], { counterCounts: { shown: 1, ready: 1, blocked: 0 } }));
    assert.match(expired, /collection window changed/);
    const readiness = expired.slice(expired.indexOf('Ready for Buyer Verification')).match(/<p[^>]*>([\s\S]*?)<\/p>/);
    assert.ok(readiness);
    assert.equal(readiness[1].replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim(), '0 ready');
    assert.match(releaseButton(expired), /disabled=""/);
});
test('company counter management sees readiness without receiving a handler release control', async () => {
    const html = await render(Counter, 'Hub/CounterPickup', counter([{ ...parcel, guidance: { ...guidance,
        action: 'counter', can_act: false, ready_for_collection: true, blocker: 'An assigned counter handler must verify and release the parcel.' },
        claim: { reference: 'PUC-5', status: 'ready', expires_at: '2099-10-11T12:00:00Z', locked_until: null } }], { canScan: false, canConfigureHours: true }));
    assert.match(html, /Save pickup hours/);
    assert.match(html, /assigned counter handler must verify/);
    assert.doesNotMatch(html, /Station Scanner/);
    assert.match(releaseButton(html), /disabled=""/);
});

test('counter page controls keep route, facility and search while counts identify the visible page', async () => {
    for (const basePath of ['/hub', '']) {
        const props = counter([parcel], { basePath });
        props.counterParcels = { ...props.counterParcels, current_page: 2, last_page: 3, total: 40, from: 16, to: 16,
            prev_page_url: `${basePath}/counter?hub_id=2&search=Mara&page=1`, next_page_url: `${basePath}/counter?hub_id=2&search=Mara&page=3` };
        const html = await render(Counter, 'Hub/CounterPickup', props);
        assert.match(html, /40 matching parcels/);
        assert.match(html, /Showing 16 to 16 of 40 matching parcels\. Page 2 of 3\./);
        assert.match(html, /Ready for Buyer Verification \(this page\)/);
        assert.match(html, /Needs Attention \(this page\)/);
        assert.ok(html.includes(`href="${basePath}/counter?hub_id=2&amp;search=Mara&amp;page=1"`));
        assert.ok(html.includes(`href="${basePath}/counter?hub_id=2&amp;search=Mara&amp;page=3"`));
    }
});

test('counter missing shelf labels do not misstate real staging and recorded locations survive', async () => {
    const ready = { ...parcel, destination_bin: null, arrived_at: 'Oct 11, 08:00', status: 'ready_for_hub_pickup',
        guidance: { ...guidance, stage: 'Staged for secure counter collection' } };
    const html = await render(Counter, 'Hub/CounterPickup', counter([ready, { ...ready, id: 6, destination_bin: 'COUNTER-B2' }]));
    assert.match(html, /Shelf not recorded/);
    assert.match(html, /Staged: Oct 11, 08:00 Asia\/Manila/);
    assert.match(html, /COUNTER-B2/);
    assert.doesNotMatch(html, /Not staged|SELF-PICKUP-SHELF|BIN: GENERAL/);
});

test('empty and out-of-range counter pages have accurate totals and a route back to the first page', async () => {
    const props = counter([], { counterParcels: { data: [], current_page: 8, last_page: 2, total: 16, from: null, to: null,
        prev_page_url: '/hub/counter?hub_id=2&page=7', next_page_url: null, first_page_url: '/hub/counter?hub_id=2&page=1' } });
    const html = await render(Counter, 'Hub/CounterPickup', props);
    assert.match(html, /No parcels on this page/);
    assert.match(html, /Showing 0 to 0 of 16 matching parcels/);
    assert.match(html, /href="\/hub\/counter\?hub_id=2&amp;page=1"[^>]*>First page/);
    const empty = await render(Counter, 'Hub/CounterPickup', counter());
    assert.match(empty, /No matching parcels at this counter/);
    assert.match(empty, /Showing 0 to 0 of 0 matching parcels/);
});


test('scanner counter preview shows actual guidance and uses the full secure counter for handover', async () => {
    for (const basePath of ['/hub', '']) {
        const ready = { ...parcel, status: 'ready_for_hub_pickup', destination_bin: null, guidance: { ...guidance,
            stage: 'Staged for secure counter collection', action: 'counter', ready_for_collection: true,
            next_step: 'Verify buyer identity, claim code and exact COD before release' } };
        const html = await render(Preview, 'Hub/ScanStation', { parcels: [ready, { ...ready, id: 6, destination_bin: 'COUNTER-B2',
            guidance: { ...ready.guidance, can_act: false, ready_for_collection: false, blocker: 'This pickup claim is expired or already used.' } }],
            total: 25, hubId: 2, basePath });
        assert.match(html, /Showing the latest 2 of 25 counter parcels/);
        assert.match(html, /Shelf not recorded/);
        assert.match(html, /COUNTER-B2/);
        assert.match(html, /Recorded custodian:<\/span> Destination Bayan Hub/);
        assert.match(html, /This pickup claim is expired or already used/);
        assert.ok(html.includes(`href="${basePath}/counter?hub_id=2&amp;search=BGO-WORKSTATION"`));
        assert.match(html, /Open full counter queue/);
        assert.doesNotMatch(html, /Release to Buyer|Confirm Handover|SELF-PICKUP-SHELF|BIN: GENERAL|name="claim_code"/);
    }
});

test('empty scanner previews distinguish an empty facility queue from a bounded preview without matches', async () => {
    const empty = await render(Preview, 'Hub/ScanStation', { parcels: [], total: 0, hubId: 2, basePath: '/hub' });
    assert.match(empty, /No parcels waiting at this counter/);
    const noMatch = await render(Preview, 'Hub/ScanStation', { parcels: [], total: 25, hubId: 2, basePath: '/hub' });
    assert.match(noMatch, /Search the full counter queue/);
    assert.doesNotMatch(noMatch, /No parcels waiting at this counter/);
});
