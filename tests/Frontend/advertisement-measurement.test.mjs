import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-ad-measurement-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const bundle = await build({ entryPoints: [join(import.meta.dirname, '../../resources/js/utils/advertisementMeasurement.ts')], bundle: true, platform: 'node', format: 'esm', write: false });
const path = join(temporary, 'measurement.mjs');
writeFileSync(path, bundle.outputFiles[0].text);
const { AdvertisementMeasurementSession } = await import(pathToFileURL(path).href);
const data = (revision = 1) => ({ token: `public-test-token-${revision}`, revision_id: revision, endpoint: '/advertisement-events' });

function artwork(t, { loaded = true, ratio = 0, visible = true } = {}) {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    let observer;
    class Observer {
        constructor(callback, options) { this.callback = callback; this.options = options; observer = this; }
        observe(target) { this.target = target; }
        disconnect() { this.disconnected = true; }
        intersection(value) { this.callback([{ target: this.target, isIntersecting: value > 0, intersectionRatio: value }]); }
    }
    const doc = Object.assign(new EventTarget(), { visibilityState: visible ? 'visible' : 'hidden', defaultView: { IntersectionObserver: Observer } });
    const image = Object.assign(new EventTarget(), { ownerDocument: doc, isConnected: true, complete: loaded, naturalWidth: loaded ? 940 : 0 });
    const sent = [];
    const session = new AdvertisementMeasurementSession((value, type) => sent.push({ value, type }));
    const stop = session.observe(image, data());
    observer.intersection(ratio);
    t.after(stop);
    return { image, doc, sent, session, stop, observer, tick: (ms) => t.mock.timers.tick(ms), hide(value) { doc.visibilityState = value ? 'hidden' : 'visible'; doc.dispatchEvent(new Event('visibilitychange')); } };
}

test('a loaded image needs at least half visibility for one continuous second', t => {
    const a = artwork(t, { ratio: 0.49 });
    a.tick(2000); assert.equal(a.sent.length, 0);
    a.observer.intersection(0.5); a.tick(999); assert.equal(a.sent.length, 0);
    a.tick(1); assert.deepEqual(a.sent.map(x => x.type), ['impression']);
    a.observer.intersection(1); a.tick(5000); assert.equal(a.sent.length, 1);
});
test('being visible before the image loads does not count toward the viewing second', t => {
    const a = artwork(t, { loaded: false, ratio: 1 });
    a.tick(2000); assert.equal(a.sent.length, 0);
    a.image.naturalWidth = 940; a.image.dispatchEvent(new Event('load'));
    a.tick(999); assert.equal(a.sent.length, 0);
    a.tick(1); assert.equal(a.sent.length, 1);
});
test('leaving half visibility resets the complete qualifying interval', t => {
    const a = artwork(t, { ratio: 1 });
    a.tick(900); a.observer.intersection(0.49); a.tick(2000);
    assert.equal(a.sent.length, 0);
    a.observer.intersection(0.8); a.tick(999); assert.equal(a.sent.length, 0);
    a.tick(1); assert.equal(a.sent.length, 1);
});
test('a hidden tab cannot earn views and returning starts a new full second', t => {
    const a = artwork(t, { ratio: 1 });
    a.tick(900); a.hide(true); a.tick(3000); assert.equal(a.sent.length, 0);
    a.hide(false); a.tick(999); assert.equal(a.sent.length, 0);
    a.tick(1); assert.equal(a.sent.length, 1);
});
test('a failed or disconnected image never earns a queued impression', t => {
    const a = artwork(t, { ratio: 1 });
    a.tick(500); a.image.dispatchEvent(new Event('error')); a.tick(1000);
    assert.equal(a.sent.length, 0);
    a.image.naturalWidth = 940; a.image.dispatchEvent(new Event('load')); a.image.isConnected = false;
    a.tick(1000); assert.equal(a.sent.length, 0);
});
test('changing a carousel image cancels the previous timer and disconnects its observer', t => {
    const a = artwork(t, { ratio: 1 });
    a.tick(900); a.stop(); a.tick(2000);
    assert.equal(a.sent.length, 0); assert.equal(a.observer.disconnected, true);
});
test('rerendering or showing the same revision again cannot duplicate its page impression', t => {
    const a = artwork(t, { ratio: 1 });
    a.tick(1000); a.stop();
    const stop = a.session.observe(a.image, { ...data(), token: 'refreshed-test-token' });
    a.observer.intersection(1); a.tick(2000); stop();
    assert.equal(a.sent.length, 1);
});
test('clicks do not need a qualifying view and are bounded per revision and page', () => {
    const sent = [];
    const session = new AdvertisementMeasurementSession((value, type) => sent.push({ value, type }));
    assert.equal(session.click(), undefined);
    session.click(data()); session.click(data()); session.click({ ...data(), token: 'changed-test-token' }); session.click(data(2));
    assert.deepEqual(sent.map(x => [x.value.revision_id, x.type]), [[1, 'click'], [2, 'click']]);
});
test('missing visibility support does not invent impressions while deliberate clicks still work', () => {
    const sent = [];
    const session = new AdvertisementMeasurementSession((value, type) => sent.push(type));
    session.observe({ ownerDocument: { defaultView: {} } }, data())();
    session.click(data()); assert.deepEqual(sent, ['click']);
});
test('synchronous and asynchronous transport failures cannot block the caller or trigger duplicate attempts', async () => {
    for (const send of [() => { throw Error('Unavailable'); }, () => Promise.reject(Error('Unavailable'))]) {
        const session = new AdvertisementMeasurementSession(send);
        assert.doesNotThrow(() => session.click(data()));
        assert.equal(session.click(data()), undefined);
        await new Promise(resolve => setImmediate(resolve));
    }
});
test('the transport sends only a bounded token and type with same-origin CSRF protection', async t => {
    const requests = [];
    t.mock.method(globalThis, 'fetch', async (...args) => { requests.push(args); return { ok: true }; });
    const previous = globalThis.document;
    globalThis.document = { querySelector: () => ({ content: 'test-csrf' }) };
    t.after(() => { if (previous === undefined) delete globalThis.document; else globalThis.document = previous; });
    new AdvertisementMeasurementSession().click(data());
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(requests.length, 1);
    const [endpoint, request] = requests[0];
    assert.equal(endpoint, '/advertisement-events');
    assert.deepEqual(JSON.parse(request.body), { token: data().token, type: 'click' });
    assert.equal(request.headers['X-CSRF-TOKEN'], 'test-csrf');
    assert.equal(request.credentials, 'same-origin'); assert.equal(request.keepalive, true);
    assert.equal(request.cache, 'no-store'); assert.equal(request.mode, 'same-origin');
});

test('a rotated session cookie takes precedence over the stale page token', async t => {
    const requests = [];
    t.mock.method(globalThis, 'fetch', async (...args) => { requests.push(args); return { ok: true }; });
    const previous = globalThis.document;
    globalThis.document = { querySelector: () => ({ content: 'stale-test-csrf' }), cookie: 'other=value; XSRF-TOKEN=current%2Btest%3D' };
    t.after(() => { if (previous === undefined) delete globalThis.document; else globalThis.document = previous; });
    new AdvertisementMeasurementSession().click(data());
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(requests[0][1].headers['X-XSRF-TOKEN'], 'current+test=');
    assert.equal(requests[0][1].headers['X-CSRF-TOKEN'], undefined);
});
