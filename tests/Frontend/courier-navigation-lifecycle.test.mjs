import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';

const root = resolve(import.meta.dirname, '../..');
const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-navigation-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const bundle = await build({ stdin: { contents: "export {default} from '@/hooks/useCourierRoadRoute';", resolveDir: root, loader: 'tsx' },
    bundle: true, packages: 'external', platform: 'node', format: 'esm', write: false,
    plugins: [{ name: 'test-hooks', setup(builder) {
        builder.onResolve({ filter: /^react$/ }, () => ({ path: 'react', namespace: 'test-hooks' }));
        builder.onLoad({ filter: /.*/, namespace: 'test-hooks' }, () => ({ contents: 'export const useState=v=>globalThis.navHarness.state(v); export const useRef=v=>globalThis.navHarness.ref(v); export const useEffect=(f,d)=>globalThis.navHarness.effect(f,d);' }));
    } }],
});
const file = join(temporary, 'navigation.mjs'); writeFileSync(file, bundle.outputFiles[0].text);
const useNavigation = (await import(pathToFileURL(file).href)).default;
const tick = async () => { for (let i = 0; i < 20; i++) await new Promise(resolve => setTimeout(resolve, 0)); };

function harness(t) {
    const originals = Object.fromEntries(['document', 'window', 'navigator', 'fetch'].map(name => [name, Object.getOwnPropertyDescriptor(globalThis, name)]));
    const slots = [], effects = []; let cursor = 0, pending = [];
    const h = {
        job: { key: 'pickup-7', status: 'picked_up', stopKind: 'origin_hub', preview: false }, target: [14.17, 121.24], calls: [], cleared: [], key: 'public-test-key', authorized: true, confirmedKind: 'origin_hub',
        state(initial) { const i = cursor++; if (!(i in slots)) slots[i] = typeof initial === 'function' ? initial() : initial; return [slots[i], value => { slots[i] = typeof value === 'function' ? value(slots[i]) : value; }]; },
        ref(value) { return this.state(() => ({ current: value }))[0]; },
        effect(fn, deps) { const i = cursor++; const old = effects[i]; if (!old || deps.some((value, index) => !Object.is(value, old.deps[index]))) pending.push(() => { old?.cleanup?.(); effects[i] = { deps, cleanup: fn() }; }); },
        render() { cursor = 0; pending = []; this.view = useNavigation(this.job, this.target, this.key, false); for (const run of pending) run(); return this.view; },
        close() { for (const effect of effects) effect?.cleanup?.(); },
    };
    const doc = Object.assign(new EventTarget(), { visibilityState: 'visible' });
    const win = Object.assign(new EventTarget(), { location: { hostname: 'localhost' } });
    const geo = { watchPosition(success, failure) { h.fix = success; h.fail = failure; return 42; }, clearWatch(id) { h.cleared.push(id); } };
    Object.defineProperty(globalThis, 'document', { configurable: true, value: doc });
    Object.defineProperty(globalThis, 'window', { configurable: true, value: win });
    Object.defineProperty(globalThis, 'navigator', { configurable: true, value: { geolocation: geo } });
    Object.defineProperty(globalThis, 'fetch', { configurable: true, value: async (url, options) => {
        h.calls.push({ url, options });
        if (url.startsWith('/courier/navigation/')) return h.authorized ? new Response(JSON.stringify({ data: { task_id: 'pickup-7', kind: h.confirmedKind, latitude: 14.17, longitude: 121.24 } })) : new Response('', { status: 403 });
        const result = { type: 'FeatureCollection', features: [{ type: 'Feature', properties: { legs: [{ steps: [{ from_index: 0, to_index: 1 }] }] }, geometry: { type: 'LineString', coordinates: [[121.20, 14.15], [121.24, 14.17]] } }] };
        if (h.deferProvider) return new Promise(resolve => { h.resolveProvider = () => resolve(new Response(JSON.stringify(result))); });
        return new Response(JSON.stringify(result));
    } });
    globalThis.navHarness = h; h.document = doc;
    t.after(() => { h.close(); delete globalThis.navHarness; for (const [name, descriptor] of Object.entries(originals)) { if (descriptor) Object.defineProperty(globalThis, name, descriptor); else delete globalThis[name]; } });
    return h;
}

test('navigation verifies assignment before location, draws a road route and stops when hidden', async t => {
    const h = harness(t); h.render();
    assert.equal(h.calls.length, 0); assert.equal(h.fix, undefined);
    h.view.setMode('motorcycle'); h.render(); h.view.start(); h.render(); await tick();
    assert.equal(h.calls.length, 1); assert.match(h.calls[0].url, /^\/courier\/navigation\/pickup-7$/);
    assert.equal(typeof h.fix, 'function');
    h.fix({ coords: { latitude: 14.15, longitude: 121.20, accuracy: 12 }, timestamp: Date.now() });
    await tick(); h.render();
    assert.deepEqual(h.view.lines, [[[14.15, 121.20], [14.17, 121.24]]]);
    assert.equal(h.calls.length, 3); // fresh assignment, recheck, then provider
    h.document.visibilityState = 'hidden'; h.document.dispatchEvent(new Event('visibilitychange'));
    assert.deepEqual(h.cleared, [42]); h.render();
    assert.equal(h.view.lines, null);
    assert.match(h.view.message, /paused/);
});

test('preview tasks and missing provider configuration never start location tracking', t => {
    const h = harness(t);
    h.job.preview = true; h.render(); h.view.start(); h.render();
    assert.equal(h.calls.length, 0); assert.equal(h.fix, undefined);
    h.job.preview = false; h.key = ''; h.render(); h.view.setMode('drive'); h.render(); h.view.start(); h.render();
    assert.equal(h.calls.length, 0); assert.match(h.view.message, /not configured/);
});

test('changing the assigned stop removes the route and stops GPS', async t => {
    const h = harness(t); h.render(); h.view.setMode('drive'); h.render(); h.view.start(); h.render(); await tick();
    h.fix({ coords: { latitude: 14.15, longitude: 121.2, accuracy: 10 }, timestamp: Date.now() }); await tick(); h.render();
    assert.ok(h.view.lines);
    h.job = { ...h.job, status: 'assigned_pickup', stopKind: 'seller' }; h.target = [14.2, 121.3]; h.render();
    assert.equal(h.view.lines, null); assert.deepEqual(h.cleared, [42]);
});

test('a late provider response cannot restore a route after the stop changes', async t => {
    const h = harness(t); h.deferProvider = true; h.render(); h.view.setMode('drive'); h.render(); h.view.start(); h.render(); await tick();
    h.fix({ coords: { latitude: 14.15, longitude: 121.2, accuracy: 10 }, timestamp: Date.now() }); await tick();
    assert.equal(typeof h.resolveProvider, 'function');
    const request = h.calls[2]; h.job = { ...h.job, status: 'assigned_pickup', stopKind: 'seller' }; h.target = [14.2, 121.3]; h.render();
    assert.equal(request.options.signal.aborted, true);
    h.resolveProvider(); await tick(); h.render();
    assert.equal(h.view.lines, null); assert.equal(h.view.active, false);
});

test('a revoked assignment prevents GPS tracking and a provider request', async t => {
    const h = harness(t); h.authorized = false; h.render(); h.view.setMode('drive'); h.render(); h.view.start(); h.render(); await tick(); h.render();
    assert.equal(h.calls.length, 1); assert.equal(h.fix, undefined);
    assert.equal(h.view.active, false); assert.match(h.view.message, /assignment or stop is unavailable/i);
});

test('a changed stop type cannot start tracking even when coordinates match', async t => {
    const h = harness(t); h.confirmedKind = 'seller'; h.render(); h.view.setMode('drive'); h.render(); h.view.start(); h.render(); await tick(); h.render();
    assert.equal(h.calls.length, 1); assert.equal(h.fix, undefined); assert.equal(h.view.active, false);
});

test('location denial stops tracking without asking the routing provider', async t => {
    const h = harness(t); h.render(); h.view.setMode('drive'); h.render(); h.view.start(); h.render(); await tick();
    h.fail({ code: 1 }); h.render();
    assert.equal(h.calls.length, 1); assert.deepEqual(h.cleared, [42]); assert.match(h.view.message, /permission was denied/);
});

test('an inaccurate location is never sent to the routing provider', async t => {
    const h = harness(t); h.render(); h.view.setMode('drive'); h.render(); h.view.start(); h.render(); await tick();
    h.fix({ coords: { latitude: 14.15, longitude: 121.2, accuracy: 200 }, timestamp: Date.now() }); await tick(); h.render();
    assert.equal(h.calls.length, 1); assert.equal(h.view.lines, null); assert.match(h.view.message, /accurate location/);
});

test('moving away from the last fix clears the old line while requests are rate limited', async t => {
    const h = harness(t); h.render(); h.view.setMode('drive'); h.render(); h.view.start(); h.render(); await tick();
    h.fix({ coords: { latitude: 14.15, longitude: 121.2, accuracy: 10 }, timestamp: Date.now() }); await tick(); h.render();
    assert.ok(h.view.lines);
    h.fix({ coords: { latitude: 14.151, longitude: 121.2, accuracy: 10 }, timestamp: Date.now() }); h.render();
    assert.equal(h.view.lines, null); assert.equal(h.calls.length, 3); assert.match(h.view.message, /updating/i);
});

test('stop navigation immediately releases GPS without waiting for another render', async t => {
    const h = harness(t); h.render(); h.view.setMode('drive'); h.render(); h.view.start(); h.render(); await tick();
    h.render(); h.view.stop();
    assert.deepEqual(h.cleared, [42]); h.render(); assert.equal(h.view.active, false);
});
