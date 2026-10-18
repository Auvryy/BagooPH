import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { File } from 'node:buffer';
import { build } from 'esbuild';
const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-marketplace-composer-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const bundle = await build({ entryPoints: [resolve(import.meta.dirname, '../../resources/js/hooks/useMarketplaceComposer.ts')],
    bundle: true, platform: 'node', format: 'esm', write: false, plugins: [{ name: 'isolated-composer', setup(builder) {
        builder.onResolve({ filter: /^react$/ }, () => ({ path: 'react', namespace: 'harness' }));
        builder.onLoad({ filter: /.*/, namespace: 'harness' }, () => ({ contents: `
            export const useState = initial => globalThis.composerHarness.state(initial);
            export const useRef = initial => globalThis.composerHarness.ref(initial);
            export const useEffect = (fn, deps) => globalThis.composerHarness.effect(fn, deps);` }));
        builder.onResolve({ filter: /catalogueRuntime$/ }, () => ({ path: 'runtime', namespace: 'invalidate' }));
        builder.onLoad({ filter: /.*/, namespace: 'invalidate' }, () => ({ contents: 'export const invalidateCatalogue = () => globalThis.composerHarness.invalidations++;' }));
    } }] });
const path = join(temporary, 'composer.mjs'); writeFileSync(path, bundle.outputFiles[0].text);
const { default: useComposer } = await import(pathToFileURL(path).href);
function harness() {
    const original = { fetch: globalThis.fetch, document: globalThis.document };
    const slots = [], effects = [], requests = []; let cursor = 0;
    const h = { requests, invalidations: 0,
        state(initial) { const i = cursor++; if (!(i in slots)) slots[i] = initial; return [slots[i], value => { slots[i] = typeof value === 'function' ? value(slots[i]) : value; }]; },
        ref(initial) { return this.state({ current: initial })[0]; },
        effect(fn, deps) { const i = cursor++; if (!effects[i] || deps.some((value, j) => value !== effects[i].deps[j])) effects[i] = { fn, deps, pending: true }; },
        render(context = 1) { cursor = 0; const result = useComposer(context); for (const effect of effects) if (effect?.pending) { effect.pending = false; effect.fn(); } return result; },
        close() { Object.assign(globalThis, original); delete globalThis.composerHarness; },
    };
    globalThis.composerHarness = h;
    globalThis.document = { querySelector: () => ({ content: 'test-csrf' }) };
    globalThis.fetch = (url, options) => new Promise((resolve, reject) => requests.push({ url, options, resolve, reject }));
    return h;
}
const response = (status, data) => ({ ok: status >= 200 && status < 300, status, headers: new Headers({ 'Content-Type': 'application/json' }), json: async () => data });
const message = { receiver_id: 2, shop_id: 3, product_id: 4, message: 'Please check this photo.' };

test('a failed send retains the image and request identity; an intentional later message gets a new identity', async () => {
    const h = harness();
    try {
        let composer = h.render();
        const image = new File(['image-data'], 'photo.jpg', { type: 'image/jpeg' });
        composer.selectImage(image); composer = h.render();
        const first = composer.send(message);
        const key = h.requests[0].options.body.get('request_key');
        assert.equal(h.requests[0].options.body.get('attachment').name, 'photo.jpg');
        h.requests[0].resolve(response(500, { success: false })); assert.equal(await first, undefined);
        composer = h.render(); assert.equal(composer.image, image); assert.match(composer.error, /not confirmed/);
        const retry = composer.send(message);
        assert.equal(h.requests[1].options.body.get('request_key'), key);
        h.requests[1].resolve(response(200, { success: true, message: { id: 1 } })); assert.equal((await retry).id, 1);
        composer = h.render(); assert.equal(composer.image, null); assert.equal(h.invalidations, 1);
        const next = composer.send(message);
        assert.notEqual(h.requests[2].options.body.get('request_key'), key);
        h.requests[2].resolve(response(200, { success: true, message: { id: 2 } })); await next;
    } finally { h.close(); }
});

test('duplicate clicks make one request and late success cannot clear another conversation’s draft image', async () => {
    const h = harness();
    try {
        const composer = h.render();
        const first = composer.send(message); const duplicate = composer.send(message);
        assert.equal(await duplicate, undefined); assert.equal(h.requests.length, 1);
        let other = h.render(9);
        const image = new File(['image'], 'new.png', { type: 'image/png' });
        other.selectImage(image); other = h.render(9);
        h.requests[0].resolve(response(200, { success: true, message: { id: 1 } }));
        assert.equal(await first, undefined); assert.equal(h.render(9).image, image);
    } finally { h.close(); }
});

test('file and text boundaries reject unsupported input with visible recovery feedback', async () => {
    const h = harness();
    try {
        let composer = h.render();
        composer.selectImage(new File(['video'], 'video.mp4', { type: 'video/mp4' }));
        composer = h.render(); assert.equal(composer.image, null); assert.match(composer.error, /JPG, PNG or WEBP/);
        composer.selectImage(new File([new Uint8Array(5 * 1024 * 1024 + 1)], 'large.jpg', { type: 'image/jpeg' }));
        composer = h.render(); assert.match(composer.error, /5 MB/);
        assert.equal(await composer.send({ ...message, message: 'a'.repeat(1001) }), undefined);
        assert.equal(h.requests.length, 0); assert.match(h.render().error, /1,000/);
    } finally { h.close(); }
});
