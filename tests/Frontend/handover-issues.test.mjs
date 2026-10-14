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
    stdin: { contents: `export { default as Index } from '@/Pages/Handover/Index'; export { default as Show } from '@/Pages/Handover/Show'; export { default as OwnedRecovery } from '@/Pages/CustodyRecovery/Own'; export { default as Deliveries } from '@/Pages/Courier/Deliveries';`, resolveDir: resolve(import.meta.dirname, '../..'), loader: 'tsx' },
    bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', loader: { '.css': 'empty' }, write: false,
});
const path = join(temporary, 'handover.mjs');
writeFileSync(path, bundle.outputFiles[0].text);
const { Index, Show, OwnedRecovery, Deliveries } = await import(pathToFileURL(path).href);
globalThis.route = name => name ? `/${name.replaceAll('.', '/')}` : { current: () => false, has: () => true };
after(() => delete globalThis.route);

const original = { delivery_status: 'assigned_pickup', order_status: 'ready_for_pickup', custody_label: 'Seller', checkpoint_reference: 'CP-ORIGINAL' };
const summary = { id: 5, reference: 'HI-REPORT', state: 'pending_review', kind: 'missed_scan', tracking_number: 'BGO-WAYBILL',
    order_number: 'BGO-ORDER', reporter_name: 'Ana Santos', created_at: '2026-10-13T13:00:00Z' };
const detail = { ...summary, version: 1, reported_holder: 'Rider Juan', reported_location: 'Origin Bayan Hub desk', notes: 'A handover was observed without its expected scan.',
    recovery: { held: false, source_token: 'RECOVERY-SOURCE', mode: null, fulfillment_required: false, can_confirm_fulfillment: false, can_authorize: false, holder_options: [], grant: null },
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
    const html = await render(Show, 'Handover/Show', { issue: { ...detail, state: 'needs_recovery', recovery: { ...detail.recovery, held: true } }, isOnline: false });
    assert.match(html, /Needs recovery/);
    assert.match(html, /The report remains open/);
    assert.match(html, /Ordinary parcel actions are paused/);
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

const heldRecovery = { ...detail.recovery, held: true, mode: 'pickup' };
const grant = { id: 7, reference: 'HRG-CURRENT', holder_id: 1, holder_name: 'Juan Santos', hub: { id: 2, name: 'Origin Bayan Hub', address: 'Receiving desk' },
    expires_at: '2026-10-14T13:00:00Z', mode: 'pickup', acknowledged: false, closed: false, blocker: null,
    can_acknowledge: false, can_receive: false, can_withdraw: false, expected_version: 3, source_token: 'CURRENT', order_number: summary.order_number, tracking_number: summary.tracking_number };

test('seller corrects omitted fulfillment explicitly without choosing or inventing a rider', async () => {
    const html = await render(Show, 'Handover/Show', { issue: { ...detail, state: 'needs_recovery', recovery: { ...heldRecovery, fulfillment_required: true, can_confirm_fulfillment: true } } }, 'seller');
    assert.match(html, /Confirm fulfillment now/);
    assert.match(html, /accepted, packed and labelled/);
    assert.match(html, /maxLength="255"/);
    assert.match(html, /minLength="10" maxLength="1000"/);
    assert.doesNotMatch(html, /Authorize recovery|Acknowledge possession|Receive recovery parcel/);
});

test('independent authorization starts with no selected holder and requires evidence confirmations', async () => {
    const html = await render(Show, 'Handover/Show', { issue: { ...detail, state: 'needs_recovery', recovery: { ...heldRecovery, can_authorize: true, holder_options: [{ id: 2, name: 'Juan Santos', status: 'suspended' }] } } }, 'admin');
    assert.match(html, /Choose the evidenced holder/);
    assert.match(html, /Restricted handover access/);
    assert.match(html, /independently verified the named holder/);
    assert.match(html, /reviewed the original supporting document/);
    assert.match(html, /Authorize recovery/);
    assert.doesNotMatch(html, /Acknowledge possession|Receive recovery parcel/);
});

test('rider acknowledgement and receiving-handler receipt have distinct physical confirmations', async () => {
    for (const receive of [false, true]) {
        const html = await render(Show, 'Handover/Show', { issue: { ...detail, state: 'needs_recovery', recovery: { ...heldRecovery, grant: { ...grant, acknowledged: receive, can_acknowledge: !receive, can_receive: receive } } } }, receive ? 'logistics' : 'courier');
        assert.match(html, /Actual parcel waybill/);
        assert.match(html, receive ? /I actually received this exact parcel/ : /I physically hold this exact parcel now/);
        assert.match(html, receive ? /Receive recovery parcel/ : /Acknowledge possession/);
        assert.doesNotMatch(html, receive ? /Acknowledge possession/ : /Receive recovery parcel/);
    }
});

test('expired authorization offers review withdrawal while keeping the hold and hiding receipt actions', async () => {
    const html = await render(Show, 'Handover/Show', { issue: { ...detail, state: 'needs_recovery', recovery: { ...heldRecovery, grant: { ...grant, blocker: 'The authorization expired. The parcel remains on hold.', can_withdraw: true } } } });
    assert.match(html, /authorization expired/);
    assert.match(html, /Withdraw authorization/);
    assert.match(html, /Expiry or withdrawal keeps the hold/);
    assert.doesNotMatch(html, /Acknowledge possession|Receive recovery parcel/);
});

test('restricted holder sees only its authorized handover and retains waiting-for-receipt guidance', async () => {
    for (const acknowledged of [false, true]) {
        const html = await render(OwnedRecovery, 'CustodyRecovery/Own', { grants: [], handoverGrants: [{ ...grant, acknowledged, can_acknowledge: !acknowledged }], requestToken: 'TOKEN' }, 'courier');
        assert.match(html, /only your authorized handover/);
        assert.equal(html.includes('Acknowledge possession'), !acknowledged);
        assert.equal(html.includes('named hub must still scan its actual receipt'), acknowledged);
        assert.doesNotMatch(html, /No current handover|Receive recovery parcel|Authorize recovery|Rider workspace/);
    }
});

test('recovered history labels describe the actual correction and show no further recovery forms', async () => {
    const html = await render(Show, 'Handover/Show', { issue: { ...detail, state: 'recovered', events: [{ ...detail.events[0], type: 'recovery_received' }] } });
    assert.match(html, /Recovery received/);
    assert.match(html, /Actual hub receipt recorded/);
    assert.doesNotMatch(html, /Report dismissed|Authorize recovery|Acknowledge possession|Receive recovery parcel/);
});

test('held final-mile parcel points to recovery without offering delivery or failed-attempt actions', async () => {
    const place = { name: 'Destination Bayan Hub', address: 'Receiving desk', phone: null };
    const task = { id: 5, trackingNumber: summary.tracking_number, orderNumber: summary.order_number, status: 'assigned_to_rider',
        handoverHold: true, nextAction: null, recipient: place, destinationHub: place, assignedAt: null,
        payment: { method: 'COD', codAmount: 100, codAmountCents: 10000 }, failureAttempts: 0, failureReason: null, canMessage: true };
    const html = await render(Deliveries, 'Courier/Deliveries', { queues: { finalMileTasks: [task] }, recoveryRequestToken: 'TOKEN' }, 'courier');
    assert.match(html, /Handover hold/);
    assert.match(html, /Open handover recovery/);
    assert.doesNotMatch(html, />Start delivery<|>Record delivery<|>Report failed attempt</);
});

const hubGrant = { ...grant, mode: 'hub_manifest', manifest_reference: 'MFT-ORIGINAL', withdraw_source_token: 'WITHDRAW-CURRENT',
    linked_recovery_reference: null, continuation_url: null };

test('hub source selection requires an explicit choice before it offers holder authorization', async () => {
    const html = await render(Show, 'Handover/Show', { issue: { ...detail, state: 'needs_recovery', recovery: { ...heldRecovery, mode: null },
        hub_recovery: { grant: null, options: [{ key: 'source-9', mode: 'hub_manifest', reference_id: 9, label: 'Corrective receipt: MFT-ORIGINAL',
            source_token: 'CURRENT', holder_options: [{ id: 1, name: 'Juan Santos', status: 'active' }] }] } } }, 'logistics');
    assert.match(html, /Choose the supported original source/);
    assert.match(html, /MFT-ORIGINAL/);
    assert.match(html, /Unlisted receipt|unlisted receipt/);
    assert.doesNotMatch(html, /Authorize actual hub recovery|Acknowledge possession|Receive recovery parcel/);
});

test('hub holder acknowledgement preserves original scans and requires current physical confirmation', async () => {
    const html = await render(Show, 'Handover/Show', { issue: { ...detail, state: 'needs_recovery', recovery: { ...heldRecovery, mode: null },
        hub_recovery: { options: [], grant: { ...hubGrant, can_acknowledge: true } } } }, 'courier');
    assert.match(html, /Acknowledge possession/);
    assert.match(html, /does not create an old hub or transport scan/);
    assert.match(html, /maxLength="255"/);
    assert.match(html, /minLength="10" maxLength="1000"/);
    assert.doesNotMatch(html, /Receive recovery parcel|Authorize actual hub recovery/);
});

test('only the receiving-handler capability renders the corrective receiving action', async () => {
    const html = await render(Show, 'Handover/Show', { issue: { ...detail, state: 'needs_recovery', recovery: { ...heldRecovery, mode: null },
        hub_recovery: { options: [], grant: { ...hubGrant, acknowledged: true, can_receive: true } } } }, 'logistics');
    assert.match(html, /Receive recovery parcel/);
    assert.match(html, /Another eligible handler must receive it/);
    assert.match(html, /I actually received this exact parcel/);
    assert.doesNotMatch(html, /Acknowledge possession|Authorize actual hub recovery/);
});

test('linked physical recovery follows its original route without a competing direct receiving action', async () => {
    const html = await render(Show, 'Handover/Show', { issue: { ...detail, state: 'needs_recovery', recovery: { ...heldRecovery, mode: null },
        hub_recovery: { options: [], grant: { ...hubGrant, mode: 'hub_existing', acknowledged: true,
            linked_recovery_reference: 'PRC-ORIGINAL', continuation_url: '/hub/parcel-recovery/8' } } } }, 'logistics');
    assert.match(html, /Continue original recovery/);
    assert.match(html, /href="\/hub\/parcel-recovery\/8"/);
    assert.match(html, /PRC-ORIGINAL/);
    assert.doesNotMatch(html, /Receive recovery parcel|Acknowledge possession/);
});

test('a changed hub grant retains its hold and offers only the permitted withdrawal', async () => {
    const html = await render(Show, 'Handover/Show', { issue: { ...detail, state: 'needs_recovery', recovery: { ...heldRecovery, mode: null },
        hub_recovery: { options: [], grant: { ...hubGrant, source_token: '', blocker: 'Recovery authority changed.', can_withdraw: true } } } }, 'admin');
    assert.match(html, /Recovery authority changed/);
    assert.match(html, /Withdraw hub authorization/);
    assert.match(html, /parcel stays on hold/);
    assert.doesNotMatch(html, /Receive recovery parcel|Acknowledge possession/);
});
