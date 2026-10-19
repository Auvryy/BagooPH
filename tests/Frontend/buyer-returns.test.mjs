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
const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-buyer-returns-'));
after(() => { rmSync(temporary, { recursive: true, force: true }); delete globalThis.returnHarness; delete globalThis.route; });
const result = await build({ stdin: { contents: `export { default as Create } from '@/Pages/Returns/Create'; export { default as Show } from '@/Pages/Returns/Show'; export { default as Index } from '@/Pages/Returns/Index';`, resolveDir: root, loader: 'tsx' },
    bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', write: false, loader: { '.css': 'empty' }, plugins: [{ name: 'return-controls', setup(builder) {
        builder.onResolve({ filter: /^@inertiajs\/react$/ }, args => /Pages\/Returns\/(Create|Show)\.tsx$/.test(args.importer) ? { path: 'inertia', namespace: 'return-probe' } : undefined);
        builder.onLoad({ filter: /^inertia$/, namespace: 'return-probe' }, () => ({ contents: `export * from '@inertiajs/react'; import { useForm as real } from '@inertiajs/react'; export const useForm = initial => globalThis.returnHarness ? globalThis.returnHarness.useForm(initial) : real(initial);`, resolveDir: root }));
        builder.onResolve({ filter: /^react$/ }, args => /Pages\/Returns\/(Create|Show)\.tsx$/.test(args.importer) ? { path: 'react', namespace: 'return-probe' } : undefined);
        builder.onLoad({ filter: /^react$/, namespace: 'return-probe' }, () => ({ contents: `export * from 'react'; import React from 'react'; export const useRef = initial => globalThis.returnHarness ? globalThis.returnHarness.ref(initial) : React.useRef(initial);`, resolveDir: root }));
        builder.onResolve({ filter: /^react\/jsx-runtime$/ }, args => /Pages\/Returns\/(Create|Show)\.tsx$/.test(args.importer) ? { path: 'jsx', namespace: 'return-probe' } : undefined);
        builder.onLoad({ filter: /^jsx$/, namespace: 'return-probe' }, () => ({ contents: `import { jsx as real, jsxs as many, Fragment } from 'react/jsx-runtime'; export { Fragment }; const track = (fn,type,props,key) => { if(globalThis.returnHarness && (props.onSubmit || props.onChange || props.onClick)) globalThis.returnHarness.handlers.push({type,props}); return fn(type,props,key); }; export const jsx=(...args)=>track(real,...args);export const jsxs=(...args)=>track(many,...args);`, resolveDir: root }));
    } }] });
const path = join(temporary, 'returns.mjs'); writeFileSync(path, result.outputFiles[0].text); const ui = await import(pathToFileURL(path).href);
globalThis.route = name => name ? `/${name.replaceAll('.', '/')}` : { current: () => false, has: () => true };
const user = role => ({ id: 7, role, name: 'Ana Santos', email: `${role}@bagoo.test`, status: 'active', kyc_status: 'approved' });
const props = { order: { id: 30, order_number: 'BGO-RETURN' }, requestToken: 'signed-original-order-token', requestUntil: '2026-10-26T00:00:00Z', reasons: { damaged: 'Item damaged', wrong_item: 'Wrong item' },
    items: [{ id: 50, name: 'Everyday travel bag', color: 'Blue', size: 'Medium', quantity: 2, available_quantity: 1, unit_price: '250.00' }, { id: 51, name: 'Already requested item', quantity: 1, available_quantity: 0, unit_price: '150.00' }] };
const caseData = { id: 11, reference: 'BR-OWNED', version: 2, status: 'rejected', reason: 'damaged', description: 'The delivered item fastening is broken.', order: props.order, actions: ['escalate', 'withdraw'],
    response_until: '2026-10-22T00:00:00Z', appeal_until: '2026-10-23T00:00:00Z', dropoff_until: null, items: [{ id: 1, quantity: 1, purchase: { name: 'Everyday travel bag', color: 'Blue', size: 'Medium', unit_price: '250.00' } }],
    evidence: [{ id: 2, url: '/buyer-returns/11/evidence/2', bytes: 12000, mime: 'image/png' }], history: [{ reference: 'BRE-DECISION', event_type: 'reject', actor_role: 'seller', reason: 'The photo does not show the purchased item label. Please provide its original label.', target_status: 'rejected', created_at: '2026-10-20T00:00:00Z' }] };
async function render(Component, supplied, role = 'buyer') {
    if (globalThis.returnHarness) { globalThis.returnHarness.cursor = 0; globalThis.returnHarness.handlers = []; }
    let view; const response = await createInertiaApp({ page: { component: 'Returns/Show', props: { auth: { user: user(role) }, flash: {}, cartCount: 0, ...supplied }, url: '/buyer-returns/11', version: null },
        resolve: () => Component, setup: ({ App, props }) => { view = createElement(App, props); return view; }, render: () => renderToStaticMarkup(view) }); return response.body;
}
class Harness {
    cursor = 0; refs = []; handlers = []; posts = []; form;
    ref(initial) { return this.refs[this.cursor++] ??= { current: initial }; }
    useForm(initial) { return this.form ??= { data: initial, errors: {}, processing: false,
        setData: (key, value) => { if (typeof key === 'object') this.form.data = key; else this.form.data[key] = value; },
        setError: (key, value) => { this.form.errors[key] = value; }, clearErrors: (...keys) => { if (!keys.length) this.form.errors = {}; else keys.forEach(key => delete this.form.errors[key]); },
        post: (url, options) => { this.posts.push({ url, options, data: structuredClone(this.form.data) }); this.form.processing = true; } }; }
    control(test) { const match = this.handlers.find(test); assert.ok(match, 'expected actual rendered control'); return match.props; }
    submit() { this.control(node => node.type === 'form').onSubmit({ preventDefault() {} }); }
}

test('request form explains the selected window, original items, private photos and real upload limits', async () => {
    const html = await render(ui.Create, props);
    assert.match(html, /including after confirming receipt|Confirming receipt does not end this window/);
    assert.match(html, /One to five photos/); assert.match(html, /up to 5 MB each/); assert.match(html, /10–1,000 characters/);
    assert.match(html, /id="return-item-51"[^>]*disabled=""/); assert.match(html, /All purchased units already/);
    assert.doesNotMatch(html, /name="amount"|name="seller_id"|payment received|refund paid/i);
});

test('selecting an original unit uses its bounded quantity and rejects missing, excess or unsupported photos before posting', async () => {
    const h = globalThis.returnHarness = new Harness();
    try {
        await render(ui.Create, props); h.submit(); assert.match(h.form.errors.items, /at least one/);
        h.control(node => node.props.id === 'return-item-50').onChange({ target: { checked: true } });
        await render(ui.Create, props);
        const quantity = h.control(node => node.type === 'input' && node.props.type === 'number');
        assert.equal(quantity.min, '1'); assert.equal(quantity.max, 1); assert.deepEqual(h.form.data.items, [{ order_item_id: 50, quantity: 1 }]);
        for (const files of [[], Array(6).fill({ type: 'image/png', size: 1 }), [{ type: 'video/mp4', size: 1 }], [{ type: 'image/png', size: 5242881 }]]) {
            h.form.data.photos = files; h.submit(); assert.equal(h.posts.length, 0); assert.match(h.form.errors.photos, /one to five/);
        }
        h.form.data.photos = [{ name: 'damage.png', type: 'image/png', size: 5242880 }]; h.form.data.description = 'The fastening broke on arrival.'; h.form.data.reason = 'damaged';
        h.submit(); h.submit(); assert.equal(h.posts.length, 1); assert.equal(h.posts[0].url, '/buyer-returns/orders/30');
        assert.equal(h.posts[0].data.request_token, props.requestToken); assert.equal(h.posts[0].options.forceFormData, true);
        h.form.processing = false; h.posts[0].options.onFinish();
        h.form.errors = { description: 'Use meaningful plain text.', 'photos.0': 'Choose an actual image.' };
        const html = await render(ui.Create, props); assert.match(html, /Use meaningful plain text/); assert.match(html, /Choose an actual image/); assert.match(html, /damage.png/);
        assert.equal(h.form.data.photos.length, 1); h.submit(); assert.equal(h.posts.length, 2); assert.equal(h.posts[1].data.request_token, h.posts[0].data.request_token);
    } finally { delete globalThis.returnHarness; }
});

for (const role of ['buyer', 'seller', 'admin']) test(`${role} return history retains its portal, exact seller feedback and protected photo URL`, async () => {
    const html = await render(ui.Show, { returnCase: caseData }, role);
    assert.match(html, new RegExp(role === 'buyer' ? 'Marketplace' : role === 'seller' ? 'Seller Centre' : 'Admin Portal'));
    assert.match(html, /The photo does not show the purchased item label/); assert.match(html, /Ask admin to review/);
    assert.match(html, /href="\/buyer-returns\/11\/evidence\/2"/); assert.match(html, /Request admin review by/);
    assert.doesNotMatch(html, /buyer-return-evidence\/|creation_token_hash|Refund paid/);
});

test('decision submission retains the version and request token through recoverable errors and blocks double clicks', async () => {
    const h = globalThis.returnHarness = new Harness();
    try {
        await render(ui.Show, { returnCase: caseData });
        h.form.data.action = 'approve'; h.form.data.reason = 'An unauthorized action must not be posted.'; h.submit(); assert.equal(h.posts.length, 0);
        h.form.data.action = 'escalate'; h.form.data.reason = 'Please review the original label and purchase evidence.';
        h.submit(); h.submit(); assert.equal(h.posts.length, 1); assert.equal(h.posts[0].data.expected_version, 2);
        h.form.processing = false; h.posts[0].options.onFinish(); h.form.errors = { return: 'Reload the saved history before retrying.' };
        const html = await render(ui.Show, { returnCase: caseData }); assert.match(html, /Reload the saved history before retrying/);
        h.submit(); assert.equal(h.posts[1].data.request_token, h.posts[0].data.request_token);
        assert.equal(h.posts[1].data.reason, h.posts[0].data.reason);
    } finally { delete globalThis.returnHarness; }
});

test('empty return queues center their explanation and never fabricate a claim or repayment', async () => {
    const html = await render(ui.Index, { cases: { data: [], current_page: 1, last_page: 1 } });
    assert.match(html, /min-h-72 flex-col items-center justify-center/); assert.match(html, /No return requests/);
    assert.doesNotMatch(html, /BR-OWNED|Repaid|Sample return/);
});
