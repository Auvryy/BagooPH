import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { buildSync } from 'esbuild';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createInertiaApp } from '@inertiajs/react';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-handover-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const bundle = buildSync({
    stdin: { contents: `export { default as Index } from '@/Pages/Handover/Index'; export { default as Show } from '@/Pages/Handover/Show';`, resolveDir: resolve(import.meta.dirname, '../..'), loader: 'tsx' },
    bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', loader: { '.css': 'empty' }, write: false,
});
const path = join(temporary, 'handover.mjs');
writeFileSync(path, bundle.outputFiles[0].text);
const { Index, Show } = await import(pathToFileURL(path).href);
globalThis.route = name => name ? `/${name.replaceAll('.', '/')}` : { current: () => false, has: () => true };
after(() => delete globalThis.route);

const original = { delivery_status: 'assigned_pickup', order_status: 'ready_for_pickup', custody_label: 'Seller', checkpoint_reference: 'CP-ORIGINAL' };
const summary = { id: 5, reference: 'HI-REPORT', state: 'pending_review', kind: 'missed_scan', tracking_number: 'BGO-WAYBILL',
    order_number: 'BGO-ORDER', reporter_name: 'Ana Santos', created_at: '2026-10-13T13:00:00Z' };
const detail = { ...summary, version: 1, reported_holder: 'Rider Juan', reported_location: 'Origin Bayan Hub desk', notes: 'A handover was observed without its expected scan.',
    original, current: original, source_token: 'SOURCE', can_review: false, evidence_url: '/handover-issues/5/evidence',
    events: [{ id: 1, type: 'reported', actor_name: 'Ana Santos', actor_role: 'seller', notes: 'Reported observation.', created_at: summary.created_at, parcel_snapshot: original }] };

async function render(Component, name, props, role = 'logistics') {
    let view;
    const result = await createInertiaApp({
        page: { component: name, props: { auth: { user: { id: 1, name: 'Ana Santos', role } }, flash: {}, errors: {}, ...props }, url: '/handover-issues', version: null },
        resolve: () => Component, setup: ({ App, props }) => { view = createElement(App, props); return view; }, render: () => renderToStaticMarkup(view),
    });
    return result.body;
}

for (const role of ['seller', 'logistics', 'admin', 'courier']) {
    test(`${role} sees scoped report navigation and a meaningful empty state inside its own portal`, async () => {
        const html = await render(Index, 'Handover/Index', { issues: { data: [], total: 0, current_page: 1, last_page: 1, prev_page_url: null, next_page_url: null }, status: 'open', canReport: role !== 'admin', isOnline: false }, role);
        assert.match(html, /No handover reports here/);
        assert.match(html, /href="\/handover-issues"/);
        assert.match(html, /Reporting or reviewing an issue does not record a parcel handover/);
        assert.equal(html.includes('Report an issue'), role !== 'admin');
        assert.equal(html.includes('Rider workspace'), role === 'courier');
    });
}

test('reported possession stays distinct from original and current recorded custody', async () => {
    const html = await render(Show, 'Handover/Show', { issue: { ...detail, current: { ...original, delivery_status: 'picked_up', custody_label: 'Rider: Pedro' } }, isOnline: false });
    assert.match(html, /Reported holder/);
    assert.match(html, /Rider Juan/);
    assert.match(html, /Recorded when reported/);
    assert.match(html, /Recorded now/);
    assert.match(html, /Rider: Pedro/);
    assert.match(html, /Recorded parcel history has changed since this report/);
    assert.doesNotMatch(html, /Save review|Mark delivered|Record pickup/);
});

test('independent review requires an explicit decision and document acknowledgement', async () => {
    const html = await render(Show, 'Handover/Show', { issue: { ...detail, can_review: true }, isOnline: false }, 'admin');
    assert.match(html, /Independent review/);
    assert.match(html, /Choose a decision/);
    assert.match(html, /I reviewed the supporting document and current parcel facts/);
    assert.match(html, /required=""[^>]*type="checkbox"|type="checkbox"[^>]*required=""/);
    assert.match(html, /Save review/);
    assert.match(html, /href="\/handover-issues\/5\/evidence"/);
    assert.doesNotMatch(html, /storage\/|handover-issue-evidence|Mark picked up/);
});

test('a confirmed concern remains open for recovery without a fake receipt action', async () => {
    const html = await render(Show, 'Handover/Show', { issue: { ...detail, state: 'needs_recovery' }, isOnline: false });
    assert.match(html, /Needs recovery/);
    assert.match(html, /The report remains open/);
    assert.match(html, /confirmation here does not move the parcel or record a scan/);
    assert.doesNotMatch(html, /Save review|Mark delivered|Release hold|Record actual intake/);
});

test('dismissed history and pagination keep their own clear labels', async () => {
    const html = await render(Index, 'Handover/Index', { issues: { data: [{ ...summary, state: 'dismissed' }], total: 16, current_page: 2, last_page: 2, prev_page_url: '/handover-issues?page=1&status=dismissed', next_page_url: null }, status: 'dismissed', canReport: true, isOnline: false }, 'seller');
    assert.match(html, /Dismissed/);
    assert.match(html, /Page 2 of 2/);
    assert.match(html, /href="\/handover-issues\?page=1&amp;status=dismissed"/);
    assert.match(html, /View report/);
    assert.doesNotMatch(html, />Next</);
});
