import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-counter-facility-'));
after(() => { rmSync(temporary, { recursive: true, force: true }); delete globalThis.counterFacility; });
const bundle = await build({
    entryPoints: [resolve(import.meta.dirname, '../../resources/js/Pages/Hub/CounterPickup.tsx')],
    bundle: true, packages: 'external', platform: 'node', format: 'esm', jsx: 'automatic', write: false,
    plugins: [{ name: 'counter-facility', setup(builder) {
        builder.onResolve({ filter: /^react$/ }, () => ({ path: 'hooks', namespace: 'counter' }));
        builder.onResolve({ filter: /^@inertiajs\/react$/ }, () => ({ path: 'inertia', namespace: 'counter' }));
        builder.onResolve({ filter: /^@headlessui\/react$/ }, () => ({ path: 'dialog', namespace: 'counter' }));
        builder.onResolve({ filter: /(?:Layouts\/DashboardLayout|Components\/WaybillCamera)$/ }, () => ({ path: 'component', namespace: 'counter' }));
        builder.onLoad({ filter: /.*/, namespace: 'counter' }, ({ path }) => ({ contents: {
            hooks: `export const useState = v => globalThis.counterFacility.state(v); export const useRef = v => useState({current:v})[0]; export const useEffect = (effect,deps) => globalThis.counterFacility.effect(effect,deps); export default {};`,
            inertia: `export const Head='head', Link='a'; export const router={post:(...args)=>globalThis.counterFacility.posts.push(args),get(){}};`,
            dialog: `export const Dialog='dialog', DialogBackdrop='div', DialogPanel='section', DialogTitle='h2';`,
            component: `export default function Component(){}`,
        }[path] }));
    } }],
});
const path = join(temporary, 'counter.mjs'); writeFileSync(path, bundle.outputFiles[0].text);
const { default: Counter } = await import(pathToFileURL(path).href);
const originalWindow = globalThis.window;
globalThis.window = { setTimeout: () => 1, clearTimeout() {} };
after(() => { if (originalWindow === undefined) delete globalThis.window; else globalThis.window = originalWindow; });

function harness() {
    const slots = []; let cursor = 0, effects = [];
    const h = {
        posts: [],
        state(initial) { const i = cursor++; if (!(i in slots)) slots[i] = typeof initial === 'function' ? initial() : initial; return [slots[i], next => { slots[i] = typeof next === 'function' ? next(slots[i]) : next; }]; },
        effect(callback, deps) { const i = cursor++, previous = slots[i]; if (!previous || deps.some((value, index) => !Object.is(value, previous[index]))) { slots[i] = deps; effects.push(callback); } },
        render(props) { globalThis.counterFacility = h; cursor = 0; let tree = Counter(props); if (effects.length) { const queued = effects; effects = []; queued.forEach(effect => effect()); cursor = 0; tree = Counter(props); } return tree; },
    };
    return h;
}
function nodes(tree, predicate) {
    if (!tree || typeof tree !== 'object') return [];
    if (Array.isArray(tree)) return tree.flatMap(child => nodes(child, predicate));
    return [...(predicate(tree) ? [tree] : []), ...nodes(tree.props?.children, predicate)];
}
function text(tree) {
    if (tree == null || typeof tree === 'boolean') return '';
    if (typeof tree !== 'object') return String(tree);
    return Array.isArray(tree) ? tree.map(text).join('') : text(tree.props?.children);
}
const parcel = { id: 5, tracking_number: 'BGO-FACILITY', order_number: 'ORDER', buyer_name: 'Mara Santos', buyer_id: 4,
    buyer_phone: '', destination_bin: 'COUNTER-A', total_amount: 100, payment_method: 'cod', item_count: 1, items: [],
    status: 'ready_for_hub_pickup', arrived_at: null,
    claim: { reference: 'CLAIM', status: 'ready', expires_at: '2099-01-01T00:00:00Z', locked_until: null },
    guidance: { stage: 'Staged', order_status: 'Sorted', custodian: 'Child A', custody_recorded_at: null, next_step: 'Verify buyer',
        responsible: 'Handler', blocker: null, action: 'counter', can_act: true, href: null, eligible_rider_ids: [], ready_for_collection: true } };
function props(id, hours, data = []) {
    return { activeHub: { id, name: `Child ${id}`, code: `CH-${id}`, tier: 'local_bayan_hub', city_municipality: 'Calamba', operating_hours: hours },
        counterParcels: { data, current_page: 1, last_page: 1, total: data.length, from: null, to: null, prev_page_url: null, next_page_url: null, first_page_url: '/hub/counter' },
        recentlyCollected: [], search: '', requestToken: 'REQUEST', canConfigureHours: true, basePath: '/hub', canScan: true,
        counterCounts: { shown: data.length, ready: data.length, blocked: 0 } };
}
const hoursInput = tree => nodes(tree, node => node.type === 'input' && node.props.maxLength === 255)[0];
const hoursForm = tree => nodes(tree, node => node.type === 'form' && text(node).includes('Save pickup hours'))[0];

test('switching facilities replaces the hours draft with the selected facility value before saving', () => {
    const h = harness(), a = props(1, '08:00 to 17:00'), b = props(2, '09:00 to 18:00');
    let tree = h.render(a);
    hoursInput(tree).props.onChange({ target: { value: 'Unsaved hours for A' } });
    assert.equal(hoursInput(h.render(a)).props.value, 'Unsaved hours for A');
    tree = h.render(b);
    assert.equal(hoursInput(tree).props.value, '09:00 to 18:00');
    hoursForm(tree).props.onSubmit({ preventDefault() {} });
    assert.deepEqual(h.posts[0].slice(0, 2), ['/hub/counter/hours', { hub_id: 2, operating_hours: '09:00 to 18:00' }]);
    tree = h.render(props(2, '10:00 to 19:00'));
    assert.equal(hoursInput(tree).props.value, '10:00 to 19:00');
});

test('changing facility closes the previous parcel release form and clears its field errors', () => {
    const h = harness(), a = props(1, '08:00 to 17:00', [parcel]);
    let tree = h.render(a);
    hoursForm(tree).props.onSubmit({ preventDefault() {} });
    h.posts[0][2].onError({ operating_hours: 'Invalid hours at A' });
    tree = h.render(a);
    assert.ok(text(tree).includes('Invalid hours at A'));
    nodes(tree, node => node.type === 'button' && text(node) === 'Release')[0].props.onClick();
    tree = h.render(a);
    assert.equal(nodes(tree, node => node.type === 'dialog').length, 1);
    tree = h.render(props(2, '09:00 to 18:00'));
    assert.equal(nodes(tree, node => node.type === 'dialog').length, 0);
    assert.equal(text(tree).includes('Invalid hours at A'), false);
});
