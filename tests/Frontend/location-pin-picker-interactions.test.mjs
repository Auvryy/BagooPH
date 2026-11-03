import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';
import postcss from 'postcss';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-pin-picker-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const bundle = await build({ stdin: { contents: "export {default} from '@/Components/LocationPinPicker';", resolveDir: resolve(import.meta.dirname, '../..'), loader: 'tsx' },
    bundle: true, packages: 'external', platform: 'node', format: 'esm', jsx: 'automatic', write: false,
    plugins: [{ name: 'pin-picker-harness', setup(builder) {
        builder.onResolve({ filter: /^react$/ }, () => ({ path: 'react', namespace: 'hooks' }));
        builder.onLoad({ filter: /.*/, namespace: 'hooks' }, () => ({ contents: 'export const useState=v=>globalThis.pinHarness.state(v); export const useId=()=>"pin-picker-help"; export const useRef=v=>globalThis.pinHarness.ref(v); export const useEffect=(f,d)=>globalThis.pinHarness.effect(f,d);' }));
        builder.onResolve({ filter: /^leaflet$/ }, () => ({ path: 'leaflet', namespace: 'leaflet' }));
        builder.onLoad({ filter: /.*/, namespace: 'leaflet' }, () => ({ contents: `export const map=(element,options)=>globalThis.pinHarness.createMap(element,options); export const tileLayer=(url,options)=>{globalThis.pinHarness.tileConfig={url,options};return globalThis.pinHarness.tiles}; export const divIcon=v=>v; export const marker=(p,o)=>globalThis.pinHarness.createMarker(p,o);` }));
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
        createMap(element, options) { h.created++; h.mapOptions = options; return { setView(point) { this.center = point; return this; }, getCenter() { return { lat: this.center?.[0] ?? 12.8797, lng: this.center?.[1] ?? 121.774 }; }, getZoom() { return 15; }, on(name, fn) { h.mapEvents[name] = fn; return this; }, remove() { h.removed++; }, invalidateSize() {} }; },
        tiles: { on(name, fn) { h.tileEvents[name] = fn; return this; }, addTo() { return this; } },
        createMarker(point, options) { h.marker = { point, options, events: {}, addTo() { return this; }, on(name, fn) { this.events[name] = fn; return this; }, setLatLng(value) { this.point = value; return this; }, getLatLng() { return { lat: this.point[0], lng: this.point[1] }; }, remove() { this.removed = true; } }; return h.marker; },
        render() { cursor = 0; pending = []; this.tree = Picker(this.props); for (const node of nodes(this.tree)) if (node.ref && node.props?.role === 'region') { this.container ??= {}; node.ref.current = this.container; } for (const run of pending) run(); return this.tree; },
        close() { for (const effect of effects) effect?.cleanup?.(); },
    };
    globalThis.pinHarness = h;
    t.after(() => { h.close(); delete globalThis.pinHarness; });
    return h;
}

test('a map click places a pin and dragging it reports the new coordinates', async t => {
    const h = harness(t); h.render(); await tick(); h.render();
    assert.equal(h.marker, undefined);
    h.tileEvents.load(); h.render();
    h.mapEvents.click({ latlng: { lat: 14.599512345, lng: 120.984212345 } }); h.render();
    assert.deepEqual(h.changed[0], { latitude: 14.5995123, longitude: 120.9842123 });
    assert.equal(h.marker.options.draggable, true);
    assert.equal(h.marker.options.keyboard, true);
    assert.equal(h.marker.options.title, 'Delivery location');
    assert.deepEqual(h.marker.options.icon.iconSize, [44, 44]);
    h.marker.point = [14.6, 120.99]; h.marker.events.dragend(); h.render();
    assert.deepEqual(h.changed[1], { latitude: 14.6, longitude: 120.99 });
    h.mapEvents.click({ latlng: { lat: -14.599512345, lng: -120.984212345 } }); h.render();
    assert.deepEqual(h.changed[2], { latitude: -14.5995123, longitude: -120.9842123 });
});

test('map keeps the existing Leaflet OpenStreetMap tiles, attribution, and page scrolling', async t => {
    const h = harness(t); h.render(); await tick();
    assert.equal(h.mapOptions.scrollWheelZoom, false);
    assert.equal(h.tileConfig.url, 'https://tile.openstreetmap.org/{z}/{x}/{y}.png');
    assert.equal(h.tileConfig.options.maxZoom, 19);
    assert.match(h.tileConfig.options.attribution, /openstreetmap\.org\/copyright/);
    assert.match(h.tileConfig.options.attribution, /OpenStreetMap contributors/);
});

test('keyboard Enter places a pin at the focused map center only', async t => {
    const h = harness(t); h.render(); await tick(); h.render();
    const mapRegion = nodes(h.tree).find(node => node.props?.role === 'region');
    const instructions = nodes(h.tree).find(node => node.props?.id === 'pin-picker-help');
    assert.equal(mapRegion.props['aria-describedby'], instructions.props.id);
    assert.match(text(instructions), /cannot verify that it matches your written address/);
    assert.match(text(instructions), /Keyboard: focus the map and use arrow keys to pan; press Enter to pin its center\. Press Tab to reach the zoom controls\./);
    h.mapEvents.keypress({ originalEvent: { key: 'ArrowRight', target: h.container } });
    h.mapEvents.keypress({ originalEvent: { key: 'Enter', target: {} } });
    assert.equal(h.changed.length, 0);

    h.mapEvents.keypress({ originalEvent: { key: 'Enter', target: h.container } }); h.render();
    assert.deepEqual(h.changed[0], { latitude: 12.8797, longitude: 121.774 });
    assert.equal(h.marker.options.title, 'Delivery location');
});

test('map status explains initial tile loading and clears after the first tile batch', async t => {
    const h = harness(t); h.render();
    assert.equal(text(nodes(h.tree).find(node => node.props?.role === 'status')), 'Loading map…');
    await tick(); h.render();
    assert.equal(text(nodes(h.tree).find(node => node.props?.role === 'status')), 'Loading map…');
    h.tileEvents.load(); h.render();
    assert.equal(text(nodes(h.tree).find(node => node.props?.role === 'status')), 'No location pin selected yet.');
});

test('a stalled tile batch times out with recovery guidance and retains the pin', async t => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const h = harness(t);
    h.props.value = { latitude: 14.5995123, longitude: 120.9842123 };
    h.render(); await tick(); h.render();
    t.mock.timers.tick(15000); h.render();
    assert.equal(text(nodes(h.tree).find(node => node.props?.role === 'status')), 'Map tiles could not load. Your pin is still selected; retry to view the location.');
    const retry = nodes(h.tree).find(node => node.type === 'button' && text(node) === 'Retry map');
    assert.ok(retry);
    assert.match(retry.props.className, /min-h-\[44px\]/);
    assert.deepEqual(h.marker.point, [14.5995123, 120.9842123]);
});

test('zoom and pin controls retain touch-sized targets', () => {
    const styles = postcss.parse(readFileSync(resolve(import.meta.dirname, '../../resources/css/app.css'), 'utf8'));
    for (const control of ['in', 'out']) {
        const suffix = `leaflet-control-zoom-${control}`;
        const rule = [];
        styles.walkRules(candidate => {
            if (candidate.selector.includes('.location-pin-picker') && candidate.selector.includes(suffix)) rule.push(candidate);
        });
        const declarations = rule.flatMap(candidate => candidate.nodes ?? []);
        for (const dimension of ['width', 'height']) {
            assert.ok(declarations.some(node => node.type === 'decl' && node.prop === dimension && node.value === '44px'), `${control} zoom target needs 44px ${dimension}`);
        }
        assert.ok(rule.some(candidate => (candidate.selector.match(/\./g) ?? []).length >= 3), `${control} selector must override Leaflet's touch sizing`);
    }
});

test('failed map tiles offer retry and release the old map', async t => {
    const h = harness(t); h.render(); await tick(); h.render();
    h.tileEvents.tileerror(); h.render();
    const retry = nodes(h.tree).find(node => node.type === 'button' && text(node) === 'Retry map');
    assert.ok(retry); retry.props.onClick(); h.render(); await tick(); h.render();
    assert.equal(h.created, 2); assert.equal(h.removed, 1);
});

test('a tile failure keeps selected-pin feedback visible with retry', async t => {
    const h = harness(t);
    h.props.value = { latitude: 14.5995123, longitude: 120.9842123 };
    h.render(); await tick(); h.render();
    assert.ok(h.marker);
    assert.equal(text(nodes(h.tree).find(node => node.props?.role === 'status')), 'Loading map… Your pin is still selected.');
    h.tileEvents.tileerror(); h.render();
    const status = nodes(h.tree).find(node => node.props?.role === 'status');
    assert.equal(text(status), 'Map tiles could not load. Your pin is still selected; retry to view the location.');
    const retry = nodes(h.tree).find(node => node.type === 'button' && text(node) === 'Retry map');
    assert.ok(retry);
    retry.props.onClick(); h.render(); await tick(); h.render();
    assert.equal(h.created, 2);
    assert.deepEqual(h.marker.point, [14.5995123, 120.9842123]);
    assert.equal(text(nodes(h.tree).find(node => node.props?.role === 'status')), 'Loading map… Your pin is still selected.');
    h.tileEvents.load(); h.render();
    assert.equal(text(nodes(h.tree).find(node => node.props?.role === 'status')), 'Location pin selected.');
});

test('clearing a saved pin removes its marker and a new selection replaces it', async t => {
    const h = harness(t);
    h.props.value = { latitude: 14.5995123, longitude: 120.9842123 };
    h.render(); await tick(); h.render();
    const previousMarker = h.marker;
    assert.deepEqual(previousMarker.point, [14.5995123, 120.9842123]);
    h.tileEvents.load(); h.render();

    h.props.value = null; h.render();
    assert.equal(previousMarker.removed, true);
    assert.equal(text(nodes(h.tree).find(node => node.props?.role === 'status')), 'No location pin selected yet.');

    h.mapEvents.click({ latlng: { lat: 14.600123456, lng: 120.985456789 } }); h.render();
    assert.deepEqual(h.changed.at(-1), { latitude: 14.6001235, longitude: 120.9854568 });
    assert.notEqual(h.marker, previousMarker);
    assert.deepEqual(h.marker.point, [14.6001235, 120.9854568]);
});
