import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';

const root = resolve(import.meta.dirname, '../..');
const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-checkout-gps-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const bundle = await build({ entryPoints: [join(root, 'resources/js/hooks/useCheckoutGps.ts')], bundle: true, platform: 'node', format: 'esm', write: false,
    plugins: [{ name: 'controlled-hooks', setup(builder) {
        builder.onResolve({ filter: /^react$/ }, () => ({ path: 'react', namespace: 'hooks' }));
        builder.onLoad({ filter: /.*/, namespace: 'hooks' }, () => ({ contents: 'export const useState=v=>globalThis.gpsHarness.state(v); export const useRef=v=>globalThis.gpsHarness.ref(v); export const useEffect=(f,d)=>globalThis.gpsHarness.effect(f,d);' }));
    } }] });
const file = join(temporary, 'gps.mjs'); writeFileSync(file, bundle.outputFiles[0].text);
const useCheckoutGps = (await import(pathToFileURL(file).href)).default;

function harness(t) {
    const previous = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
    const slots = [], effects = []; let cursor = 0, pending = [];
    const h = {
        pins: [], requests: [],
        state(initial) { const index = cursor++; if (!(index in slots)) slots[index] = initial; return [slots[index], value => { slots[index] = typeof value === 'function' ? value(slots[index]) : value; }]; },
        ref(value) { return this.state({ current: value })[0]; },
        effect(fn, deps) { const index = cursor++; if (!effects[index]) pending.push(() => { effects[index] = { deps, cleanup: fn() }; }); },
        render() { cursor = 0; pending = []; this.view = useCheckoutGps(point => this.pins.push(point)); for (const run of pending) run(); return this.view; },
        close() { for (const effect of effects) effect?.cleanup?.(); },
    };
    globalThis.gpsHarness = h;
    Object.defineProperty(globalThis, 'navigator', { configurable: true, value: { geolocation: { getCurrentPosition: (success, failure) => h.requests.push({ success, failure }) } } });
    t.after(() => { h.close(); delete globalThis.gpsHarness; if (previous) Object.defineProperty(globalThis, 'navigator', previous); else delete globalThis.navigator; });
    h.render();
    return h;
}
const fix = (overrides = {}) => ({ coords: { latitude: 14.55, longitude: 121.05, accuracy: 10, ...overrides }, timestamp: Date.now() });

test('a location result cannot overwrite a newer address or manually selected pin', t => {
    const h = harness(t);
    h.view.capture(); h.render().cancel();
    h.requests[0].success(fix());
    assert.deepEqual(h.pins, []);
    assert.equal(h.render().locating, false);
    h.view.capture(); h.render().capture();
    h.requests[1].success(fix({ latitude: 14.6 }));
    h.requests[2].success(fix());
    assert.deepEqual(h.pins, [{ latitude: 14.55, longitude: 121.05 }]);
    h.view.capture(); h.close(); h.requests[3].success(fix());
    assert.equal(h.pins.length, 1);
});

test('inaccurate, stale and unavailable fixes leave the pin unchanged', t => {
    const h = harness(t);
    h.view.capture(); h.requests[0].success(fix({ accuracy: 101 }));
    assert.match(h.render().status, /fresh, accurate/);
    h.view.capture(); h.requests[1].success({ ...fix(), timestamp: Date.now() - 31000 });
    assert.match(h.render().status, /fresh, accurate/);
    h.view.capture(); h.requests[2].failure({ message: 'private browser detail' });
    assert.match(h.render().status, /Place the pin/);
    assert.doesNotMatch(h.view.status, /private browser detail/);
    assert.deepEqual(h.pins, []);
});
