import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';

const directory = mkdtempSync(join(import.meta.dirname, '.tmp-rider-workspace-'));
after(() => rmSync(directory, { recursive: true, force: true }));
const bundle = await build({ stdin: { contents: "export { default } from '@/Pages/Courier/Deliveries';", resolveDir: resolve(import.meta.dirname, '../..'), loader: 'tsx' },
    bundle: true, packages: 'external', platform: 'node', format: 'esm', jsx: 'automatic', write: false,
    plugins: [{ name: 'rider-workspace-hooks', setup(builder) {
        builder.onResolve({ filter: /^react$/ }, () => ({ path: 'hooks', namespace: 'rider-harness' }));
        builder.onLoad({ filter: /.*/, namespace: 'rider-harness' }, () => ({ contents: `export const useState=v=>globalThis.riderWorkspace.state(v); export const useRef=v=>globalThis.riderWorkspace.ref(v); export const useEffect=(f,d)=>globalThis.riderWorkspace.effect(f,d);` }));
        builder.onResolve({ filter: /^@inertiajs\/react$/ }, () => ({ path: 'inertia', namespace: 'rider-inertia' }));
        builder.onLoad({ filter: /.*/, namespace: 'rider-inertia' }, () => ({ contents: `export const Head='head', Link='a'; export const usePage=()=>({props:{auth:{user:{name:'Rider'}}}}); export const router={post:(...args)=>globalThis.riderWorkspace.calls.push(args),reload(){}};` }));
        builder.onResolve({ filter: /hooks\/(useOperationalRefresh|useCourierRequestError)$/ }, () => ({ path: 'noop', namespace: 'rider-noop' }));
        builder.onLoad({ filter: /.*/, namespace: 'rider-noop' }, () => ({ contents: 'export default function noop(){}' }));
        builder.onResolve({ filter: /(Layouts\/CourierLayout|Components\/(CourierCurrentJob|CourierTaskCard|CourierDashboard|CourierUI|WaybillCamera))$/ }, args => ({ path: args.path, namespace: 'rider-child' }));
        builder.onLoad({ filter: /.*/, namespace: 'rider-child' }, () => ({ contents: `export default function Child(){}; export const CourierDeliveryOverview=()=>null,CourierRecentDeliveries=()=>null,CourierDialog=()=>null,CourierEmpty=()=>null,CourierFieldError=()=>null,CourierPanel=()=>null,CourierParcelIdentifiers=()=>null; export const courierButton='',courierInput='',courierPrimary='',courierClasses=(...v)=>v.filter(Boolean).join(' ');` }));
    } }],
});
const modulePath = join(directory, 'workspace.mjs'); writeFileSync(modulePath, bundle.outputFiles[0].text);
const Workspace = (await import(pathToFileURL(modulePath).href)).default;
function nodes(tree) { return !tree || typeof tree !== 'object' ? [] : [tree, ...[tree.props?.children].flat(Infinity).flatMap(nodes)]; }
function text(tree) { return typeof tree === 'string' ? tree : Array.isArray(tree) ? tree.map(text).join('') : tree && typeof tree === 'object' ? text(tree.props?.children) : ''; }
function harness(t) {
    const slots = [], effects = []; let cursor = 0, pending = [];
    const task = { id: 8, trackingNumber: 'BGO-PARCEL', orderNumber: 'BGO-ORDER', status: 'assigned_pickup', nextAction: 'confirm_pickup', retainedByCurrentCourier: false,
        itemCount: 1, items: [{ name: 'Canvas bag', quantity: 1 }], merchant: { name: 'Seller', address: 'Pickup road' }, originHub: { name: 'Origin hub', address: 'Hub road' }, assignedAt: null, canMessage: false };
    const h = { calls: [], props: { recoveryRequestToken: 'TEST-TOKEN', isOnline: true, scope: { isAssigned: true, isOperational: true }, queues: { pickupTasks: [task], availablePickups: [], finalMileTasks: [] } },
        state(initial) { const i = cursor++; if (!(i in slots)) slots[i] = typeof initial === 'function' ? initial() : initial; return [slots[i], next => { slots[i] = typeof next === 'function' ? next(slots[i]) : next; }]; },
        ref(initial) { return this.state(() => ({ current: initial }))[0]; },
        effect(fn, deps) { const i = cursor++; const prior = effects[i]; if (!prior || deps.some((value, index) => !Object.is(value, prior.deps[index]))) pending.push(() => { prior?.cleanup?.(); effects[i] = { deps, cleanup: fn() }; }); },
        render() { cursor = 0; pending = []; this.tree = Workspace(this.props); for (const effect of pending) effect(); return this.tree; },
        button(label) { const found = nodes(this.tree).find(node => node.type === 'button' && text(node).includes(label)); assert.ok(found, 'Expected button: '+label); return found; },
        camera() { const found = nodes(this.tree).find(node => typeof node.props?.onScan === 'function'); assert.ok(found, 'Camera callback must be available in the actual action form'); return found; },
        submit() { nodes(this.tree).find(node => node.type === 'form').props.onSubmit({ preventDefault() {} }); },
    };
    globalThis.riderWorkspace = h;
    t.after(() => { for (const effect of effects) effect?.cleanup?.(); delete globalThis.riderWorkspace; });
    h.render(); return h;
}
test('rider camera fills the selected waybill without submitting; wrong scans cannot confirm custody', t => {
    const h = harness(t); h.button('Scan waybill · confirm pickup').props.onClick(); h.render();
    h.camera().props.onScan('BGO-WRONG'); h.render();
    assert.equal(nodes(h.tree).find(node => node.props?.id === 'pickup-waybill').props.value, 'BGO-WRONG');
    assert.equal(h.calls.length, 0);
    assert.equal(h.button('Confirm pickup').props.disabled, true);
    h.submit(); h.render(); assert.equal(h.calls.length, 0);
    h.camera().props.onScan('BGO-PARCEL'); h.render(); assert.equal(h.calls.length, 0);
    assert.equal(h.button('Confirm pickup').props.disabled, false);
    h.submit(); assert.equal(h.calls.length, 1);
    assert.match(h.calls[0][0], /deliveries\/8\/status$/);
    assert.equal(h.calls[0][1].barcode, 'BGO-PARCEL'); assert.equal(h.calls[0][1].status, 'picked_up');
    h.submit(); assert.equal(h.calls.length, 1, 'Pending confirmation must block duplicate writes');
});
test('a refreshed assignment closes its old camera form and cannot submit the stale pickup', t => {
    const h = harness(t); h.button('Scan waybill · confirm pickup').props.onClick(); h.render();
    h.camera().props.onScan('BGO-PARCEL'); h.render();
    h.props = { ...h.props, queues: { pickupTasks: [], finalMileTasks: [], availablePickups: [] } }; h.render(); h.render();
    assert.equal(nodes(h.tree).some(node => typeof node.props?.onScan === 'function'), false);
    assert.equal(h.calls.length, 0);
});

test('pickup moves to Carrying only after the server confirms the handover', t => {
    const h = harness(t);
    assert.equal(h.button('Assigned to you').props['aria-pressed'], true);
    h.button('Scan waybill · confirm pickup').props.onClick(); h.render();
    h.camera().props.onScan('BGO-PARCEL'); h.render(); h.submit(); h.render();
    assert.equal(h.button('Assigned to you').props['aria-pressed'], true, 'A pending scan cannot invent custody');
    const options = h.calls[0][2];
    const task = h.props.queues.pickupTasks[0];
    h.props = { ...h.props, queues: { ...h.props.queues, pickupTasks: [{ ...task, status: 'picked_up', nextAction: 'await_origin_hub_scan', retainedByCurrentCourier: true }] } };
    options.onSuccess({ props: { flash: {} } }); options.onFinish(); h.render();
    assert.equal(h.button('Carrying').props['aria-pressed'], true);
    assert.equal(nodes(h.tree).some(node => typeof node.props?.onScan === 'function'), false);
    assert.equal(h.calls.length, 1);
});
