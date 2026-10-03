import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { buildSync } from 'esbuild';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createInertiaApp } from '@inertiajs/react';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const bundle = buildSync({
    stdin: {
        contents: `export * from '@/utils/courier';
            export { default as Deliveries } from '@/Pages/Courier/Deliveries';
            export { default as Profile } from '@/Pages/Courier/Profile';
            export { default as Trips } from '@/Pages/Courier/Earnings';
            export { default as Messages } from '@/Pages/Courier/Messages';`,
        resolveDir: resolve(import.meta.dirname, '../..'), loader: 'tsx',
    },
    bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', write: false,
    loader: { '.css': 'empty' },
});
const bundlePath = join(temporary, 'courier.mjs');
writeFileSync(bundlePath, bundle.outputFiles[0].text);
const ui = await import(pathToFileURL(bundlePath).href);
const scope = { company: 'Bagoo Dispatch', hub: 'Assigned Bayan Hub', hubCode: 'BH-TEST', isAssigned: true, isOperational: true };
const auth = { user: { id: 5, name: 'Ana Rider', email: 'rider@bagoo.test', role: 'courier' } };
async function renderPage(Component, name, props) {
    let view;
    const result = await createInertiaApp({
        page: { component: `Courier/${name}`, props: { ...props, auth, flash: {}, cartCount: 0 }, url: '/courier/deliveries', version: null },
        resolve: () => Component,
        setup: ({ App, props: appProps }) => { view = createElement(App, appProps); return view; },
        render: () => renderToStaticMarkup(view),
    });
    return result.body;
}
function directionDestinations(html) {
    return [...new Set([...html.matchAll(/href="(https:\/\/www\.google\.com\/maps\/dir\/[^\"]+)"/g)]
        .map((match) => new URL(match[1].replaceAll('&amp;', '&')).searchParams.get('destination')))];
}
const pickup = {
    id: 1, trackingNumber: 'BGO-PICKUP', orderNumber: 'BGO-ORDER', status: 'assigned_pickup', itemCount: 2,
    merchant: { name: 'Seller store', address: 'Seller road, Laguna', phone: '09171234567' },
    originHub: { name: 'Origin Bayan Hub', address: 'Origin hub road', latitude: 14.1, longitude: 121.2 },
    assignedAt: null, nextAction: 'confirm_pickup', canMessage: true,
};
const finalMile = {
    id: 2, trackingNumber: 'BGO-DROP', orderNumber: 'BGO-ORDER', status: 'assigned_to_rider',
    recipient: { name: 'Saved recipient', address: 'Saved buyer road', latitude: 14.5, longitude: 121.6, phone: '+639171234567' },
    destinationHub: { name: 'Destination Bayan Hub', address: 'Destination hub road', latitude: 14.3, longitude: 121.4 },
    payment: { method: 'COD', codAmount: 950.12 }, assignedAt: null, nextAction: 'start_delivery', canMessage: true,
};

test('portal navigation stays on the root or courier subdomain path', () => {
    assert.equal(ui.courierPath('/profile/account', 'bagoo.test'), '/courier/profile/account');
    assert.equal(ui.courierPath('/profile/password', 'courier.bagoo.test'), '/profile/password');
    assert.equal(ui.courierPath('messages', 'courier.localhost'), '/messages');
    assert.equal(ui.courierPath('/deliveries', 'localhost'), '/courier/deliveries');
});
test('directions reject absent or invalid coordinates without inventing a pin', () => {
    assert.equal(ui.directionsUrl({ name: 'Hub', code: 'BH-TEST' }), null);
    assert.equal(ui.directionsUrl({ latitude: null, longitude: null }), null);
    assert.equal(ui.directionsUrl({ address: '   ', latitude: NaN, longitude: Infinity }), null);
    assert.equal(new URL(ui.directionsUrl({ latitude: 0, longitude: 0 })).searchParams.get('destination'), '0,0');
    assert.equal(new URL(ui.directionsUrl({ address: 'Saved street', latitude: 91, longitude: 181 })).searchParams.get('destination'), 'Saved street');
});
test('address fallback is encoded as one destination parameter', () => {
    const address = 'Block 2 & Gate #3, Laguna?api=invalid';
    const url = new URL(ui.directionsUrl({ address }));
    assert.equal(url.searchParams.get('destination'), address);
    assert.equal(url.searchParams.get('api'), '1');
    assert.equal(url.searchParams.get('dir_action'), 'navigate');
    assert.equal([...url.searchParams].length, 3);
});
test('street map rejects missing, nonnumeric, and unprojectable pins', () => {
    for (const place of [{}, { latitude: null, longitude: null }, { latitude: '14', longitude: 121 }, { latitude: 86, longitude: 121 }, { latitude: 14, longitude: Infinity }]) {
        assert.equal(ui.courierMapCoordinates(place), null);
    }
    assert.deepEqual(ui.courierMapCoordinates({ latitude: 0, longitude: 0 }), [0, 0]);
    assert.deepEqual(ui.courierMapCoordinates({ latitude: 14.5, longitude: 121.6 }), [14.5, 121.6]);
});
test('map selection survives refreshed objects, falls back after removal, and clears on an empty queue', () => {
    const jobs = [{ key: 'pickup-1' }, { key: 'pickup-2' }];
    assert.equal(ui.selectCourierMapJob(jobs, 'pickup-2'), jobs[1]);
    const refreshed = jobs.map((job) => ({ ...job }));
    assert.equal(ui.selectCourierMapJob(refreshed, 'pickup-2'), refreshed[1]);
    assert.equal(ui.selectCourierMapJob([jobs[0]], 'pickup-2'), jobs[0]);
    assert.equal(ui.selectCourierMapJob([], 'pickup-2'), null);
});
test('telephone links and recorded display values have truthful missing states', () => {
    assert.equal(ui.telephoneUrl('+63 917 123 4567'), 'tel:+639171234567');
    assert.equal(ui.telephoneUrl('not provided'), null);
    assert.equal(ui.telephoneUrl('123'), null);
    assert.equal(ui.courierDate('invalid'), 'Not recorded');
    assert.equal(ui.courierMoney(null), 'Amount not provided');
    assert.equal(ui.courierMoney(Infinity), 'Amount not provided');
    assert.match(ui.courierDate('2026-10-02T18:00:00Z'), /Oct 3, 2026/);
});
test('claimed pickup shows seller directions and collection action', async () => {
    const html = await renderPage(ui.Deliveries, 'Deliveries', { scope, isOnline: true, queues: { pickupTasks: [pickup] } });
    assert.deepEqual(directionDestinations(html), ['Seller road, Laguna']);
    assert.match(html, /Confirm pickup/);
    assert.match(html, /No usable saved pin/);
    assert.match(html, /Use address-based directions/);
    assert.match(html, /phase=pickup/);
    assert.doesNotMatch(html, /Cash due at delivery/);
});
test('collected pickup switches directions to origin intake and removes collection action', async () => {
    const html = await renderPage(ui.Deliveries, 'Deliveries', { scope, isOnline: false, queues: { pickupTasks: [{ ...pickup, status: 'picked_up', nextAction: 'await_origin_hub_scan' }] } });
    assert.deepEqual(directionDestinations(html), ['14.1,121.2']);
    assert.match(html, /Hub staff must scan/);
    assert.doesNotMatch(html, />Confirm pickup</);
    assert.match(html, /Existing assigned parcels remain available/);
});
test('available pickup cannot be claimed off duty or beyond capacity', async () => {
    for (const props of [{ isOnline: false }, { isOnline: true, stats: { activePickups: 5, activePickupLimit: 5 } }]) {
        const html = await renderPage(ui.Deliveries, 'Deliveries', { scope, ...props, queues: { availablePickups: [{ ...pickup, nextAction: 'claim_pickup', canMessage: false }] } });
        assert.match(html, /<button[^>]+disabled=""[^>]*>Claim pickup<\/button>/);
        assert.doesNotMatch(html, /phase=pickup/);
    }
});
test('final-mile collection targets the destination hub with exact server COD', async () => {
    const html = await renderPage(ui.Deliveries, 'Deliveries', { scope, isOnline: true, queues: { finalMileTasks: [finalMile] } });
    assert.deepEqual(directionDestinations(html), ['14.3,121.4']);
    assert.match(html, /Start delivery/);
    assert.match(html, /Map of Destination Bayan Hub/);
    assert.match(html, /aria-label="Zoom in"/);
    assert.match(html, /aria-label="Zoom out"/);
    assert.match(html, /₱950\.12/);
});
test('delivery leg uses the saved buyer pin and records delivery separately from receipt', async () => {
    const html = await renderPage(ui.Deliveries, 'Deliveries', { scope, isOnline: false, queues: { finalMileTasks: [{ ...finalMile, status: 'out_for_delivery', nextAction: 'complete_delivery' }] } });
    assert.deepEqual(directionDestinations(html), ['14.5,121.6']);
    assert.match(html, /Record delivery/);
    assert.match(html, /Map of Saved buyer destination/);
    assert.match(html, /buyer confirms receipt separately/);
    assert.match(html, /phase=final_mile/);
});
test('unassigned dashboard presents an honest empty queue', async () => {
    const html = await renderPage(ui.Deliveries, 'Deliveries', { queues: {}, scope: {}, isOnline: false });
    assert.match(html, /Hub not assigned/);
    assert.match(html, /logistics company and hub assignment is needed/);
    assert.doesNotMatch(html, /BH-LBN|100%|Operational/);
    assert.deepEqual(directionDestinations(html), []);
    assert.doesNotMatch(html, /id="rider-job-map"/);
});
test('an available pickup map is explicitly a preview and never a claimed job', async () => {
    const html = await renderPage(ui.Deliveries, 'Deliveries', { scope, isOnline: true, queues: { availablePickups: [{ ...pickup, nextAction: 'claim_pickup', canMessage: false }] } });
    assert.match(html, /Pickup preview/);
    assert.match(html, /Claim the pickup before collecting/);
    assert.doesNotMatch(html, /Your selected stop/);
});
test('history explains current scope and avoids unsupported performance and payout claims', async () => {
    const html = await renderPage(ui.Trips, 'Earnings', { scope, isOnline: true, summary: { completedDeliveries: 0, completedToday: 0 }, trips: [] });
    assert.match(html, /previous hub assignments are not included/);
    assert.match(html, /No completed trips yet/);
    assert.doesNotMatch(html, /Lifetime|100%|Drop Rate|Verified/);
});
test('profile uses real assignment, missing vehicle values, and same-portal forms', async () => {
    const html = await renderPage(ui.Profile, 'Profile', {
        scope, isOnline: false,
        rider: { name: 'Ana Rider', email: 'rider@bagoo.test', phone: null, email_verified_at: null, account_status: 'active', kyc_status: 'approved' },
        assignment: { company: scope.company, hub: scope.hub, hub_code: scope.hubCode, barangay: null },
        vehicle: { type: null, model: null, plate_number: null, fleet_status: null, license_number: null, registration_status: null },
    });
    assert.match(html, /Assigned Bayan Hub/);
    assert.match(html, /Contact details/);
    assert.match(html, /Verify.*email before making this change/);
    assert.doesNotMatch(html, /Verified Partner|Digital Pass|QR Code|Delete Account/);
});
test('message deep links select the correct parcel phase and show a labelled composer', async () => {
    const base = { delivery_id: 3, tracking_number: 'BGO-THREAD', order_number: null, last_message: null, last_time: null, unread_count: 0, messages: [] };
    const html = await renderPage(ui.Messages, 'Messages', {
        scope, isOnline: true, currentUserId: 5, selectedDeliveryId: 3, selectedPhase: 'final_mile',
        conversations: [
            { ...base, phase: 'pickup', can_send: false, participant: { id: 6, name: 'Seller', role: 'seller', shop_name: 'Seller store' } },
            { ...base, phase: 'final_mile', can_send: true, participant: { id: 7, name: 'Buyer recipient', role: 'buyer', shop_name: null } },
        ],
    });
    assert.match(html, /aria-label="Selected conversation"[^]*Buyer recipient/);
    assert.match(html, />Conversations<\/button>/);
    assert.match(html, /Send message/);
    assert.doesNotMatch(html, /conversation is read-only/);
});
