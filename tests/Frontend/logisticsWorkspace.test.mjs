import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const bundle = await build({ stdin: { contents: `export * from '@/utils/hubWorkspace'; export * from '@/Components/Logistics/Workspace'; export {default as HubMap} from '@/Components/Logistics/HubMap'; export {default as RiderPicker} from '@/Components/Logistics/RiderPicker'; export {default as VehiclePicker} from '@/Components/Logistics/VehiclePicker';`, resolveDir: resolve(import.meta.dirname, '../..'), loader: 'tsx' }, bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', write: false, loader: { '.css': 'empty' }, plugins: [{ name: 'test-css', setup(build) { build.onResolve({ filter: /\.css$/ }, () => ({ path: 'style', namespace: 'test-css' })); build.onLoad({ filter: /.*/, namespace: 'test-css' }, () => ({ contents: '', loader: 'js' })); } }] });
const file = join(temporary, 'logistics.mjs');writeFileSync(file, bundle.outputFiles[0].text);
const ui = await import(pathToFileURL(file).href);
const hub = (id, tier, parent_hub_id = null, company = 1) => ({ id, name: `Facility ${id}`, tier, parent_hub_id, logistics_company_id: company });
const origin = hub(1, 'local_bayan_hub', 2), mother = hub(2, 'regional_mother_hub'), destination = hub(3, 'local_bayan_hub', 2), otherMother = hub(4, 'regional_mother_hub'), otherChild = hub(5, 'local_bayan_hub', 4), foreign = hub(6, 'regional_mother_hub', null, 2);
const hubs = [origin, mother, destination, otherMother, otherChild, foreign];

test('child transfer choices preserve the configured parent and exclude child shortcuts or another company', () => {
    assert.deepEqual(ui.nextTransferHubs(hubs, origin).map(h => h.id), [2]);
    assert.deepEqual(ui.nextTransferHubs(hubs, undefined), []);
});
test('a Mother Hub offers its children and other same-company Mother Hubs, never another parent’s child', () => {
    assert.deepEqual(ui.nextTransferHubs(hubs, mother).map(h => h.id), [1, 3, 4]);
});
test('planning or sealing a manifest never indicates that a receiving hub has taken custody', () => {
    for (const status of ['draft', 'sealed', 'dispatched']) {
        const steps = ui.transferSteps(status, status !== 'draft');
        assert.notEqual(steps.find(s => s.title === 'Receive').state, 'done');
        assert.notEqual(steps.find(s => s.title === 'Close').state, 'done');
    }
    assert.equal(ui.transferSteps('sealed', true).find(s => s.state === 'current').title, 'Depart');
    assert.equal(ui.transferSteps('dispatched', true).find(s => s.state === 'current').title, 'Receive');
    assert.equal(ui.transferSteps('received', true).find(s => s.state === 'current').title, 'Close');
    assert.ok(ui.transferSteps('closed', true).every(s => s.state === 'done'));
});
test('bag loading, departure and opening remain separate from actual parcel receipts', () => {
    assert.equal(ui.bagSteps('sealed', false).find(s => s.state === 'current').title, 'Load truck');
    assert.equal(ui.bagSteps('sealed', true).find(s => s.state === 'current').title, 'Depart');
    assert.equal(ui.bagSteps('in_transit', true).find(s => s.state === 'current').title, 'Verify seal');
    assert.equal(ui.bagSteps('opened', true).find(s => s.state === 'current').title, 'Receive');
    assert.ok(ui.bagSteps('received', true).every(s => s.state === 'done'));
});
test('maps use valid saved coordinates without fabricating a location', () => {
    assert.deepEqual(ui.hubPoint({ latitude: 14.2, longitude: 121.1 }), [14.2, 121.1]);
    for (const coordinates of [{}, { latitude: null, longitude: 121 }, { latitude: 0, longitude: 0 }, { latitude: 91, longitude: 121 }, { latitude: 14, longitude: 181 }, { latitude: NaN, longitude: 121 }]) assert.equal(ui.hubPoint(coordinates), null);
    const markup = renderToStaticMarkup(createElement(ui.HubMap, { hubs: [origin] }));
    assert.match(markup, /No map pins saved yet/);assert.match(markup, /address/);assert.doesNotMatch(markup, /leaflet-container/);
});
test('vehicle cards retain a disabled, required single selection with separate plate and driver labels', () => {
    const markup = renderToStaticMarkup(createElement(ui.VehiclePicker, { vehicles: [{ id: 9, plate_number: 'BG-TEST-9', driver_name: 'Test Driver' }], value: '9', disabled: true, onChange() {} }));
    assert.match(markup, /<fieldset disabled/);assert.match(markup, /type="radio"/);assert.match(markup, /required/);assert.match(markup, /checked/);assert.match(markup, /BG-TEST-9/);assert.match(markup, /Test Driver/);
});
test('route cards escape saved hub names and distinguish the actual current hub', () => {
    const markup = renderToStaticMarkup(createElement(ui.HubRoute, { stops: [{ ...origin, name: '<script>bad</script>' }, mother], currentHubId: 1 }));
    assert.match(markup, /&lt;script&gt;/);assert.doesNotMatch(markup, /<script>/);assert.equal((markup.match(/Current hub/g) ?? []).length, 1);
});

test('rider cards show only the parcel’s server-authorized choices and never describe assignment as custody', () => {
    const riders = [{ id: 11, name: 'Eligible area rider', assigned_barangay: 'Poblacion III', vehicle_type: 'motorcycle' }, { id: 12, name: 'Unavailable area rider', assigned_barangay: 'Poblacion III', vehicle_type: 'motorcycle' }];
    const html = renderToStaticMarkup(createElement(ui.RiderPicker, { riders, eligibleIds: [11], value: '11', onChange() {} }));
    assert.match(html, /Eligible area rider/);assert.doesNotMatch(html, /Unavailable area rider|Current holder/);assert.match(html, /type="radio"/);assert.match(html, /checked/);
});

test('the progress rail can receive keyboard focus and exposes the current and completed steps as text', () => {
    const html = renderToStaticMarkup(createElement(ui.HubStepRail, { steps: ui.transferSteps('sealed', true) }));
    assert.match(html, /tabindex="0"/);assert.equal((html.match(/aria-current="step"/g) ?? []).length, 1);
    assert.match(html, /Completed/);assert.match(html, /Current step/);assert.match(html, /Waiting/);
});
