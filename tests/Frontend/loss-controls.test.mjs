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
const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-loss-controls-'));
after(() => { rmSync(temporary, { recursive: true, force: true }); delete globalThis.lossControlsHarness; });
const target = /Components\/Finance\/LossControls\.tsx$/;
const bundled = await build({ stdin: { contents: "export { default } from '@/Components/Finance/LossControls';", resolveDir: root, loader: 'tsx' },
    bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', write: false,
    plugins: [{ name: 'loss-controls-interactions', setup(builder) {
        builder.onResolve({ filter: /^@inertiajs\/react$/ }, args => target.test(args.importer) ? { path: 'inertia', namespace: 'loss-controls' } : undefined);
        builder.onLoad({ filter: /^inertia$/, namespace: 'loss-controls' }, () => ({ contents: "export * from '@inertiajs/react'; export const useForm=initial=>globalThis.lossControlsHarness.useForm(initial);", resolveDir: root }));
        builder.onResolve({ filter: /^react$/ }, args => target.test(args.importer) ? { path: 'react', namespace: 'loss-controls' } : undefined);
        builder.onLoad({ filter: /^react$/, namespace: 'loss-controls' }, () => ({ contents: "export * from 'react'; export const useRef=initial=>globalThis.lossControlsHarness.ref(initial); export const useEffect=(effect,deps)=>globalThis.lossControlsHarness.effect(effect,deps);", resolveDir: root }));
        builder.onResolve({ filter: /^react\/jsx-runtime$/ }, args => target.test(args.importer) ? { path: 'jsx', namespace: 'loss-controls' } : undefined);
        builder.onLoad({ filter: /^jsx$/, namespace: 'loss-controls' }, () => ({ contents: "import {jsx as real,jsxs as many,Fragment} from 'react/jsx-runtime';export {Fragment};const track=(fn,type,props,key)=>{if(props.onSubmit||props.onChange||props.onClick)globalThis.lossControlsHarness.handlers.push({type,props});return fn(type,props,key);};export const jsx=(...args)=>track(real,...args);export const jsxs=(...args)=>track(many,...args);", resolveDir: root }));
    } }] });
const artifact = join(temporary, 'loss-controls.mjs'); writeFileSync(artifact, bundled.outputFiles[0].text);
const Controls = (await import(pathToFileURL(artifact).href)).default;
class Harness {
    forms = {}; refs = []; effects = []; handlers = []; posts = []; cursor = 0; effectCursor = 0;
    ref(initial) { return this.refs[this.cursor++] ??= { current: initial }; }
    effect(effect, deps) { const slot = this.effectCursor++; const prior = this.effects[slot]; if (!prior || deps.some((value, index) => value !== prior[index])) { this.effects[slot] = deps; effect(); } }
    useForm(initial) {
        const name = 'action' in initial ? 'action' : 'decision' in initial ? 'review' : 'credit';
        if (!this.forms[name]) {
            const form = this.forms[name] = { data: initial, errors: {}, processing: false, transform: fn => { form.transformer = fn; },
                setData: (key, value) => { if (typeof key === 'object') form.data = key; else form.data[key] = value; },
                clearErrors: () => { form.errors = {}; }, setError: (key, value) => { form.errors[key] = value; },
                post: (url, options) => { this.posts.push({ url, options, data: structuredClone(form.transformer(form.data)) }); form.processing = true; } };
        }
        return this.forms[name];
    }
    control(test) { const control = this.handlers.find(test); assert.ok(control, 'expected an actual rendered control'); return control.props; }
    submit(label = 'Update original loss finances') { this.control(item => item.type === 'form' && item.props['aria-label'] === label).onSubmit({ preventDefault() {} }); }
    finish(name = 'action') { this.forms[name].processing = false; this.posts.at(-1).options.onFinish(); }
}
async function render(value, planId = 11, actorId = null) {
    const h = globalThis.lossControlsHarness; h.cursor = 0; h.effectCursor = 0; h.handlers = [];
    let view;
    const response = await createInertiaApp({ page: { component: 'Finance/LossControls', props: { planId, value, ...(actorId ? { auth: { user: { id: actorId } } } : {}) }, url: '/shipment-finances/'+planId, version: null },
        resolve: () => Controls, setup: ({ App, props }) => { view = createElement(App, props); return view; }, render: () => renderToStaticMarkup(view) });
    return response.body;
}
const base = { version: 1, actions: [], receivers: [], hold_message: null, funding: null, credits: null, disposition: null };
const action = (id, extra = {}) => ({ id, label: 'Original '+id, confirmation: 'payment_confirmed', proof: false, amount: false, receiver: false, source: false, cash_reference: false, maximum_cents: null, sources: [], ...extra });
const choose = (h, id) => h.control(item => item.type === 'select' && item.props.children.some?.(child => child?.props?.value === '')).onChange({ target: { value: id } });
const ready = h => { h.forms.action.data.reason = 'Checked the actual original participant, amount and private evidence.'; h.forms.action.data.confirmed = true; };

test('unused grant returns require the selected original approval and owner consent without accepting a client amount', async () => {
    const h = globalThis.lossControlsHarness = new Harness();
    const value = { ...base, version: 7, actions: [action('return-unused-credit', { confirmation: 'unused_credit_return_confirmed', source: true,
        sources: [{ id: 31, label: 'Original first grant', amount_cents: 3833 }, { id: 32, label: 'Original second grant', amount_cents: 2500 }] })] };
    await render(value); choose(h, 'return-unused-credit'); let html = await render(value); ready(h);
    assert.match(html, /Original approved grant/); assert.match(html, /I authorize returning only these unused reviewed credits from my company Wallet/);
    assert.doesNotMatch(html, /type="file"|Amount \(PHP\)|Actual receiving admin/);
    h.submit(); assert.equal(h.posts.length, 0); assert.ok(h.forms.action.errors.source_event_id);
    h.control(item => item.type === 'select' && item.props.children.some?.(child => child?.props?.children === 'Select the original grant approval'))
        .onChange({ target: { value: '31' } });
    html = await render(value); assert.match(html, /Return [\s\S]*38\.33[\s\S]*to the original demo issuance/);
    assert.match(html, /Company debt, paid compensation and the parcel hold remain separate/);
    h.control(item => item.type === 'input' && item.props.type === 'checkbox').onChange({ target: { checked: false } });
    h.submit(); assert.equal(h.posts.length, 0); assert.ok(h.forms.action.errors.confirmed);
    h.control(item => item.type === 'input' && item.props.type === 'checkbox').onChange({ target: { checked: true } });
    Object.assign(h.forms.action.data, { amount: '999.00', recipient_id: '999', proof: { size: 2048, type: 'image/png' }, cash_reference: 'UNRELATED' });
    h.submit(); h.submit(); assert.equal(h.posts.length, 1);
    assert.deepEqual(Object.keys(h.posts[0].data).sort(), ['action', 'expected_version', 'reason', 'request_token', 'source_event_id', 'unused_credit_return_confirmed']);
    assert.equal(h.posts[0].data.source_event_id, '31'); assert.equal(h.posts[0].data.unused_credit_return_confirmed, true);
    assert.equal(h.posts[0].data.expected_version, 7); assert.equal(h.posts[0].options.forceFormData, false);
});

test('an uncertain unused grant return keeps its original identity and resets only after the saved authority is confirmed', async () => {
    const h = globalThis.lossControlsHarness = new Harness();
    const value = { ...base, version: 9, actions: [action('return-unused-credit', { confirmation: 'unused_credit_return_confirmed', source: true,
        sources: [{ id: 31, label: 'Original reviewed grant', amount_cents: 3833 }] })] };
    await render(value); choose(h, 'return-unused-credit'); await render(value); ready(h);
    h.submit(); const original = h.posts[0].data; h.finish();
    await render({ ...value, version: 10 }); h.submit();
    assert.deepEqual(h.posts[1].data, original);
    h.posts[1].options.onSuccess({ props: { loss: { id: 11, controls: { version: 10 } } } }); h.finish();
    await render({ ...value, version: 10, actions: [] });
    assert.equal(h.forms.action.data.reason, ''); assert.equal(h.forms.action.data.confirmed, false);
    assert.equal(h.forms.action.data.source_event_id, ''); assert.equal(h.forms.action.data.expected_version, 10);
    assert.notEqual(h.forms.action.data.request_token, original.request_token);
    assert.equal(h.handlers.some(item => item.type === 'form' && item.props['aria-label'] === 'Update original loss finances'), false);
});

test('restoring a company source uses only its actual contribution and distinct consent before any reviewed grant return', async () => {
    const h = globalThis.lossControlsHarness = new Harness();
    const value = { ...base, version: 12, actions: [
        action('return-company-source', { confirmation: 'company_source_return_confirmed', source: true, sources: [{ id: 40, label: 'Original reserve deposit', amount_cents: 15000 }] }),
        action('return-unused-credit', { confirmation: 'unused_credit_return_confirmed', source: true, sources: [{ id: 31, label: 'Original grant approval', amount_cents: 3833 }] }),
    ] };
    await render(value); choose(h, 'return-company-source'); let html = await render(value); ready(h);
    assert.match(html, /Original company contribution/); assert.match(html, /Restore [\s\S]*150\.00[\s\S]*to your company Wallet/);
    assert.match(html, /Reviewed credits retain their original restrictions/); assert.match(html, /keeping reviewed credits restricted to their original grant/);
    assert.doesNotMatch(html, /type="file"|Amount \(PHP\)|Actual receiving admin/);
    h.forms.action.data.source_event_id = '31'; h.submit(); assert.equal(h.posts.length, 0);
    h.control(item => item.type === 'select' && item.props.children.some?.(child => child?.props?.children === 'Select the original reserve contribution'))
        .onChange({ target: { value: '40' } });
    h.control(item => item.type === 'input' && item.props.type === 'checkbox').onChange({ target: { checked: false } });
    h.submit(); assert.equal(h.posts.length, 0);
    h.control(item => item.type === 'input' && item.props.type === 'checkbox').onChange({ target: { checked: true } });
    Object.assign(h.forms.action.data, { amount: '999.00', recipient_id: '999', proof: { size: 3000, type: 'image/png' } });
    h.submit(); h.submit(); assert.equal(h.posts.length, 1);
    assert.deepEqual(Object.keys(h.posts[0].data).sort(), ['action', 'company_source_return_confirmed', 'expected_version', 'reason', 'request_token', 'source_event_id']);
    assert.equal(h.posts[0].data.source_event_id, '40'); assert.equal(h.posts[0].data.company_source_return_confirmed, true);
    const token = h.posts[0].data.request_token; h.finish();
    await render({ ...value, version: 13 }); h.submit(); assert.deepEqual(h.posts[1].data, h.posts[0].data); h.finish();
    choose(h, 'return-unused-credit'); html = await render({ ...value, version: 13 });
    assert.equal(h.forms.action.data.confirmed, false); assert.equal(h.forms.action.data.reason, '');
    assert.equal(h.forms.action.data.proof, null); assert.equal(h.forms.action.data.source_event_id, '31');
    assert.notEqual(h.forms.action.data.request_token, token); assert.equal(h.forms.action.data.expected_version, 13);
    assert.match(html, /Original approved grant/); assert.doesNotMatch(html, /Original company contribution/);
    h.submit(); assert.equal(h.posts.length, 2);
});

test('confirmed loss accounting sends only its retained disposition consent and never uploads or pays a new amount', async () => {
    const h = globalThis.lossControlsHarness = new Harness();
    const value = { ...base, actions: [action('account-loss', { confirmation: 'loss_accounting_confirmed' })] };
    await render(value); choose(h, 'account-loss'); await render(value); ready(h);
    Object.assign(h.forms.action.data, { amount: '999.00', recipient_id: '999', proof: { size: 2048, type: 'image/png' } });
    h.submit(); assert.equal(h.posts.length, 1);
    assert.deepEqual(Object.keys(h.posts[0].data).sort(), ['action', 'expected_version', 'loss_accounting_confirmed', 'reason', 'request_token']);
    assert.equal(h.posts[0].data.action, 'account-loss'); assert.equal(h.posts[0].data.loss_accounting_confirmed, true);
    assert.equal(h.posts[0].options.forceFormData, false);
});

test('retained loss accounting explains its separate physical and money boundary without a second receipt action', async () => {
    globalThis.lossControlsHarness = new Harness();
    const html = await render({ ...base, accounting: { reference: 'ORIGINAL-ACCOUNTING', verified: true } });
    assert.match(html, /accounted as a confirmed loss/); assert.match(html, /Physical arrivals and money remain separate/);
    assert.doesNotMatch(html, /<form|Record delivery|Parcel received/);
});

test('Wallet action sends only its selected confirmation and preserves the original identity until confirmed success', async () => {
    const h = globalThis.lossControlsHarness = new Harness();
    const value = { ...base, actions: [action('reserve-original', { confirmation: 'original_reserve_confirmed' })] };
    await render(value); choose(h, 'reserve-original'); await render(value); ready(h);
    const token = h.forms.action.data.request_token;
    h.submit(); h.submit(); assert.equal(h.posts.length, 1);
    assert.deepEqual(Object.keys(h.posts[0].data).sort(), ['action', 'expected_version', 'original_reserve_confirmed', 'reason', 'request_token']);
    assert.equal(h.posts[0].url, '/shipment-finances/11/actions'); assert.equal(h.posts[0].data.original_reserve_confirmed, true);
    h.finish(); await render({ ...value, version: 2 }); h.submit();
    assert.equal(h.posts[1].data.request_token, token); assert.equal(h.posts[1].data.expected_version, 1);
    h.posts[1].options.onSuccess({ props: { loss: { id: 11, controls: { version: 2 } } } }); h.finish();
    assert.notEqual(h.forms.action.data.request_token, token); assert.equal(h.forms.action.data.expected_version, 2);
    assert.equal(h.forms.action.data.reason, ''); assert.equal(h.forms.action.data.confirmed, false);
});

test('actual cash offers enforce their owned limit, receiving admin and private proof without posting extra authority', async () => {
    const h = globalThis.lossControlsHarness = new Harness();
    const value = { ...base, receivers: [{ id: 8, name: 'Actual receiving admin' }], actions: [action('offer-advance', { proof: true, amount: true, receiver: true, cash_reference: true, maximum_cents: 5000 })] };
    await render(value); choose(h, 'offer-advance'); await render(value); ready(h);
    Object.assign(h.forms.action.data, { amount: '50.01', recipient_id: '8', cash_reference: 'ACTUAL-TRANSFER', proof: { size: 4000, type: 'image/png' } });
    h.submit(); assert.equal(h.posts.length, 0); assert.ok(h.forms.action.errors.amount);
    h.forms.action.data.amount = '50.00'; h.forms.action.data.proof.size = 5242881; h.submit(); assert.equal(h.posts.length, 0); assert.ok(h.forms.action.errors.proof);
    h.forms.action.data.proof = { size: 4000, type: 'image/png' }; h.forms.action.data.recipient_id = '999'; h.submit(); assert.equal(h.posts.length, 0);
    Object.assign(h.forms.action.data, { recipient_id: '8', amount_cents: 99999, buyer_id: 20, source_event_id: '77', cash_reference: ' ACTUAL-TRANSFER ' });
    h.submit(); assert.equal(h.posts.length, 1); const sent = h.posts[0].data;
    assert.equal(sent.amount, '50.00'); assert.equal(sent.recipient_id, '8'); assert.equal(sent.cash_reference, 'ACTUAL-TRANSFER');
    assert.equal(sent.payment_confirmed, true); assert.equal(h.posts[0].options.forceFormData, true);
    for (const key of ['buyer_id', 'amount_cents', 'source_event_id', 'company_id', 'confirmed']) assert.equal(key in sent, false);
});

test('named receipts and buyer acknowledgements retain their exact original source without an entered payout amount', async () => {
    for (const receipt of [true, false]) {
        const h = globalThis.lossControlsHarness = new Harness();
        const id = receipt ? 'receive-company' : 'confirm-buyer-receipt';
        const value = { ...base, actions: [action(id, { source: true, proof: receipt, confirmation: receipt ? 'payment_confirmed' : 'action_confirmed', sources: [{ id: 9, label: 'My original payment', amount_cents: 12500 }] })] };
        await render(value); choose(h, id); await render(value); ready(h);
        h.forms.action.data.amount = '999.00'; h.forms.action.data.source_event_id = '99';
        if (receipt) h.forms.action.data.proof = { size: 2048, type: 'application/pdf' };
        h.submit(); assert.equal(h.posts.length, 0); h.forms.action.data.source_event_id = '9'; h.submit();
        assert.equal(h.posts[0].data.source_event_id, '9'); assert.equal('amount' in h.posts[0].data, false);
        assert.equal('cash_reference' in h.posts[0].data, false); assert.equal('recipient_id' in h.posts[0].data, false);
        assert.equal('proof' in h.posts[0].data, receipt); assert.equal(h.posts[0].data[receipt ? 'payment_confirmed' : 'action_confirmed'], true);
    }
});

test('company credit requests are bounded and keep their original version on an uncertain response', async () => {
    const h = globalThis.lossControlsHarness = new Harness();
    const value = { ...base, credits: { maximum_cents: 10000, wallet_balance_cents: 0, requests: [] } };
    await render(value); Object.assign(h.forms.credit.data, { amount: '100.01', reason: 'The original company needs only its actual unfunded shortfall.' });
    h.submit('Request original company funding credits'); assert.equal(h.posts.length, 0);
    h.forms.credit.data.amount = '100.00'; h.submit('Request original company funding credits');
    assert.equal(h.posts[0].url, '/shipment-finances/11/wallet-funding-credits'); assert.equal(h.posts[0].data.expected_version, 1);
    const token = h.forms.credit.data.request_token; h.finish('credit'); await render({ ...value, version: 3 });
    h.submit('Request original company funding credits'); assert.equal(h.posts[1].data.request_token, token); assert.equal(h.posts[1].data.expected_version, 1);
    h.finish('credit'); await render({ ...value, version: 3 });
    h.control(item => item.type === 'button' && item.props.children === 'Start new request').onClick();
    assert.equal(h.forms.credit.data.expected_version, 3); assert.notEqual(h.forms.credit.data.request_token, token); assert.equal(h.forms.credit.data.reason, '');
});

test('held original grants can be declined without approval consent or any client-selected recipient', async () => {
    const h = globalThis.lossControlsHarness = new Harness();
    const request = { id: 5, reference: 'ORIGINAL-GRANT', status: 'pending', version: 1, amount_cents: 10000, reason: 'Original company shortfall.', review_reason: null, can_review: true, can_approve: false, wallet_reference: null };
    const value = { ...base, hold_message: 'The original source needs reconciliation.', credits: { maximum_cents: 0, wallet_balance_cents: null, available_for_authority_cents: null, requests: [request] } };
    const html = await render(value); assert.doesNotMatch(html, /option value="approved"/);
    assert.doesNotMatch(html, /Available for this loss/);
    Object.assign(h.forms.review.data, { decision: 'approved', confirmed: true, reason: 'Independent review of the exact original company funding request.' });
    h.submit('Review original company funding credits'); assert.equal(h.posts.length, 0);
    h.forms.review.data.decision = 'rejected'; h.submit('Review original company funding credits');
    assert.equal(h.posts[0].url, '/shipment-finances/11/wallet-funding-credits/5/review');
    assert.equal('demo_credit_confirmed' in h.posts[0].data, false); assert.equal('recipient_id' in h.posts[0].data, false);
});

test('independent grant approval requires consent and sends the original request version without a new amount', async () => {
    const h = globalThis.lossControlsHarness = new Harness();
    const request = { id: 5, reference: 'ORIGINAL-GRANT', status: 'pending', version: 1, amount_cents: 10000, reason: 'Original company shortfall.', review_reason: null, can_review: true, can_approve: true, wallet_reference: null };
    const value = { ...base, credits: { maximum_cents: 0, wallet_balance_cents: null, requests: [request] } };
    const html = await render(value); assert.match(html, /option value="approved"/);
    Object.assign(h.forms.review.data, { decision: 'approved', confirmed: false, reason: 'Independent review of the original owned company funding request.', amount: '999.00', recipient_id: 900 });
    h.submit('Review original company funding credits'); assert.equal(h.posts.length, 0);
    h.forms.review.data.confirmed = true; h.submit('Review original company funding credits'); h.submit('Review original company funding credits');
    assert.equal(h.posts.length, 1); assert.equal(h.posts[0].data.demo_credit_confirmed, true); assert.equal(h.posts[0].data.version, 1);
    assert.equal('amount' in h.posts[0].data, false); assert.equal('recipient_id' in h.posts[0].data, false);
});

test('operational evidence requires a private file and clears reason, consent and proof when changing original authority', async () => {
    const h = globalThis.lossControlsHarness = new Harness();
    const value = { ...base, actions: [action('record-disposition', { proof: true, confirmation: 'disposition_confirmed' })] };
    await render(value); choose(h, 'record-disposition'); const html = await render(value); ready(h);
    assert.match(html, /does not confirm a parcel, vehicle or sack arrival/);
    h.forms.action.data.proof = { size: 0, type: 'image/png' }; h.submit(); assert.equal(h.posts.length, 0);
    h.forms.action.data.proof = { size: 4000, type: 'image/png' }; h.submit(); assert.equal(h.posts[0].data.disposition_confirmed, true);
    const token = h.forms.action.data.request_token; h.finish(); await render({ ...value, version: 2 }, 12);
    assert.equal(h.forms.action.data.proof, null); assert.equal(h.forms.action.data.reason, ''); assert.equal(h.forms.action.data.confirmed, false);
    assert.equal(h.forms.action.data.expected_version, 2); assert.notEqual(h.forms.action.data.request_token, token);
});

test('cash decimal syntax and missing confirmation block the real submit handler', async () => {
    const h = globalThis.lossControlsHarness = new Harness();
    const value = { ...base, actions: [action('approve-advance', { amount: true, maximum_cents: 10000, confirmation: 'demo_advance_confirmed' })] };
    await render(value); choose(h, 'approve-advance'); await render(value); ready(h);
    for (const amount of ['01.00', '1e2', '-1', '0', '1.234', '1000000000']) { h.forms.action.data.amount = amount; h.submit(); assert.equal(h.posts.length, 0); }
    h.forms.action.data.amount = '100.00'; h.forms.action.data.confirmed = false; h.submit(); assert.equal(h.posts.length, 0);
    h.forms.action.data.confirmed = true; h.submit(); assert.equal(h.posts.length, 1);
});

test('credits reserved for another authority remain in the balance while this original loss can request its own shortfall', async () => {
    const h = globalThis.lossControlsHarness = new Harness();
    const value = { ...base, credits: { maximum_cents: 18833, wallet_balance_cents: 18833, available_for_authority_cents: 0, requests: [] } };
    const html = await render(value);
    assert.match(html, /My virtual Wallet balance/); assert.match(html, /Available for this loss/); assert.match(html, /188\.33/);
    Object.assign(h.forms.credit.data, { amount: '188.33', reason: 'The other reviewed credits remain reserved for their own original obligation.' });
    h.submit('Request original company funding credits'); assert.equal(h.posts.length, 1); assert.equal(h.posts[0].data.amount, '188.33');
    assert.doesNotMatch(html, /Fund from my company Wallet/);
});

test('unused platform reserve return renders distinct labels, enforces consent and resets on actor change', async () => {
    const h = globalThis.lossControlsHarness = new Harness();
    const value = { ...base, version: 15, actions: [action('return-platform-source', { confirmation: 'platform_source_return_confirmed', source: true,
        sources: [{ id: 51, label: 'Original unretired advance', amount_cents: 3833 }, { id: 52, label: 'Original unspent escrow', amount_cents: 25000 }] })] };
    await render(value); choose(h, 'return-platform-source'); let html = await render(value); ready(h);
    assert.match(html, /Original platform reserve deposit/);
    assert.match(html, /I independently verified this found-parcel hold and authorize returning only this unused platform source/);
    assert.doesNotMatch(html, /type="file"|Amount \(PHP\)|Actual receiving admin/);
    h.submit(); assert.equal(h.posts.length, 0); assert.ok(h.forms.action.errors.source_event_id);
    h.control(item => item.type === 'select' && item.props.children.some?.(child => child?.props?.children === 'Select the original escrow or advance deposit'))
        .onChange({ target: { value: '52' } });
    html = await render(value); assert.match(html, /Return [\s\S]*250\.00[\s\S]*to its original escrow or demo issuance account/);
    h.control(item => item.type === 'input' && item.props.type === 'checkbox').onChange({ target: { checked: false } });
    h.submit(); assert.equal(h.posts.length, 0);
    h.control(item => item.type === 'input' && item.props.type === 'checkbox').onChange({ target: { checked: true } });
    Object.assign(h.forms.action.data, { amount: '999.00', recipient_id: '999', proof: { size: 2048, type: 'image/png' } });
    h.submit(); h.submit(); assert.equal(h.posts.length, 1);
    assert.deepEqual(Object.keys(h.posts[0].data).sort(), ['action', 'expected_version', 'platform_source_return_confirmed', 'reason', 'request_token', 'source_event_id']);
    assert.equal(h.posts[0].data.source_event_id, '52');
    assert.equal(h.posts[0].data.platform_source_return_confirmed, true);
    assert.equal(h.posts[0].options.forceFormData, false);
    const tokenBeforeSwitch = h.forms.action.data.request_token;
    await render(value, 11, 7);
    ready(h);
    h.forms.action.data.source_event_id = '52';
    await render(value, 11, 8);
    assert.equal(h.forms.action.data.reason, '');
    assert.equal(h.forms.action.data.confirmed, false);
    assert.equal(h.forms.action.data.source_event_id, '');
    assert.notEqual(h.forms.action.data.request_token, tokenBeforeSwitch);
});

test('found-parcel reconciliation renders status, blocks ineligible paths and posts eligible outcome_path with proof', async () => {
    const h = globalThis.lossControlsHarness = new Harness();
    const value = {
        ...base, version: 19,
        finding: { reference: 'FINDING-REF-1', proof_url: '/shipment-finances/11/finding/proof', intake_url: '/loss-found/11?hub_id=4' },
        reconciliation: {
            eligible_paths: ['unpaid_undelivered'],
            blocked_reasons: { already_settled_salvage: 'Salvage custody applies only after loss compensation or resolution has already been settled.' },
            stale: false,
            reconciliation: null,
        },
        actions: [action('reconcile-found', { confirmation: 'reconciliation_confirmed', proof: true, outcome_path: true })],
    };
    let html = await render(value, 11, 22);
    assert.match(html, /href="\/loss-found\/11\?hub_id=4"/);
    assert.match(html, /Salvage custody applies only after loss compensation/);
    choose(h, 'reconcile-found');
    html = await render(value, 11, 22);
    assert.match(html, /Eligible reconciliation outcome/);
    assert.match(html, /unpaid_undelivered/);
    ready(h);
    h.forms.action.data.proof = { size: 4096, type: 'image/png' };
    h.submit();
    assert.equal(h.posts.length, 1);
    assert.deepEqual(Object.keys(h.posts[0].data).sort(), ['action', 'expected_version', 'outcome_path', 'proof', 'reason', 'reconciliation_confirmed', 'request_token']);
    assert.equal(h.posts[0].data.outcome_path, 'unpaid_undelivered');
    assert.equal(h.posts[0].data.reconciliation_confirmed, true);
    assert.equal(h.posts[0].options.forceFormData, true);
});

test('approved stale reconciliation shows warning, invalidation action, and cod cash return submits without source_event_id', async () => {
    const h = globalThis.lossControlsHarness = new Harness();
    const value = {
        ...base, version: 21, receivers: [{ id: 8, name: 'Receiving Admin' }],
        reconciliation: {
            eligible_paths: [],
            blocked_reasons: {},
            stale: true,
            reconciliation: {
                id: 5, reference: 'REC-STALE-1', outcome_path: 'already_settled_salvage',
                outcome_label: 'Retain settled compensation & move parcel to salvage', status: 'approved',
                stale: true, reason: 'Approved salvage custody.', proof_url: '/shipment-finances/11/reconciliation/5/proof',
                invalidation_reason: null, invalidated_at: null, released_at: null, created_at: '2026-11-14T00:00:00Z',
            },
        },
        actions: [
            action('invalidate-reconciliation', { confirmation: 'invalidation_confirmed' }),
            action('record-company-cash-return', { proof: true, amount: true, cash_reference: true, maximum_cents: 18000 }),
            action('offer-buyer-compensation-return', { proof: true, receiver: true, cash_reference: true }),
        ],
    };
    let html = await render(value, 11, 22);
    assert.match(html, /Original financial state changed after reconciliation approval/);
    assert.match(html, /href="\/shipment-finances\/11\/reconciliation\/5\/proof"/);
    choose(h, 'record-company-cash-return');
    await render(value, 11, 22);
    ready(h);
    Object.assign(h.forms.action.data, { amount: '180.00', cash_reference: 'CASH-RET-01', proof: { size: 2048, type: 'application/pdf' } });
    h.submit();
    assert.equal(h.posts.length, 1);
    assert.equal(h.posts[0].data.action, 'record-company-cash-return');
    assert.equal('source_event_id' in h.posts[0].data, false);
    assert.equal(h.posts[0].data.cash_reference, 'CASH-RET-01');
});

test('company credit request and admin review forms reset sensitive drafts and request tokens when the active actor changes', async () => {
    const requestHarness = globalThis.lossControlsHarness = new Harness();
    const requestValue = { ...base, version: 5, credits: { maximum_cents: 10000, wallet_balance_cents: 0, requests: [] } };
    await render(requestValue, 11, 7);
    Object.assign(requestHarness.forms.credit.data, { amount: '50.00', reason: 'Original company shortfall needing review.' });
    const creditToken = requestHarness.forms.credit.data.request_token;
    await render(requestValue, 11, 8);
    assert.equal(requestHarness.forms.credit.data.amount, '');
    assert.equal(requestHarness.forms.credit.data.reason, '');
    assert.notEqual(requestHarness.forms.credit.data.request_token, creditToken);

    const reviewHarness = globalThis.lossControlsHarness = new Harness();
    const pendingRequest = { id: 9, reference: 'GRANT-ACTOR-RESET', status: 'pending', version: 2, amount_cents: 10000, reason: 'Original shortfall.', review_reason: null, can_review: true, can_approve: true, wallet_reference: null };
    const reviewValue = { ...base, version: 6, credits: { maximum_cents: 0, wallet_balance_cents: null, requests: [pendingRequest] } };
    await render(reviewValue, 11, 7);
    Object.assign(reviewHarness.forms.review.data, { decision: 'approved', confirmed: true, reason: 'Independent admin checked the grant request.' });
    const reviewToken = reviewHarness.forms.review.data.request_token;
    await render(reviewValue, 11, 8);
    assert.equal(reviewHarness.forms.review.data.decision, '');
    assert.equal(reviewHarness.forms.review.data.confirmed, false);
    assert.equal(reviewHarness.forms.review.data.reason, '');
    assert.notEqual(reviewHarness.forms.review.data.request_token, reviewToken);
});
