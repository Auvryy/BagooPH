import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-waybill-actions-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const bundle = await build({ stdin: { contents: "export {default as Camera} from '@/Components/WaybillCamera'; export {default as Scanner} from '@/Pages/Hub/ScanStation'; export {default as Refresh} from '@/hooks/useOperationalRefresh';", resolveDir: resolve(import.meta.dirname, '../..'), loader: 'tsx' },
    bundle: true, packages: 'external', platform: 'node', format: 'esm', jsx: 'automatic', write: false,
    plugins: [{ name: 'controlled-waybill-interactions', setup(builder) {
        builder.onResolve({ filter: /^react$/ }, () => ({ path: 'react', namespace: 'waybill-hooks' }));
        builder.onLoad({ filter: /.*/, namespace: 'waybill-hooks' }, () => ({ contents: `
            export default {};
            export const useState = initial => globalThis.waybillHarness.state(initial);
            export const useRef = initial => globalThis.waybillHarness.ref(initial);
            export const useEffect = (effect,deps) => globalThis.waybillHarness.effect(effect,deps);
            export const useCallback = (fn,deps) => globalThis.waybillHarness.memo(fn,deps);
        ` }));
        builder.onResolve({ filter: /^@inertiajs\/react$/ }, () => ({ path: 'inertia', namespace: 'waybill-inertia' }));
        builder.onLoad({ filter: /.*/, namespace: 'waybill-inertia' }, () => ({ contents: `
            export const Head = () => null; export const Link = 'a';
            export const usePage = () => ({props:globalThis.waybillHarness.props});
            export const usePoll = (interval,options,policy) => { globalThis.waybillHarness.poll={interval,options,policy}; return {start(){},stop(){}}; };
            export const router = { on: (...args) => globalThis.waybillHarness.on(...args), reload: options => {globalThis.waybillHarness.reloads++; options.onSuccess?.(); options.onFinish?.();}, get(){} };
        ` }));
        builder.onResolve({ filter: /Layouts\/DashboardLayout$/ }, () => ({ path: 'layout', namespace: 'waybill-layout' }));
        builder.onLoad({ filter: /.*/, namespace: 'waybill-layout' }, () => ({ contents: 'export default props => props.children;' }));
    } }],
});
const file = join(temporary, 'interactions.mjs'); writeFileSync(file, bundle.outputFiles[0].text);
const ui = await import(pathToFileURL(file).href);
const tick = async () => { for (let i = 0; i < 20; i++) await Promise.resolve(); };
const deferred = () => { let resolve; const promise = new Promise(done => { resolve = done; }); return { promise, resolve }; };
function nodes(tree) {
    if (!tree || typeof tree !== 'object') return [];
    const children = tree.props?.children;
    return [tree, ...[children].flat(Infinity).flatMap(nodes)];
}
const hub = { id: 1, name: 'Origin Bayan Hub', code: 'BH-1', tier: 'local_bayan_hub', allows_self_pickup: true };
const props = { activeHub: hub, hubs: [hub], recentScans: [], pendingOriginIntake: [], counterPickups: [], counterTotal: 0, basePath: '/hub', stats: { parcels_in_hub: 0, ready_pickup: 0, dispatched_today: 0 } };
const reply = (confirmed = false) => ({ success: true, confirmed, message: confirmed ? 'Custody action recorded.' : 'Waybill verified.',
    prompt: { action: confirmed ? 'DISPATCH_TO_FEEDER' : 'RECEIVE_FROM_PICKUP_RIDER', requires_confirmation: !confirmed, expected_status: confirmed ? 'arrived_at_origin_hub' : 'picked_up', manifest_url: confirmed ? '/hub/manifests?hub_id=1' : null },
    delivery: { id: 7, tracking_number: 'BGO-ONE', status: confirmed ? 'arrived_at_origin_hub' : 'picked_up', delivery_type: 'doorstep', destination_bin: null,
        buyer: { name: 'Mara Santos', barangay: 'Poblacion III', city: 'Santa Cruz' }, order: { total_amount: 100, payment_method: 'cod', items: [] },
        guidance: { stage: 'Parcel stage', order_status: 'Picked up', custodian: confirmed ? hub.name : 'Pickup rider', action: confirmed ? 'manifest' : 'scan', can_act: true, next_step: confirmed ? 'Prepare a manifest' : 'Receive from rider', href: confirmed ? '/hub/manifests?hub_id=1' : '/hub/scan?hub_id=1', eligible_rider_ids: [] } } });

function harness(t, Component = ui.Camera, initial = { onScan() {} }) {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const originals = Object.fromEntries(['window', 'document', 'navigator', 'fetch', 'route'].map(name => [name, Object.getOwnPropertyDescriptor(globalThis, name)]));
    const slots = [], effects = [], listeners = new Map(); let cursor = 0, queued = [], disposed = false;
    const h = {
        on(name, callback) { const list = listeners.get(name) ?? new Set(); list.add(callback); listeners.set(name,list); return () => list.delete(callback); },
        emit(name, visit) { for (const callback of listeners.get(name) ?? []) callback({detail:{visit}}); },
        props: initial, reloads: 0, calls: [], values: [], stopped: 0, selected: [], focused: 0,
        video: { readyState: 2, srcObject: null, play: async () => {} },
        state(initial) { const index = cursor++; if (!(index in slots)) slots[index] = typeof initial === 'function' ? initial() : initial; return [slots[index], value => { assert.equal(disposed, false, 'Unmounted views must not receive state updates'); slots[index] = typeof value === 'function' ? value(slots[index]) : value; }]; },
        ref(initial) { return this.state(() => ({ current: initial }))[0]; },
        memo(fn, deps) { const index = cursor++; const old = slots[index]; if (!old || deps.some((value, i) => !Object.is(value, old.deps[i]))) slots[index] = { deps, value: fn }; return slots[index].value; },
        effect(callback, deps) { const index = cursor++; const old = effects[index]; if (!old || !deps || deps.some((value, i) => !Object.is(value, old.deps[i]))) queued.push(() => { old?.cleanup?.(); effects[index] = { deps, cleanup: callback() }; }); },
        render() { cursor = 0; queued = []; this.tree = Component(this.props); const video = nodes(this.tree).find(n => n.type === 'video'); if (video?.ref) video.ref.current = h.video; const input = nodes(this.tree).find(n => n.props?.id === 'barcode-field'); if (input?.ref) input.ref.current = { focus() { h.focused++; } }; const pending = queued; queued = []; pending.forEach(fn => fn()); cursor = 0; this.tree = Component(this.props); return this.tree; },
        button(text) { const node = nodes(this.tree).find(n => n.type === 'button' && nodes(n).some(child => [child.props?.children].flat(Infinity).includes(text))); assert.ok(node, `Missing button ${text}`); return node; },
        close() { if (disposed) return; effects.forEach(effect => effect?.cleanup?.()); disposed = true; },
        stream() { return { getTracks: () => [{ stop() { h.stopped++; } }] }; },
    };
    const window = Object.assign(new EventTarget(), { location: Object.assign(new URL('https://hub.bagoo.test/scan'), { reload() { h.reloads++; } }), BarcodeDetector: class { async detect() { return h.values; } } });
    const document = Object.assign(new EventTarget(), { visibilityState: 'visible', cookie: 'XSRF-TOKEN=current-token', querySelector: () => ({ content: 'stale-token' }) });
    const navigator = { onLine: true, mediaDevices: { getUserMedia: async () => h.stream() } };
    const fetch = async (path, options) => { h.calls.push({ path, options, body: JSON.parse(options.body) }); return h.response ? h.response(path, options) : Response.json(reply()); };
    for (const [name, value] of Object.entries({ window, document, navigator, fetch, route: () => ({ current: () => false }) })) Object.defineProperty(globalThis, name, { value, configurable: true });
    Object.assign(h, { window, document, navigator }); globalThis.waybillHarness = h;
    t.after(() => { h.close(); delete globalThis.waybillHarness; for (const [name, descriptor] of Object.entries(originals)) { if (descriptor) Object.defineProperty(globalThis, name, descriptor); else delete globalThis[name]; } });
    return h;
}

test('camera starts on its first mount and treats the matching printed QR and barcode as one parcel', async t => {
    const seen = []; const h = harness(t, ui.Camera, { onScan: code => seen.push(code) });
    h.values = [{ rawValue: 'BGO-ONE' }, { rawValue: 'https://bagoo.test/track/BGO-ONE' }];
    h.render(); await h.button('Scan actual waybill with camera').props.onClick(); h.render(); await tick(); h.render();
    assert.deepEqual(seen, ['BGO-ONE']); assert.equal(h.stopped, 1);
    t.mock.timers.tick(1000); await tick(); assert.equal(seen.length, 1);
});
test('two different camera codes remain unsubmitted until only one parcel is visible', async t => {
    const seen = []; const h = harness(t, ui.Camera, { onScan: code => seen.push(code) });
    h.values = [{ rawValue: 'BGO-ONE' }, { rawValue: 'BGO-TWO' }];
    h.render(); await h.button('Scan actual waybill with camera').props.onClick(); h.render(); await tick(); h.render();
    assert.deepEqual(seen, []); assert.ok(nodes(h.tree).some(n => /Several parcel/.test(String(n.props?.children))));
    h.values = [{ rawValue: 'BGO-TWO' }]; t.mock.timers.tick(250); await tick(); h.render();
    assert.deepEqual(seen, ['BGO-TWO']); assert.equal(h.stopped, 1);
});
test('leaving while camera permission is pending releases the eventual stream', async t => {
    const h = harness(t); const permission = deferred(); h.navigator.mediaDevices.getUserMedia = () => permission.promise;
    h.render(); const starting = h.button('Scan actual waybill with camera').props.onClick(); h.close();
    permission.resolve(h.stream()); await starting; assert.equal(h.stopped, 1);
});
test('first scanner entry inspects once and a deliberate confirmation refreshes queues and exposes the next step', async t => {
    const h = harness(t, ui.Scanner, props); h.render();
    assert.equal(h.focused, 1, 'Direct entry must prepare the keyboard scanner field.');
    let input = nodes(h.tree).find(n => n.props?.id === 'barcode-field'); input.props.onChange({ target: { value: 'BGO-ONE' } }); h.render();
    nodes(h.tree).find(n => n.type === 'form').props.onSubmit({ preventDefault() {} }); await tick(); h.render();
    assert.equal(h.calls.length, 1); assert.equal(h.calls[0].path, '/hub/scan'); assert.equal(h.calls[0].body.mode, 'inspect');
    assert.equal(h.calls[0].options.headers['X-XSRF-TOKEN'], 'current-token');
    const gate = deferred(); h.response = () => gate.promise;
    const button = h.button('Confirm custody action'); const saving = button.props.onClick(); button.props.onClick();
    assert.equal(h.calls.length, 2, 'Rapid duplicate clicks must not duplicate a handover request');
    gate.resolve(Response.json(reply(true))); await saving; h.render();
    assert.equal(h.reloads, 1); assert.equal(h.calls[1].body.mode, 'confirm');
    assert.ok(nodes(h.tree).some(n => n.props?.guidance?.action === 'manifest'));
    h.button('Check for updates'); h.button('Scan next parcel');
});
test('an invalid next scan preserves its validation error and cannot leave the old parcel confirmation active', async t => {
    const h = harness(t, ui.Scanner, props); h.render();
    const input = () => nodes(h.tree).find(n => n.props?.id === 'barcode-field');
    const submit = () => nodes(h.tree).find(n => n.type === 'form').props.onSubmit({ preventDefault() {} });
    input().props.onChange({ target: { value: 'BGO-ONE' } }); h.render(); submit(); await tick(); h.render(); h.button('Confirm custody action');
    h.response = async () => Response.json({ errors: { barcode: ['Use the actual printed tracking code.'] } }, { status: 422 });
    input().props.onChange({ target: { value: '\tBGO-OTHER' } }); h.render(); submit(); h.render(); await tick(); h.render();
    assert.equal(h.calls[1].body.barcode, '\tBGO-OTHER', 'Controls must reach validation rather than being silently removed.');
    assert.ok(nodes(h.tree).some(n => n.props?.role === 'alert' && nodes(n).some(child => String(child.props?.children).includes('Use the actual printed tracking code'))));
    assert.ok(!nodes(h.tree).some(n => n.props?.onClick && String(n.props.children).includes('Confirm custody action')));
});
test('changing a facility discards a late lookup and removes the previous confirmation', async t => {
    const h = harness(t, ui.Scanner, props); const gate = deferred(); h.response = () => gate.promise;
    h.render(); nodes(h.tree).find(n => n.props?.id === 'barcode-field').props.onChange({ target: { value: 'BGO-ONE' } }); h.render();
    const camera = () => nodes(h.tree).find(n => n.props?.instruction?.startsWith('Scan the tracking barcode'));
    assert.equal(camera().key, '1');
    nodes(h.tree).find(n => n.type === 'form').props.onSubmit({ preventDefault() {} });
    h.props = { ...props, activeHub: { ...hub, id: 2 } }; h.render();
    assert.equal(camera().key, '2', 'Changing facility must unmount the previous camera and release its stream.');
    assert.equal(h.calls[0].options.signal.aborted, true);
    gate.resolve(Response.json(reply())); await tick(); h.render();
    assert.ok(!nodes(h.tree).some(n => n.props?.onClick && String(n.props.children).includes('Confirm custody action')));
});
test('expired scanner sessions have direct recovery without repeating a confirmation', async t => {
    const h = harness(t, ui.Scanner, props); h.response = async () => Response.json({}, { status: 419 });
    h.render(); nodes(h.tree).find(n => n.props?.id === 'barcode-field').props.onChange({ target: { value: 'BGO-ONE' } }); h.render();
    nodes(h.tree).find(n => n.type === 'form').props.onSubmit({ preventDefault() {} }); await tick(); h.render();
    h.button('Reload scanner').props.onClick(); assert.equal(h.reloads, 1); assert.equal(h.calls.length, 1);
});
test('operational polling is bounded and pauses on hidden/offline pages and during an action', t => {
    const h = harness(t, () => ui.Refresh(h.props.busy, ['queues']), { busy: false }); h.render();
    assert.equal(h.poll.interval, 15000); assert.equal(h.poll.policy.mode, 'rest');
    assert.equal(h.poll.options().onBefore(), true);
    h.document.visibilityState = 'hidden'; assert.equal(h.poll.options().onBefore(), false);
    h.document.visibilityState = 'visible'; h.navigator.onLine = false; assert.equal(h.poll.options().onBefore(), false);
    h.navigator.onLine = true; let cancelled = 0; h.poll.options().onCancelToken({ cancel: () => cancelled++ });
    h.props = { busy: true }; h.render(); assert.equal(h.poll.options().onBefore(), false); assert.equal(cancelled, 1);
});


test('facility changes and foreground work reject even an already delivered old poll response', t => {
    const h = harness(t, () => ui.Refresh(false, ['queues']), {activeHub:{id:1}}); h.render();
    let cancelled = 0; const old = h.poll.options(); old.onCancelToken({cancel:() => cancelled++});
    const visit = {headers:{},method:'post'};
    h.emit('before',visit); h.emit('start',visit);
    assert.equal(cancelled,1); assert.equal(h.poll.options().onBefore(),false);
    assert.throws(() => old.onBeforeUpdate(), /newer catalogue request/);
    h.props = {activeHub:{id:2}}; h.render();
    h.emit('finish',visit); assert.equal(h.poll.options().onBefore(),true);
    const current = h.poll.options(); current.onCancelToken({cancel:() => cancelled++});
    old.onFinish();
    h.document.visibilityState = 'hidden'; h.document.dispatchEvent(new Event('visibilitychange'));
    assert.equal(cancelled,2,'an old finish must not release the new poll cancellation handle');
    assert.throws(() => current.onBeforeUpdate(), /newer catalogue request/);
    h.document.visibilityState = 'visible';
    const next = h.poll.options(); next.onCancelToken({cancel:() => cancelled++});
    const pollVisit = {headers:next.headers}; h.emit('before',pollVisit); h.emit('start',pollVisit);
    assert.equal(cancelled,2,'a poll must not cancel itself');
    assert.deepEqual(next.only,['auth','queues']); next.onBeforeUpdate();
    h.navigator.onLine=false; h.window.dispatchEvent(new Event('offline')); assert.equal(cancelled,3);
});
