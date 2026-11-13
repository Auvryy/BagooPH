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
const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-loss-found-'));
after(() => { rmSync(temporary, { recursive: true, force: true }); delete globalThis.foundHarness; delete globalThis.route; });
const target = /Pages\/Hub\/LossFoundIntake\.tsx$/;
const bundle = await build({ stdin: { contents: "export {default} from '@/Pages/Hub/LossFoundIntake';", resolveDir: root, loader: 'tsx' },
    bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', write: false, loader: { '.css': 'empty' },
    plugins: [{ name: 'found-intake-controls', setup(builder) {
        builder.onResolve({ filter: /^@inertiajs\/react$/ }, args => target.test(args.importer) ? { path: 'inertia', namespace: 'found-probe' } : undefined);
        builder.onLoad({ filter: /^inertia$/, namespace: 'found-probe' }, () => ({ contents: "export * from '@inertiajs/react'; export const useForm=initial=>globalThis.foundHarness.useForm(initial);", resolveDir: root }));
        builder.onResolve({ filter: /^react$/ }, args => target.test(args.importer) ? { path: 'react', namespace: 'found-probe' } : undefined);
        builder.onLoad({ filter: /^react$/, namespace: 'found-probe' }, () => ({ contents: "export * from 'react';export const useRef=initial=>globalThis.foundHarness.ref(initial);export const useEffect=(fn,deps)=>globalThis.foundHarness.effect(fn,deps);", resolveDir: root }));
        builder.onResolve({ filter: /^react\/jsx-runtime$/ }, args => target.test(args.importer) ? { path: 'jsx', namespace: 'found-probe' } : undefined);
        builder.onLoad({ filter: /^jsx$/, namespace: 'found-probe' }, () => ({ contents: "import {jsx as one,jsxs as many,Fragment} from 'react/jsx-runtime';export {Fragment};const track=(fn,type,props,key)=>{if(props.onSubmit||props.onChange||props.onClick)globalThis.foundHarness.handlers.push({type,props});return fn(type,props,key);};export const jsx=(...args)=>track(one,...args);export const jsxs=(...args)=>track(many,...args);", resolveDir: root }));
    } }] });
const artifact = join(temporary, 'found.mjs'); writeFileSync(artifact, bundle.outputFiles[0].text);
const { default: Found } = await import(pathToFileURL(artifact).href);
globalThis.route = name => name ? '/'+name.replaceAll('.', '/') : { current: () => false, has: () => true };
class Harness {
    cursor = 0; effectCursor = 0; refs = []; effects = []; handlers = []; posts = []; queued = []; form;
    ref(initial) { return this.refs[this.cursor++] ??= { current: initial }; }
    effect(callback, dependencies) { const slot = this.effectCursor++; const prior = this.effects[slot];
        if (!prior || dependencies.some((value, index) => value !== prior[index])) { this.effects[slot] = [...dependencies]; this.queued.push(callback); } }
    useForm(initial) {
        return this.form ??= { data: initial, errors: {}, processing: false,
            setData: (key, value) => { if (typeof key === 'object') this.form.data = key; else this.form.data[key] = value; },
            clearErrors: () => { this.form.errors = {}; }, setError: (key, value) => { this.form.errors[key] = value; },
            transform: fn => { this.transform = fn; }, post: (url, options) => { this.posts.push({ url, options, data: structuredClone(this.transform(this.form.data)) }); this.form.processing = true; } };
    }
    submit() { const form = this.handlers.find(item => item.type === 'form'); assert.ok(form); form.props.onSubmit({ preventDefault() {} }); }
    finish() { this.form.processing = false; this.posts.at(-1).options.onFinish(); }
}
const original = { id: 7, barcode: 'BGO-ORIGINAL', manifest_reference: 'MFT-ORIGINAL', scope: 'Original parcel',
    hub: { id: 4, name: 'Receiving hub', code: 'BH-4' }, version: 2, can_intake: true, blocker: null, finding: null };
async function render(intake = original) {
    const h = globalThis.foundHarness; h.cursor = 0; h.effectCursor = 0; h.handlers = []; let view;
    const response = await createInertiaApp({ page: { component: 'Hub/LossFoundIntake', props: { auth: { user: { id: 3, name: 'Hub operator', role: 'logistics' } }, flash: {}, errors: {}, intake, requestToken: 'ORIGINAL-TOKEN' }, url: '/loss-found/7?hub_id=4', version: null },
        resolve: () => Found, setup: ({ App, props }) => { view = createElement(App, props); return view; }, render: () => renderToStaticMarkup(view) });
    return response.body;
}
function ready(h) { Object.assign(h.form.data, { barcode: original.barcode, reason: 'Found the original parcel and placed it in the named secure quarantine area.', proof: { type: 'image/png', size: 2048 }, quarantine_confirmed: true }); }
test('actual intake has bounded private evidence and quarantine controls without money or ordinary receiving actions', async () => {
    globalThis.foundHarness = new Harness(); const html = await render();
    assert.match(html, /Record actual quarantine intake/); assert.match(html, /Receiving hub/); assert.match(html, /maxLength="1000"/); assert.match(html, /up to 5 MB/);
    assert.doesNotMatch(html, /Buyer refund|seller protection|Wallet balance|Record delivery|Receive parcel/);
});
test('required exact waybill evidence limits and consent stop invalid intake before submission', async () => {
    const h = globalThis.foundHarness = new Harness(); await render(); h.submit(); assert.equal(h.posts.length, 0);
    for (const key of ['barcode', 'reason', 'proof', 'quarantine_confirmed']) assert.ok(h.form.errors[key]);
    ready(h); h.form.data.barcode = 'BRT-OTHER'; h.submit(); assert.equal(h.posts.length, 0);
    ready(h); h.form.data.proof.size = 5242881; h.submit(); assert.equal(h.posts.length, 0);
    ready(h); h.form.data.reason = 'A'.repeat(1001); h.submit(); assert.equal(h.posts.length, 0);
    ready(h); h.form.data.quarantine_confirmed = false; h.submit(); assert.equal(h.posts.length, 0);
});
test('uncertain responses retain the same request version and evidence; duplicate posts are guarded', async () => {
    const h = globalThis.foundHarness = new Harness(); await render(); ready(h); h.form.data.amount = '999.00';
    h.submit(); h.submit(); assert.equal(h.posts.length, 1);
    assert.equal(h.posts[0].url, '/loss-found/7'); assert.equal(h.posts[0].options.forceFormData, true);
    assert.deepEqual(Object.keys(h.posts[0].data).sort(), ['barcode', 'expected_version', 'hub_id', 'proof', 'quarantine_confirmed', 'reason', 'request_token']);
    h.finish(); await render({ ...original, version: 3 }); h.submit();
    assert.equal(h.posts[1].data.request_token, 'ORIGINAL-TOKEN'); assert.equal(h.posts[1].data.expected_version, 2); assert.deepEqual(h.posts[1].data.proof, h.posts[0].data.proof);
    h.posts[1].options.onSuccess({ props: { intake: { ...original, finding: { reference: 'FOUND' } } } });
    assert.equal(h.form.data.proof, null); assert.equal(h.form.data.reason, ''); assert.equal(h.form.data.quarantine_confirmed, false);
});
test('a saved finding hides new intake and explains the independent reconciliation hold', async () => {
    globalThis.foundHarness = new Harness(); const html = await render({ ...original, can_intake: false,
        finding: { reference: 'FOUND', recorded_at: '2026-11-13T12:00:00Z', at_selected_hub: true, proof_url: '/loss-found/7/proof?hub_id=4' } });
    assert.match(html, /awaiting reconciliation/); assert.match(html, /Download private intake evidence/); assert.doesNotMatch(html, /Record quarantine intake|<form/);
});
test('changed source blocks stale rendered submit and does not manufacture a receipt', async () => {
    const h = globalThis.foundHarness = new Harness(); await render(); ready(h);
    const html = await render({ ...original, can_intake: false, blocker: 'Original custody changed; independent reconciliation is required.' });
    assert.match(html, /Original custody changed/); assert.equal(h.handlers.some(item => item.type === 'form'), false); assert.equal(h.posts.length, 0);
});
