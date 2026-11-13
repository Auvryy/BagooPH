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
const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-loss-review-'));
after(() => { rmSync(temporary, { recursive: true, force: true }); delete globalThis.lossReviewHarness; delete globalThis.route; });
const target = /Pages\/Finance\/LossReview\.tsx$/;
const bundled = await build({ stdin: { contents: "export { default } from '@/Pages/Finance/LossReview';", resolveDir: root, loader: 'tsx' },
    bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', write: false, loader: { '.css': 'empty' },
    plugins: [{ name: 'loss-review-controls', setup(builder) {
        builder.onResolve({ filter: /^@inertiajs\/react$/ }, args => target.test(args.importer) ? { path: 'inertia', namespace: 'loss-review-probe' } : undefined);
        builder.onLoad({ filter: /^inertia$/, namespace: 'loss-review-probe' }, () => ({ contents: "export * from '@inertiajs/react'; export const useForm=initial=>globalThis.lossReviewHarness.useForm(initial);", resolveDir: root }));
        builder.onResolve({ filter: /^react$/ }, args => target.test(args.importer) ? { path: 'react', namespace: 'loss-review-probe' } : undefined);
        builder.onLoad({ filter: /^react$/, namespace: 'loss-review-probe' }, () => ({ contents: "export * from 'react'; export const useRef=initial=>globalThis.lossReviewHarness.ref(initial); export const useEffect=(effect,deps)=>globalThis.lossReviewHarness.effect(effect,deps);", resolveDir: root }));
        builder.onResolve({ filter: /^react\/jsx-runtime$/ }, args => target.test(args.importer) ? { path: 'jsx', namespace: 'loss-review-probe' } : undefined);
        builder.onLoad({ filter: /^jsx$/, namespace: 'loss-review-probe' }, () => ({ contents: "import {jsx as real,jsxs as many,Fragment} from 'react/jsx-runtime';export {Fragment}; const track=(fn,type,props,key)=>{if(props.onSubmit||props.onChange||props.onClick)globalThis.lossReviewHarness.handlers.push({type,props});return fn(type,props,key);};export const jsx=(...args)=>track(real,...args);export const jsxs=(...args)=>track(many,...args);", resolveDir: root }));
    } }] });
const artifact = join(temporary, 'loss-review.mjs'); writeFileSync(artifact, bundled.outputFiles[0].text);
const { default: LossReview } = await import(pathToFileURL(artifact).href);
globalThis.route = name => name ? '/'+name.replaceAll('.', '/') : { current: () => false, has: () => true };

class Harness {
    cursor = 0; effectCursor = 0; refs = []; effects = []; queued = []; handlers = []; posts = []; form;
    ref(initial) { return this.refs[this.cursor++] ??= { current: initial }; }
    effect(callback, dependencies) {
        const slot = this.effectCursor++; const previous = this.effects[slot];
        if (!previous || dependencies.some((value, index) => value !== previous[index])) { this.effects[slot] = [...dependencies]; this.queued.push(callback); }
    }
    useForm(initial) {
        return this.form ??= { data: initial, errors: {}, processing: false,
            setData: (name, value) => { if (typeof name === 'object') this.form.data = name; else this.form.data[name] = value; },
            clearErrors: () => { this.form.errors = {}; }, setError: (name, value) => { this.form.errors[name] = value; },
            transform: callback => { this.transform = callback; },
            post: (url, options) => { this.posts.push({ url, options, data: structuredClone(this.transform(this.form.data)) }); this.form.processing = true; } };
    }
    control(predicate) { const node = this.handlers.find(predicate); assert.ok(node, 'expected actual rendered control'); return node.props; }
    preview() { this.control(node => node.type === 'button' && node.props.type === 'button').onClick(); }
    submit() { this.control(node => node.type === 'form').onSubmit({ preventDefault() {} }); }
    finish(post = this.posts.at(-1), success = false) {
        this.form.processing = false;
        if (success) post.options.onSuccess?.();
        post.options.onFinish();
    }
}

const source = { observation_id: 13, observation_reference: 'MFE-ORIGINAL-MISSING', manifest_reference: 'MFT-ORIGINAL', company_name: 'Bagoo Original Logistics', barcode: 'BGO-ORIGINAL', direction: 'outbound', scope: 'undelivered' };
const basis = { original_merchandise_cents: 20000, buyer_paid_shipping_cents: 5000, actual_buyer_paid_cents: 25000,
    buyer_refund_cents: 25000, seller_protection_cap_cents: 18000, protected_earned_shipping_cents: 833 };
const amounts = { buyer_refund_cents: 25000, seller_protection_cents: 18000, protected_earned_shipping_cents: 833,
    company_funding_due_cents: 18833, original_escrow_required_cents: 25000, reserve_goal_cents: 43833 };
const preview = { input: { seller_protection: '180.00', investigation_reference: 'ORIGINAL-INVESTIGATION' }, amounts, review_token: 'a'.repeat(64) };
const props = { order: { id: 4, order_number: 'BGO-ORIGINAL-PURCHASE' }, source, basis, preview: null, blocker: null, requestToken: 'original-approval-token' };

async function render(changes = {}) {
    const h = globalThis.lossReviewHarness; h.cursor = 0; h.effectCursor = 0; h.handlers = []; h.queued = [];
    let view;
    const response = await createInertiaApp({ page: { component: 'Finance/LossReview', props: {
        auth: { user: { id: 7, role: 'admin', name: 'Independent admin', status: 'active', kyc_status: 'approved', canManageReturnLiabilities: true } },
        flash: {}, errors: {}, ...props, ...changes }, url: '/shipment-finances/missing/13/review', version: null }, resolve: () => LossReview,
        setup: ({ App, props: appProps }) => { view = createElement(App, appProps); return view; }, render: () => renderToStaticMarkup(view) });
    h.queued.forEach(callback => callback()); return response.body;
}
const ready = h => Object.assign(h.form.data, { reason: 'I independently verified the original missing custody, responsible company and approved amounts.',
    proof: { type: 'image/png', size: 4000, name: 'actual-investigation.png' }, loss_confirmed: true });

test('loss preview sends only safe review fields and failed requests retain private evidence and the exact approval identity', async () => {
    const h = globalThis.lossReviewHarness = new Harness(); await render();
    Object.assign(h.form.data, preview.input); ready(h); const proof = h.form.data.proof; const token = h.form.data.request_token;
    h.preview(); h.preview(); assert.equal(h.posts.length, 1);
    assert.equal(h.posts[0].url, '/shipment-finances/missing/13/preview'); assert.deepEqual(h.posts[0].data, preview.input);
    h.finish(); await render({ preview, requestToken: 'refreshed-page-token' });
    assert.equal(h.form.data.proof, proof); assert.equal(h.form.data.loss_confirmed, true); assert.equal(h.form.data.request_token, token);
    h.form.data.buyer_id = 999; h.form.data.amount_cents = 999999;
    h.submit(); h.submit(); assert.equal(h.posts.length, 2); const original = h.posts[1];
    assert.equal(original.url, '/shipment-finances/missing/13/approve'); assert.equal(original.options.forceFormData, true);
    assert.deepEqual(Object.keys(original.data).sort(), ['seller_protection', 'investigation_reference', 'review_token', 'request_token', 'reason', 'proof', 'loss_confirmed'].sort());
    assert.equal(original.data.review_token, preview.review_token); assert.equal(original.data.request_token, token);
    h.finish(original); h.form.errors = { proof: 'The server could not confirm the retained upload.' };
    const failed = await render({ preview, requestToken: 'another-refreshed-token' }); assert.match(failed, /could not confirm the retained upload/);
    assert.equal(h.form.data.proof, proof); assert.equal(h.form.data.loss_confirmed, true); h.submit();
    assert.deepEqual(h.posts[2].data, original.data);
});

test('changed details clear reason file and consent and disable approval until a matching preview returns', async () => {
    const h = globalThis.lossReviewHarness = new Harness(); await render({ preview }); ready(h);
    h.control(node => node.props.id === 'seller-protection').onChange({ target: { value: '179.00' } });
    assert.equal(h.form.data.reason, ''); assert.equal(h.form.data.proof, null); assert.equal(h.form.data.loss_confirmed, false);
    const stale = await render({ preview }); assert.match(stale, /Details changed/);
    assert.doesNotMatch(stale, /id="loss-confirmation-title"|id="loss-reason"|id="loss-proof"/);
    assert.equal(h.handlers.some(node => node.props.id === 'loss-reason' || node.props.id === 'loss-proof'), false);
    h.submit(); assert.equal(h.posts.length, 0);
    Object.assign(h.form.data, preview.input); ready(h); await render({ preview });
    h.control(node => node.props.id === 'investigation-reference').onChange({ target: { value: 'DIFFERENT-INVESTIGATION' } });
    await render({ preview }); h.submit(); assert.equal(h.posts.length, 0); assert.equal(h.form.data.proof, null);
});

test('successful completion clears sensitive fields while a fresh page restores only preview details', async () => {
    const h = globalThis.lossReviewHarness = new Harness(); await render({ preview });
    assert.equal(h.form.data.seller_protection, '180.00'); assert.equal(h.form.data.investigation_reference, 'ORIGINAL-INVESTIGATION');
    assert.equal(h.form.data.reason, ''); assert.equal(h.form.data.proof, null); assert.equal(h.form.data.loss_confirmed, false);
    ready(h); h.submit(); h.finish(undefined, true);
    assert.equal(h.form.data.reason, ''); assert.equal(h.form.data.proof, null); assert.equal(h.form.data.loss_confirmed, false);
    assert.equal(h.form.data.request_token, props.requestToken);
});

test('canonical decimal limits and investigation bounds are checked before preview while zero protection is allowed', async () => {
    const h = globalThis.lossReviewHarness = new Harness(); await render(); h.form.data.investigation_reference = 'ORIGINAL-INVESTIGATION';
    for (const value of ['-1', '01', '1e2', '1.234', '180.01', '9999999999']) {
        h.form.data.seller_protection = value; h.preview(); assert.equal(h.posts.length, 0); assert.ok(h.form.errors.seller_protection);
    }
    h.form.data.seller_protection = '0.00';
    for (const value of ['ab', 'A'.repeat(121)]) { h.form.data.investigation_reference = value; h.preview(); assert.equal(h.posts.length, 0); assert.ok(h.form.errors.investigation_reference); }
    h.form.data.investigation_reference = ' ACTUAL-REFERENCE '; h.preview();
    assert.deepEqual(h.posts[0].data, { seller_protection: '0.00', investigation_reference: 'ACTUAL-REFERENCE' });
});

test('approval requires bounded private evidence reason and explicit independent consent', async () => {
    const h = globalThis.lossReviewHarness = new Harness(); await render({ preview }); ready(h);
    for (const proof of [null, { type: 'image/png', size: 0 }, { type: 'image/png', size: 5242881 }, { type: 'text/plain', size: 4000 }]) {
        h.form.data.proof = proof; h.submit(); assert.equal(h.posts.length, 0); assert.ok(h.form.errors.proof);
    }
    ready(h); h.form.data.reason = 'short'; h.submit(); assert.equal(h.posts.length, 0); assert.ok(h.form.errors.reason);
    ready(h); h.form.data.reason = 'A'.repeat(1001); h.submit(); assert.equal(h.posts.length, 0); assert.ok(h.form.errors.reason);
    ready(h); h.form.data.loss_confirmed = false; h.submit(); assert.equal(h.posts.length, 0); assert.ok(h.form.errors.loss_confirmed);
    ready(h); h.form.data.proof = { type: 'application/pdf', size: 5242880, name: 'investigation.pdf' }; h.submit(); assert.equal(h.posts.length, 1);
    assert.equal(h.posts[0].data.proof.size, 5242880);
});

test('zero buyer payment is distinct from seller protection and earned shipping and blocked evidence has no approval form', async () => {
    const h = globalThis.lossReviewHarness = new Harness();
    const html = await render({ basis: { ...basis, actual_buyer_paid_cents: 0, buyer_refund_cents: 0 }, preview: { ...preview,
        amounts: { ...amounts, buyer_refund_cents: 0, original_escrow_required_cents: 0, reserve_goal_cents: 18833 } } });
    assert.match(html, /No buyer refund due/); assert.match(html, /Separate seller protection/); assert.match(html, /Actual earned shipping retained/);
    assert.match(html, /Responsible logistics company/); assert.match(html, /Bagoo Original Logistics/);
    assert.match(html, /Company funding required/); assert.match(html, /188\.33/); assert.match(html, /8\.33/); assert.match(html, /180\.00/);
    const blocked = await render({ source: null, basis: null, blocker: 'The original missing observation now has an actual receipt.' });
    assert.match(blocked, /now has an actual receipt/); assert.doesNotMatch(blocked, /<form/);
});

test('incomplete or unsigned previews cannot authorize approval or guess missing funding as zero', async () => {
    const h = globalThis.lossReviewHarness = new Harness(); const missing = { ...preview.amounts }; delete missing.company_funding_due_cents;
    let html = await render({ preview: { ...preview, amounts: missing } }); ready(h);
    assert.match(html, /preview is incomplete/); assert.match(html, /Unavailable/); h.submit(); assert.equal(h.posts.length, 0);
    html = await render({ preview: { ...preview, review_token: '' } });
    assert.match(html, /preview is incomplete/); h.submit(); assert.equal(h.posts.length, 0);
});

test('a different original observation resets sensitive fields and uses its own supplied retry identity', async () => {
    const h = globalThis.lossReviewHarness = new Harness(); await render({ preview }); ready(h);
    const changes = { source: { ...source, observation_id: 14, barcode: 'BRT-SEPARATE', scope: 'buyer_return', direction: 'buyer_return' },
        preview: { ...preview, input: { seller_protection: '0.00', investigation_reference: 'OTHER-INVESTIGATION' } }, requestToken: 'other-observation-token' };
    await render(changes); await render(changes);
    assert.equal(h.form.data.request_token, 'other-observation-token'); assert.equal(h.form.data.seller_protection, '0.00');
    assert.equal(h.form.data.proof, null); assert.equal(h.form.data.reason, ''); assert.equal(h.form.data.loss_confirmed, false);
});
