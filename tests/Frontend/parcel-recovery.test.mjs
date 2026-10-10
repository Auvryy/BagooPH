import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { buildSync } from 'esbuild';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createInertiaApp } from '@inertiajs/react';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-recovery-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const bundle = buildSync({
    stdin: { contents: `export { default as Recovery } from '@/Pages/Hub/ParcelRecovery'; export { default as Manifests } from '@/Pages/Hub/Manifests';`, resolveDir: resolve(import.meta.dirname, '../..'), loader: 'tsx' },
    bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', loader: { '.css': 'empty' }, write: false,
});
const path = join(temporary, 'recovery.mjs');
writeFileSync(path, bundle.outputFiles[0].text);
const { Recovery, Manifests } = await import(pathToFileURL(path).href);
globalThis.route = name => name ? `/${name.replaceAll('.', '/')}` : { current: () => false, has: () => true };
after(() => delete globalThis.route);
const observation = { id: 5, reference: 'OBS-5', kind: 'wrong_hub', barcode: 'BGO-RECOVERY', observedHub: 'Observed Bayan Hub',
    reporter: 'Ana Santos', reportedAt: '2026-10-10T12:00:00Z', state: 'needs_review', url: '/hub/parcel-recovery/5', manifestUrl: '/hub/manifests/2' };
const recoveryCase = { id: 3, reference: 'PRC-3', state: 'authorized', version: 1, legIndex: 0, reason: 'Recover the actual parcel.', currentHubId: null,
    nextHubId: null, canPrepareTransfer: false, transferUrl: null, originalManifestUrl: '/hub/manifests/1', events: [], transfers: [] };
const detail = { ...observation, notes: 'An unexpected parcel was observed.', blocker: null, canAuthorize: false, canIntake: false, canWithdraw: false,
    sourceToken: null, custody: 'In transport on the original manifest', expectedHub: 'Expected Mother Hub',
    route: [{ id: 1, name: 'Observed Bayan Hub', code: 'BH-1', tier: 'local_bayan_hub' }, { id: 2, name: 'Expected Mother Hub', code: 'MH-1', tier: 'regional_mother_hub' }], case: null };
async function render(Component, name, props) {
    let view;
    const result = await createInertiaApp({
        page: { component: name, props: { auth: { user: { id: 1, name: 'Ana Santos', role: 'logistics' } }, flash: {}, errors: {}, ...props }, url: '/hub/parcel-recovery', version: null },
        resolve: () => Component, setup: ({ App, props }) => { view = createElement(App, props); return view; }, render: () => renderToStaticMarkup(view),
    });
    return result.body;
}
function props(selected) {
    return { observations: { data: [observation], links: [] }, selectedObservation: selected, filters: { status: 'open' },
        basePath: '/hub/parcel-recovery', manifestPath: '/hub/manifests', requestToken: 'REQUEST', creationToken: null, vehicles: [] };
}

test('unknown parcels show a blocker without authorizing or pretending to receive them', async () => {
    const html = await render(Recovery, 'Hub/ParcelRecovery', props({ ...detail, route: [], expectedHub: null, blocker: 'No supported original parcel custody was found.' }));
    assert.match(html, /No supported original parcel custody was found/);
    assert.match(html, /Not established/);
    assert.doesNotMatch(html, /Authorize recovery|Record actual intake|Prepare recovery manifest/);
});
test('company review gives authority without rendering a handler intake action', async () => {
    const html = await render(Recovery, 'Hub/ParcelRecovery', props({ ...detail, canAuthorize: true, sourceToken: 'SOURCE' }));
    assert.match(html, /Authorize recovery/);
    assert.match(html, /assigned receiving handler then scans/);
    assert.doesNotMatch(html, /Record actual intake|Actual waybill/);
});
test('an assigned intake handler sees a real waybill action and the required recovery hubs', async () => {
    const html = await render(Recovery, 'Hub/ParcelRecovery', props({ ...detail, state: 'authorized', canIntake: true, case: recoveryCase }));
    assert.match(html, /Actual waybill/);
    assert.match(html, /Record actual intake/);
    assert.match(html, /Expected Mother Hub/);
    assert.doesNotMatch(html, /Authorize recovery|Prepare recovery manifest/);
});
test('a missing recovery vehicle has an explicit blocker and disabled preparation', async () => {
    const html = await render(Recovery, 'Hub/ParcelRecovery', props({ ...detail, state: 'held', case: { ...recoveryCase, state: 'held', canPrepareTransfer: true, currentHubId: 1, nextHubId: 2 } }));
    assert.match(html, /needs an eligible vehicle and available driver/);
    assert.match(html, /<button[^>]*disabled=""[^>]*>Prepare recovery manifest/);
    assert.doesNotMatch(html, /Record actual intake/);
});
test('completed recovery retains its original receipt and history while explaining the separate discrepancy review', async () => {
    const html = await render(Recovery, 'Hub/ParcelRecovery', props({ ...detail, state: 'completed', case: { ...recoveryCase, state: 'completed', events: [
        { reference: 'PRE-1', type: 'completed', actor: 'Ana Santos', hub: 'Expected Mother Hub', notes: 'The actual parcel was received.', recordedAt: '2026-10-10T12:15:00Z' },
    ] } }));
    assert.match(html, /original expected hub received the parcel/i);
    assert.match(html, /company still reviews the observation/);
    assert.match(html, /The actual parcel was received/);
    assert.match(html, /href="\/hub\/manifests\/1"/);
    assert.doesNotMatch(html, /Record actual intake|Authorize recovery|Prepare recovery manifest|Mark delivered/);
});
test('case history stays visible without rendering links to inaccessible manifests', async () => {
    const html = await render(Recovery, 'Hub/ParcelRecovery', props({ ...detail, manifestUrl: null, state: 'held', case: {
        ...recoveryCase, state: 'held', originalManifestUrl: null, transfers: [{ reference: 'MAN-RETAINED', status: 'closed', url: null }],
    } }));
    assert.match(html, /MAN-RETAINED/);
    assert.doesNotMatch(html, /Open the observation manifest|Original receiving manifest|href="null"/);
});
