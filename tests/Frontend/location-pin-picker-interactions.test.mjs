import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-pin-picker-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const bundle = await build({ stdin: { contents: "export {default} from '@/Components/LocationPinPicker';", resolveDir: resolve(import.meta.dirname, '../..'), loader: 'tsx' },
    bundle: true, packages: 'external', platform: 'node', format: 'esm', jsx: 'automatic', write: false,
    plugins: [{ name: 'pin-picker-harness', setup(builder) {
        builder.onResolve({ filter: /^react$/ }, () => ({ path: 'react', namespace: 'hooks' }));
        builder.onLoad({ filter: /.*/, namespace: 'hooks' }, () => ({ contents: 'export const useState=v=>globalThis.pinHarness.state(v); export const useRef=v=>globalThis.pinHarness.ref(v); export const useEffect=(f,d)=>globalThis.pinHarness.effect(f,d);' }));
        builder.onResolve({ filter: /^leaflet$/ }, () => ({ path: 'leaflet', namespace: 'leaflet' }));
        builder.onLoad({ filter: /.*/, namespace: 'leaflet' }, () => ({ contents: `export const map=()=>globalThis.pinHarness.createMap(); export const tileLayer=()=>globalThis.pinHarness.tiles; export const divIcon=v=>v; export const marker=(p,o)=>globalThis.pinHarness.createMarker(p,o);` }));
        builder.onResolve({ filter: /\.css$/ }, () => ({ path: 'style', namespace: 'style' }));
        builder.onLoad({ filter: /.*/, namespace: 'style' }, () => ({ contents: '' }));
    } }],
});
const file = join(temporary, 'picker.mjs'); writeFileSync(file, bundle.outputFiles[0].text);
const Picker = (await import(pathToFileURL(file).href)).default;
const tick = async () => { for (let i = 0; i < 20; i++) await Promise.resolve(); };
const nodes = tree => !tree || typeof tree !== 'object' ? [] : [tree, ...[tree.props?.children].flat(Infinity).flatMap(nodes)];
const text = tree => typeof tree === 'string' ? tree : Array.isArray(tree) ? tree.map(text).join('') : tree && typeof tree === 'object' ? text(tree.props?.children) : '';

function harness(t) {
    const slots = [], effects = []; let cursor = 0, pending = [];
    const h = {
        props: { value: null, onChange(point) { h.changed.push(point); h.props.value = point; } }, changed: [], mapEvents: {}, tileEvents: {}, created: 0, removed: 0,
        state(initial) { const i = cursor++; if (!(i in slots)) slots[i] = typeof initial === 'function' ? initial() : initial; return [slots[i], value => { slots[i] = typeof value === 'function' ? value(slots[i]) : value; }]; },
        ref(value) { return this.state(() => ({ current: value }))[0]; },
        effect(fn, deps) { const i = cursor++; const old = effects[i]; if (!old || deps.some((value, index) => !Object.is(value, old.deps[index]))) pending.push(() => { old?.cleanup?.(); effects[i] = { deps, cleanup: fn() }; }); },
        createMap() { h.created++; return { setView() { return this; }, getZoom() { return 15; }, on(name, fn) { h.mapEvents[name] = fn; return this; }, remove() { h.removed++; }, invalidateSize() {} }; },
        tiles: { on(name, fn) { h.tileEvents[name] = fn; return this; }, addTo() { return this; } },
        createMarker(point, options) { h.marker = { point, options, events: {}, addTo() { return this; }, on(name, fn) { this.events[name] = fn; return this; }, setLatLng(value) { this.point = value; return this; }, getLatLng() { return { lat: this.point[0], lng: this.point[1] }; }, remove() { this.removed = true; } }; return h.marker; },
        render() { cursor = 0; pending = []; this.tree = Picker(this.props); for (const node of nodes(this.tree)) if (node.ref && node.props?.role === 'region') node.ref.current = {}; for (const run of pending) run(); return this.tree; },
        close() { for (const effect of effects) effect?.cleanup?.(); },
    };
    globalThis.pinHarness = h;
    t.after(() => { h.close(); delete globalThis.pinHarness; });
    return h;
}

test('a map click places a pin and dragging it reports the new coordinates', async t => {
    const h = harness(t); h.render(); await tick(); h.render();
    assert.equal(h.marker, undefined);
    h.mapEvents.click({ latlng: { lat: 14.599512345, lng: 120.984212345 } }); h.render();
    assert.deepEqual(h.changed[0], { latitude: 14.5995123, longitude: 120.9842123 });
    assert.equal(h.marker.options.draggable, true);
    h.marker.point = [14.6, 120.99]; h.marker.events.dragend(); h.render();
    assert.deepEqual(h.changed[1], { latitude: 14.6, longitude: 120.99 });
});

test('failed map tiles offer retry and release the old map', async t => {
    const h = harness(t); h.render(); await tick(); h.render();
    h.tileEvents.tileerror(); h.render();
    const retry = nodes(h.tree).find(node => node.type === 'button' && text(node) === 'Retry map');
    assert.ok(retry); retry.props.onClick(); h.render(); await tick(); h.render();
    assert.equal(h.created, 2); assert.equal(h.removed, 1);
});
