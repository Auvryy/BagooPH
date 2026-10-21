import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-product-vouchers-'));
process.once('exit', () => rmSync(temporary, { recursive: true, force: true }));
after(() => { rmSync(temporary, { recursive: true, force: true }); delete globalThis.productVoucherHarness; delete globalThis.route; });
const bundled = await build({
    stdin: { contents: `export {default as Selection} from '@/Components/Vouchers/VoucherProductSelection'; export {default as Form} from '@/Components/Vouchers/CampaignForm'; export {default as Scope} from '@/Components/Vouchers/BuyerVoucherScope'; export {VoucherChoiceCards as Choices} from '@/Components/Vouchers/BuyerVoucherPicker';`, resolveDir: resolve(import.meta.dirname, '../..'), loader: 'tsx' },
    bundle: true, packages: 'external', platform: 'node', format: 'esm', jsx: 'automatic', write: false,
    plugins: [{ name: 'product-voucher-interactions', setup(builder) {
        builder.onResolve({ filter: /^react$/ }, () => ({ path: 'hooks', namespace: 'voucher-hooks' }));
        builder.onLoad({ filter: /.*/, namespace: 'voucher-hooks' }, () => ({ contents: `export const useState = value => globalThis.productVoucherHarness.state(value); export const useEffect = (fn,deps) => globalThis.productVoucherHarness.effect(fn,deps); export const useRef = value => useState(() => ({current:value}))[0];` }));
        builder.onResolve({ filter: /^axios$/ }, () => ({ path: 'http', namespace: 'voucher-http' }));
        builder.onLoad({ filter: /.*/, namespace: 'voucher-http' }, () => ({ contents: `export default {get: (...args) => globalThis.productVoucherHarness.request(args), isAxiosError: error => !!error?.response};` }));
        builder.onResolve({ filter: /^@inertiajs\/react$/ }, () => ({ path: 'inertia', namespace: 'voucher-inertia' }));
        builder.onLoad({ filter: /.*/, namespace: 'voucher-inertia' }, () => ({ contents: `export const Link = 'a'; export const usePage = () => ({props:{auth:{user:{role:'buyer'}}}}); export const router = {}; export const useForm = data => globalThis.productVoucherHarness.form(data);` }));
        builder.onResolve({ filter: /^@headlessui\/react$/ }, () => ({ path: 'dialog', namespace: 'voucher-dialog' }));
        builder.onLoad({ filter: /.*/, namespace: 'voucher-dialog' }, () => ({ contents: `export const Dialog='dialog', DialogPanel='section', DialogTitle='h2';` }));
        builder.onResolve({ filter: /Components\/Modal$/ }, () => ({ path: 'modal', namespace: 'voucher-modal' }));
        builder.onLoad({ filter: /.*/, namespace: 'voucher-modal' }, () => ({ contents: `export default 'section';` }));
    } }],
});
const file = join(temporary, 'controls.mjs'); writeFileSync(file, bundled.outputFiles[0].text);
const ui = await import(pathToFileURL(file).href);
globalThis.route = name => '/' + name.replaceAll('.', '/');

function nodes(tree, predicate) {
    if (Array.isArray(tree)) return tree.flatMap(child => nodes(child, predicate));
    if (!tree || typeof tree !== 'object') return [];
    return [...(predicate(tree) ? [tree] : []), ...nodes(tree.props?.children, predicate)];
}
function text(tree) {
    if (Array.isArray(tree)) return tree.map(text).join(' ');
    if (tree === null || tree === undefined || typeof tree === 'boolean') return '';
    return typeof tree === 'object' ? text(tree.props?.children) : String(tree);
}
const button = (tree, label) => nodes(tree, node => node.type === 'button' && text(node).trim() === label)[0];
const settle = () => new Promise(resolve => setImmediate(resolve));
function harness(Component, props) {
    let cursor = 0, timerId = 0, form;
    const states = [], effects = [], timers = new Map(), requests = [], submitted = [];
    const previousWindow = globalThis.window;
    globalThis.window = { setTimeout(fn) { const id = ++timerId; timers.set(id, fn); return id; }, clearTimeout(id) { timers.delete(id); } };
    const h = {
        errors: {},
        state(initial) {
            const index = cursor++;
            if (!(index in states)) states[index] = typeof initial === 'function' ? initial() : initial;
            return [states[index], value => { states[index] = typeof value === 'function' ? value(states[index]) : value; }];
        },
        effect(fn, deps) {
            const index = cursor++, old = effects[index];
            if (!old || JSON.stringify(old.deps) !== JSON.stringify(deps)) { old?.cleanup?.(); effects[index] = { deps, fn, run: true }; }
        },
        request(args) {
            let resolve, reject;
            const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
            requests.push({ args, resolve, reject }); return promise;
        },
        form(initial) {
            form ??= { data: initial, processing: false,
                setData(key, value) { form.data = typeof key === 'function' ? key(form.data) : { ...form.data, [key]: value }; },
                clearErrors(...keys) { for (const key of keys) delete h.errors[key]; },
                post(url) { submitted.push({ method: 'post', url, data: structuredClone(form.data) }); },
                patch(url) { submitted.push({ method: 'patch', url, data: structuredClone(form.data) }); },
            };
            form.errors = h.errors; return form;
        },
        render() {
            globalThis.productVoucherHarness = h; cursor = 0;
            const tree = Component(props);
            for (const effect of effects) if (effect?.run) { effect.run = false; effect.cleanup = effect.fn(); }
            return tree;
        },
        flush() { for (const [id, fn] of timers) { timers.delete(id); fn(); } },
        requests, submitted,
        close() { for (const effect of effects) effect?.cleanup?.(); globalThis.window = previousWindow; delete globalThis.productVoucherHarness; },
    };
    globalThis.productVoucherHarness = h;
    return h;
}
const product = (id, name = `Studio Product ${id}`) => ({ id, name, sku: `BGO-${id}`, featured_image: null });
const page = (data, current = 1, last = 1) => ({ data: { products: { data, current_page: current, last_page: last, total: data.length } } });
const props = () => {
    const p = { scope: 'all', ids: [], initialProducts: [], onScope(scope) { p.scope = scope; if (scope === 'all') p.ids = []; }, onChange(ids) { p.ids = ids; } };
    return p;
};

test('whole-shop defaults do not fetch products; selected mode gives immediate required feedback', () => {
    const p = props(), h = harness(ui.Selection, p);
    try {
        let tree = h.render(); h.flush(); assert.equal(h.requests.length, 0);
        nodes(tree, node => node.props?.value === 'selected')[0].props.onChange();
        tree = h.render(); assert.match(text(tree), /Choose at least one product/); h.flush();
        assert.equal(h.requests[0].args[0], '/seller/vouchers/products');
        assert.equal(h.requests[0].args[1].params.page, 1);
    } finally { h.close(); }
});

test('product choices persist across paging, search and removal without exposing another selection', async () => {
    const p = props(); p.scope = 'selected'; const h = harness(ui.Selection, p);
    try {
        h.render(); h.flush(); h.requests[0].resolve(page([product(1)], 1, 2)); await settle();
        let tree = h.render(); nodes(tree, node => node.props?.type === 'checkbox')[0].props.onChange();
        tree = h.render(); assert.deepEqual(p.ids, [1]); assert.match(text(tree), /Studio Product 1/);
        button(tree, 'Next').props.onClick(); h.render(); h.flush(); h.requests[1].resolve(page([product(2)], 2, 2)); await settle();
        tree = h.render(); assert.match(text(tree), /Studio Product 1/);
        nodes(tree, node => node.props?.type === 'checkbox')[0].props.onChange(); tree = h.render(); assert.deepEqual(p.ids, [1, 2]);
        nodes(tree, node => node.props?.['aria-label'] === 'Remove Studio Product 1')[0].props.onClick();
        assert.deepEqual(p.ids, [2]);
        button(h.render(), 'Clear selection').props.onClick(); assert.deepEqual(p.ids, []);
    } finally { h.close(); }
});

test('late search responses and closed selections cannot replace the current product results', async () => {
    const p = props(); p.scope = 'selected'; const h = harness(ui.Selection, p);
    try {
        h.render(); h.flush();
        let tree = h.render(); const search = nodes(tree, node => node.props?.type === 'search')[0];
        assert.equal(search.props.maxLength, 100);
        let prevented = false; search.props.onKeyDown({ key: 'Enter', preventDefault() { prevented = true; } }); assert.equal(prevented, true);
        prevented = false; search.props.onKeyDown({ key: 'ArrowDown', preventDefault() { prevented = true; } }); assert.equal(prevented, false);
        search.props.onChange({ target: { value: 'Current' } });
        h.render(); h.flush(); assert.equal(h.requests[0].args[1].signal.aborted, true);
        assert.deepEqual(h.requests[1].args[1].params, { search: 'Current', page: 1 });
        h.requests[1].resolve(page([product(2, 'Current product')])); await settle();
        h.requests[0].resolve(page([product(1, 'Stale product')])); await settle();
        tree = h.render(); assert.match(text(tree), /Current product/); assert.doesNotMatch(text(tree), /Stale product/);
        nodes(tree, node => node.props?.value === 'all')[0].props.onChange(); h.render();
        assert.equal(h.requests[1].args[1].signal.aborted, true);
    } finally { h.close(); }
});

test('failed and malformed product responses retain selected values and provide a working retry', async () => {
    const p = props(); p.scope = 'selected'; p.ids = [1]; p.initialProducts = [product(1)]; const h = harness(ui.Selection, p);
    try {
        h.render(); h.flush(); h.requests[0].reject({ response: { status: 419 } }); await settle();
        let tree = h.render(); assert.match(text(tree), /Reload to renew your session/); assert.deepEqual(p.ids, [1]);
        button(tree, 'Try again').props.onClick(); h.render(); h.flush(); h.requests[1].resolve({ data: {} }); await settle();
        tree = h.render(); assert.match(text(tree), /Could not load your products/); assert.match(text(tree), /Studio Product 1/);
        button(tree, 'Try again').props.onClick(); h.render(); h.flush(); h.requests[2].resolve(page([product(1)])); await settle();
        assert.doesNotMatch(text(h.render()), /Could not load/); assert.deepEqual(p.ids, [1]);
    } finally { h.close(); }
});

test('one hundred selected products disable additions while still allowing removal', async () => {
    const p = props(); p.scope = 'selected'; p.initialProducts = Array.from({ length: 100 }, (_, i) => product(i + 1)); p.ids = p.initialProducts.map(row => row.id);
    const h = harness(ui.Selection, p);
    try {
        h.render(); h.flush(); h.requests[0].resolve(page([product(1), product(101)])); await settle();
        let tree = h.render(); const choices = nodes(tree, node => node.props?.type === 'checkbox');
        assert.equal(choices[0].props.disabled, false); assert.equal(choices[1].props.disabled, true);
        choices[0].props.onChange(); tree = h.render(); assert.equal(p.ids.length, 99);
        assert.equal(nodes(tree, node => node.props?.type === 'checkbox')[1].props.disabled, false);
    } finally { h.close(); }
});

test('edited selections explain removed and ineligible products and keep server errors beside the field', () => {
    const p = { ...props(), scope: 'selected', ids: [1, 2], initialProducts: [{ ...product(1), eligible: false }], errors: ['Choose a published product from your shop.'] };
    const h = harness(ui.Selection, p);
    try {
        const tree = h.render(); assert.match(text(tree), /Product no longer available/); assert.match(text(tree), /Remove this product/);
        assert.match(text(tree), /Choose a published product from your shop/);
        assert.equal(nodes(tree, node => node.type === 'button' && node.props?.['aria-label']?.startsWith('Remove ')).length, 2);
    } finally { h.close(); }
});

test('seller form submits the selected scope and exact IDs and requires an explicit all-products choice to clear it', () => {
    const terms = { code: 'STUDIOSAVE', name: 'Studio savings', discount_type: 'fixed', value_cents: 1000, minimum_cents: 10000, maximum_cents: 1000,
        budget_cents: 50000, global_limit: 50, per_buyer_limit: 1, starts_at: '2026-10-21T02:00:00Z', ends_at: '2026-10-22T02:00:00Z', product_scope: 'selected', product_ids: [1] };
    const p = { campaign: { id: 4, code: terms.code, version: 2, current_revision_id: 1, published_terms: terms, selected_products: [product(1)] }, platform: false, onClose() {} };
    const h = harness(ui.Form, p);
    try {
        let tree = h.render(); nodes(tree, node => node.type === 'form')[0].props.onSubmit({ preventDefault() {} });
        assert.equal(h.submitted[0].method, 'patch'); assert.equal(h.submitted[0].data.product_scope, 'selected'); assert.deepEqual(h.submitted[0].data.product_ids, [1]);
        nodes(tree, node => node.type === ui.Selection)[0].props.onChange([]); tree = h.render(); assert.equal(button(tree, 'Save draft').props.disabled, true);
        nodes(tree, node => node.type === ui.Selection)[0].props.onScope('all'); tree = h.render(); assert.equal(button(tree, 'Save draft').props.disabled, false);
        nodes(tree, node => node.type === 'form')[0].props.onSubmit({ preventDefault() {} });
        assert.equal(h.submitted[1].data.product_scope, 'all'); assert.deepEqual(h.submitted[1].data.product_ids, []);
    } finally { h.close(); }
});

test('platform forms keep product targeting unavailable and scoped buyer offers show actual product links', () => {
    const h = harness(ui.Form, { campaign: null, platform: true, onClose() {} });
    try { assert.equal(nodes(h.render(), node => node.type === ui.Selection).length, 0); } finally { h.close(); }
    assert.equal(ui.Scope({ offer: { product_scope: 'all' } }), null);
    const tree = ui.Scope({ offer: { product_scope: 'selected', eligible_products: [{ id: 1, name: 'Studio Bag', url: '/product/studio-bag' }] } });
    assert.match(text(tree), /Only qualifying products count/); assert.match(text(tree), /Studio Bag/);
    assert.equal(nodes(tree, node => node.type === 'a')[0].props.href, '/product/studio-bag');
    assert.match(text(ui.Scope({ offer: { product_scope: 'selected', eligible_products: [] } })), /currently unavailable/);
});

test('ineligible checkout choices remain disabled while showing scope and the useful minimum-spend error', () => {
    const offer = { id: 1, revision_id: 2, issuer: 'shop', name: 'Studio voucher', code: 'STUDIOSAVE', discount_type: 'fixed', discount_value: 10,
        min_spend: 100, max_discount: 10, product_scope: 'selected', eligible: false, unavailable_reason: 'Add ₱20.00 in qualifying products from this shop to use this voucher.' };
    const tree = ui.Choices({ offers: [offer], chosen: null, onChoose() {} });
    assert.equal(nodes(tree, node => node.props?.type === 'radio')[1].props.disabled, true);
    assert.match(text(tree), /qualifying products/); assert.equal(nodes(tree, node => node.type === ui.Scope)[0].props.offer, offer);
});
