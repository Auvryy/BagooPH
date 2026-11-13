import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createInertiaApp } from '@inertiajs/react';

const root = resolve(import.meta.dirname, '../..');
const directory = mkdtempSync(join(import.meta.dirname, '.tmp-transport-arrival-'));
after(() => { rmSync(directory, { recursive: true, force: true }); delete globalThis.arrivalHarness; });
const target = /Components\/Logistics\/TransportArrival\.tsx$/;
const bundled = await build({ stdin: { contents: "export { default } from '@/Components/Logistics/TransportArrival';", resolveDir: root, loader: 'tsx' },
    bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', write: false,
    plugins: [{ name: 'actual-arrival-controls', setup(builder) {
        builder.onResolve({ filter: /^@inertiajs\/react$/ }, args => target.test(args.importer) ? { path: 'inertia', namespace: 'arrival' } : undefined);
        builder.onLoad({ filter: /^inertia$/, namespace: 'arrival' }, () => ({ contents: "export * from '@inertiajs/react'; export const useForm=initial=>globalThis.arrivalHarness.useForm(initial);", resolveDir: root }));
        builder.onResolve({ filter: /^react$/ }, args => target.test(args.importer) ? { path: 'react', namespace: 'arrival' } : undefined);
        builder.onLoad({ filter: /^react$/, namespace: 'arrival' }, () => ({ contents: "export * from 'react'; export const useRef=initial=>globalThis.arrivalHarness.ref(initial);", resolveDir: root }));
        builder.onResolve({ filter: /^react\/jsx-runtime$/ }, args => target.test(args.importer) ? { path: 'jsx', namespace: 'arrival' } : undefined);
        builder.onLoad({ filter: /^jsx$/, namespace: 'arrival' }, () => ({ contents: "import {jsx as real,jsxs as many,Fragment} from 'react/jsx-runtime'; export {Fragment}; const track=(fn,type,props,key)=>{ const h=globalThis.arrivalHarness;if(props.onSubmit||props.onChange)h.handlers.push({type,props});if(typeof type==='function')h.keys.push(key);return fn(type,props,key); };export const jsx=(...args)=>track(real,...args);export const jsxs=(...args)=>track(many,...args);", resolveDir: root }));
    } }] });
const path = join(directory, 'arrival.mjs'); writeFileSync(path, bundled.outputFiles[0].text);
const Arrival = (await import(pathToFileURL(path).href)).default;
const initial = { value: { can_record: true, record: null }, manifestId: 9, version: 6, driver: 'Named original driver', plate: 'BGO-901', basePath: '/hub/manifests' };

class Harness {
    refs = []; cursor = 0; handlers = []; keys = []; posts = []; form;
    ref(initial) { return this.refs[this.cursor++] ??= { current: initial }; }
    useForm(initial) { return this.form ??= { data: structuredClone(initial), errors: {}, processing: false,
        setData: (key, value) => { this.form.data[key] = value; }, transform: callback => { this.transform = callback; },
        post: (url, options) => { this.posts.push({ url, options, data: structuredClone(this.transform(this.form.data)) }); this.form.processing = true; } }; }
    control(predicate) { const node = this.handlers.find(predicate); assert.ok(node, 'actual rendered control exists'); return node.props; }
    submit() { this.control(node => node.type === 'form').onSubmit({ preventDefault() {} }); }
    finish() { this.form.processing = false; this.posts.at(-1).options.onFinish(); }
}
async function render(h, props = initial, actor = 7) {
    h.cursor = 0; h.handlers = []; h.keys = [];
    let view;
    const output = await createInertiaApp({ page: { component: 'Arrival/Test', props: { ...props, auth: { user: { id: actor, role: 'logistics' } } }, url: '/hub/manifests/9', version: null },
        resolve: () => Arrival, setup: ({ App, props: supplied }) => { view = createElement(App, supplied); return view; }, render: () => renderToStaticMarkup(view) });
    return output.body;
}
async function check(run) { const h = globalThis.arrivalHarness = new Harness(); try { await run(h); } finally { delete globalThis.arrivalHarness; } }

test('arrival controls require the observed original plate and both physical confirmations', async () => check(async h => {
    const html = await render(h);
    assert.match(html, /Named original driver/); assert.match(html, /unopened sacks keep their separate holds/);
    assert.match(html, /Record vehicle arrival/); assert.doesNotMatch(html, /type="file"|recipient_id|amount_cents/);
    const plate = h.control(node => node.type === 'input' && node.props.maxLength === 40);
    const reason = h.control(node => node.type === 'textarea');
    const checks = h.handlers.filter(node => node.props.type === 'checkbox').map(node => node.props);
    plate.onChange({ target: { value: 'DIFFERENT' } }); reason.onChange({ target: { value: 'Actual original driver and vehicle arrived at this hub.' } });
    checks.forEach(control => control.onChange({ target: { checked: true } })); h.submit(); assert.equal(h.posts.length, 0);
    plate.onChange({ target: { value: ' bgo-901 ' } }); checks[1].onChange({ target: { checked: false } }); h.submit(); assert.equal(h.posts.length, 0);
    checks[1].onChange({ target: { checked: true } }); reason.onChange({ target: { value: 'x'.repeat(1001) } }); h.submit(); assert.equal(h.posts.length, 0);
    reason.onChange({ target: { value: '  Actual original driver and vehicle arrived at this hub.  ' } }); h.submit(); h.submit(); assert.equal(h.posts.length, 1);
    const first = h.posts[0]; assert.equal(first.url, '/hub/manifests/9/transport-arrival'); assert.equal(first.data.vehicle_plate, 'BGO-901');
    assert.equal(first.data.version, 6); assert.equal(first.data.arrival_confirmed, true); assert.equal(first.data.driver_identity_confirmed, true);
    assert.equal(first.data.reason, 'Actual original driver and vehicle arrived at this hub.');
    h.finish(); h.submit(); assert.equal(h.posts.length, 2); assert.deepEqual(h.posts[1].data, first.data);
}));

test('saved arrival replaces the form and form context includes current actor manifest and version', async () => check(async h => {
    await render(h); assert.ok(h.keys.includes('7:9:6'));
    await render(h, { ...initial, version: 8 }, 12); assert.ok(h.keys.includes('12:9:8'));
    const html = await render(h, { ...initial, value: { can_record: false, record: { reference: 'MFE-ACTUAL-ARRIVAL', created_at: '2026-11-14T01:00:00Z' } } });
    assert.match(html, /MFE-ACTUAL-ARRIVAL/); assert.match(html, /Parcel and sack receipts remain separate/);
    assert.doesNotMatch(html, /<form|Record vehicle arrival/);
    assert.equal(await render(h, { ...initial, value: { can_record: false, record: null } }), '');
}));
