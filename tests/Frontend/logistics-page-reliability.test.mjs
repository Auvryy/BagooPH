import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createInertiaApp } from '@inertiajs/react';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-logistics-pages-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const bundle = await build({
    stdin: { contents: `export { default as Overview } from '@/Pages/Hub/Dashboard'; export { default as ErrorPage } from '@/Pages/Hub/Error'; export { default as HubLocationEditor } from '@/Components/Logistics/HubLocationEditor'; export { default as CompanySetup } from '@/Pages/Hub/CompanySetup';`, resolveDir: resolve(import.meta.dirname, '../..'), loader: 'tsx' },
    bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', loader: { '.css': 'empty' }, write: false,
    plugins: [{ name: 'test-css', setup(builder) { builder.onResolve({ filter: /\.css$/ }, () => ({ path: 'style', namespace: 'test-css' })); builder.onLoad({ filter: /.*/, namespace: 'test-css' }, () => ({ contents: '', loader: 'js' })); } }],
});
const bundlePath = join(temporary, 'pages.mjs');
writeFileSync(bundlePath, bundle.outputFiles[0].text);
const { Overview, ErrorPage, HubLocationEditor, CompanySetup } = await import(pathToFileURL(bundlePath).href);
const paths = { 'hub.dashboard': '/hub/dashboard', 'hub.scan.station': '/hub/scan', 'hub.deliveries': '/hub/deliveries', 'hub.network': '/hub/network', 'hub.fleet': '/hub/fleet', 'hub.counter': '/hub/counter', 'hub.manifests.index': '/hub/manifests', 'hub.recovery.index': '/hub/delivery-recovery', 'hub.parcel-recovery.index': '/hub/parcel-recovery', 'hub.cod.index': '/hub/cod' };
globalThis.route = name => name ? paths[name] ?? `/${name.replaceAll('.', '/')}` : { current: () => false, has: () => true };
after(() => delete globalThis.route);

async function render(Component, name, props) {
    let view;
    const result = await createInertiaApp({
        page: { component: name, props, url: '/hub/dashboard', version: null }, resolve: () => Component,
        setup: ({ App, props }) => { view = createElement(App, props); return view; }, render: () => renderToStaticMarkup(view),
    });
    return result.body;
}

function overview(canScan) {
    const hub = { id: 2, name: 'Bagoo Bayan Hub', code: 'BH-PAGE', tier: 'local_bayan_hub' };
    return { auth: { user: { id: 1, name: 'Mara Santos', role: 'logistics', canScanParcels: canScan, activeHub: hub, allHubs: [hub] } },
        flash: {}, errors: {}, scope: { mode: canScan ? 'hub' : 'company', company_name: 'Bagoo Page Checks', company_code: 'PAGE',
            selected_hub_id: canScan ? hub.id : null, hubs: [hub], can_view_company: !canScan, can_scan: canScan },
        stats: { parcels_in_custody: 0, ready_for_dispatch: 0, exceptions: 0, dispatched_today: 0 },
        attentionQueue: [{ key: 'next_scan', label: 'Awaiting next hub scan', count: 1, href: canScan ? '/hub/scan' : '/hub/deliveries', tone: 'neutral' }],
        movement: [], parcelFlow: { origin_hub: 0, mother_hub_transit: 0, destination_hub: 0, final_mile: 0, counter_pickup: 0 }, facilities: [], recentActivity: [] };
}

test('hub pin editor requires an actual selected point before saving', async () => {
    const base = { name: 'Test Hub', latitude: null, longitude: null, location_token: 'a'.repeat(64) };
    const empty = await render(HubLocationEditor, 'Hub/Pin', { hub: base, url: '/hub/location' });
    assert.match(empty, /role="status">Loading map…<\/p>/);
    assert.match(empty, /aria-label="Hub location map"/);
    assert.match(empty, /<button[^>]*disabled=""[^>]*>Save hub pin<\/button>/);
    const selected = await render(HubLocationEditor, 'Hub/Pin', { hub: { ...base, latitude: 14.16, longitude: 121.24 }, url: '/hub/location' });
    assert.match(selected, /Loading map… Your pin is still selected/);
    assert.doesNotMatch(selected, /<button[^>]*disabled=""[^>]*>Save hub pin<\/button>/);
});

test('company owners can find the pin editor even when a hub has no saved coordinates', async () => {
    const company = { id: 1, name: 'Test Company', base_shipping_cents: 6000, included_weight_grams: 1000, additional_kg_cents: 2000, shipping_earnings_model: 'standard', tariff_version: 1 };
    const hub = { id: 2, name: 'Test Hub', code: 'TEST-HUB', tier: 'local_bayan_hub', city_municipality: 'Calamba', province: 'Laguna', parent_hub_id: null, postal_codes: [], is_active: true, latitude: null, longitude: null, address: 'Test Address', coverage_token: 'a'.repeat(64), location_token: 'b'.repeat(64) };
    const props = { ...overview(false), company, companies: [company], hubs: [hub], staff: [], basePath: '/hub/company-setup', canProvision: true };
    const owner = await render(CompanySetup, 'Hub/CompanySetup', props);
    assert.match(owner, /Edit map pin/);
    const viewer = await render(CompanySetup, 'Hub/CompanySetup', { ...props, canProvision: false });
    assert.doesNotMatch(viewer, /Edit map pin/);
});

test('company navigation and overview open permitted parcel pages without offering a forbidden scanner', async () => {
    const html = await render(Overview, 'Hub/Dashboard', overview(false));
    assert.doesNotMatch(html, /href="\/hub\/scan"/);
    assert.doesNotMatch(html, />Scan parcel</);
    assert.match(html, /href="\/hub\/deliveries"/);
    assert.match(html, />View parcels</);
    assert.match(html, /href="\/hub\/counter"/);
});

test('assigned handlers retain scanner access in navigation and both overview actions', async () => {
    const html = await render(Overview, 'Hub/Dashboard', overview(true));
    assert.ok((html.match(/href="\/hub\/scan"/g) ?? []).length >= 3);
    assert.match(html, /Scan Station/);
    assert.match(html, />Scan parcel</);
    assert.match(html, />Open scanner</);
});

test('a changed handler permission removes the scanner on a fresh render', async () => {
    const granted = await render(Overview, 'Hub/Dashboard', overview(true));
    assert.match(granted, /href="\/hub\/scan"/);
    const revoked = await render(Overview, 'Hub/Dashboard', overview(false));
    assert.doesNotMatch(revoked, /href="\/hub\/scan"/);
});

const failure = { status: 500, title: 'Could not open this page', description: 'Return to Overview and check the latest record before repeating an update.',
    requestId: '34d8a854-337a-4d47-a214-3e7f5707984e', overviewUrl: '/hub/dashboard', loginUrl: null, retryUrl: '/hub/manifests' };

test('read failures render without account props and retain a safe read-only retry and reference', async () => {
    const html = await render(ErrorPage, 'Hub/Error', failure);
    assert.match(html, /BagooPH Logistics/);
    assert.match(html, /id="logistics-error-title"/);
    assert.match(html, /href="\/hub\/manifests"/);
    assert.match(html, /Try again/);
    assert.match(html, /href="\/hub\/dashboard"/);
    assert.match(html, /Reference: 34d8a854-337a-4d47-a214-3e7f5707984e/);
    assert.doesNotMatch(html, /<form|SQLSTATE|vendor\/laravel|Stack trace|Nothing was changed/);
});

test('failed updates offer navigation without automatically repeating the operation', async () => {
    const html = await render(ErrorPage, 'Hub/Error', { ...failure, retryUrl: null });
    assert.doesNotMatch(html, /Try again|<form|href="\/hub\/manifests"/);
    assert.match(html, /Check|check the latest record/);
    assert.match(html, /Open Overview/);
});

test('expired sessions offer sign-in and forbidden records do not invent technical details or a success result', async () => {
    const expired = await render(ErrorPage, 'Hub/Error', { ...failure, status: 419, title: 'Sign in again', loginUrl: '/hub/login', retryUrl: null, requestId: null });
    assert.match(expired, /href="\/hub\/login"/);
    assert.doesNotMatch(expired, /Reference:|Try again|saved successfully/i);
    const forbidden = await render(ErrorPage, 'Hub/Error', { ...failure, status: 403, title: 'Access unavailable', overviewUrl: '/hub/dashboard?hub_id=2', requestId: null, retryUrl: null });
    assert.match(forbidden, /href="\/hub\/dashboard\?hub_id=2"/);
    assert.doesNotMatch(forbidden, /Reference:|Try again|saved successfully/i);
});
