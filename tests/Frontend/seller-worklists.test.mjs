import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-seller-worklists-'));
after(() => { rmSync(temporary, { recursive: true, force: true }); delete globalThis.sellerWorklistHarness; delete globalThis.route; delete globalThis.window; });
const result = await build({
    stdin: { contents: "export { default as Orders } from '@/Pages/Seller/Orders'; export { default as Reviews } from '@/Pages/Seller/Reviews';", resolveDir: resolve(import.meta.dirname, '../..'), loader: 'tsx' },
    bundle: true, packages: 'external', platform: 'node', format: 'esm', jsx: 'automatic', write: false,
    plugins: [{ name: 'seller-worklist-hooks', setup(builder) {
        builder.onResolve({ filter: /^react$/ }, () => ({ path: 'hooks', namespace: 'seller-hooks' }));
        builder.onLoad({ filter: /.*/, namespace: 'seller-hooks' }, () => ({ loader: 'js', contents: `
            export const useState = value => globalThis.sellerWorklistHarness.state(value);
            export const useRef = value => useState(() => ({ current: value }))[0];
            export const useEffect = () => {}; export const useLayoutEffect = () => {};
            export const useMemo = callback => callback(); export const useCallback = callback => callback; export default {};
        ` }));
        builder.onResolve({ filter: /^@inertiajs\/react$/ }, () => ({ path: 'inertia', namespace: 'seller-inertia' }));
        builder.onLoad({ filter: /.*/, namespace: 'seller-inertia' }, () => ({ loader: 'js', contents: `
            export const Head = () => null; export const Link = () => null;
            export const usePage = () => ({ props: { auth: { user: {} }, flash: {} } });
            export const useForm = data => ({ data, errors: {}, processing: false, clearErrors() {}, setData() {}, post() {} });
            export const router = { get: (...args) => globalThis.sellerWorklistHarness.requests.push(args) };
        ` }));
        builder.onResolve({ filter: /Layouts\/DashboardLayout$/ }, () => ({ path: 'layout', namespace: 'seller-layout' }));
        builder.onLoad({ filter: /.*/, namespace: 'seller-layout' }, () => ({ loader: 'js', contents: 'export default function DashboardLayout() {}' }));
    } }],
});
const file = join(temporary, 'worklists.mjs'); writeFileSync(file, result.outputFiles[0].text);
const { Orders, Reviews } = await import(pathToFileURL(file).href);
globalThis.route = name => '/' + name.replaceAll('.', '/');
globalThis.window = { location: new URL('https://bagoo.test/seller/orders?status=to_pack&page=2') };
function harness(Component, props) {
    const slots = []; let cursor = 0;
    const h = { requests: [], state(initial) { const i = cursor++; if (!(i in slots)) slots[i] = typeof initial === 'function' ? initial() : initial; return [slots[i], value => { slots[i] = typeof value === 'function' ? value(slots[i]) : value; }]; }, render() { cursor = 0; return Component(props); } };
    globalThis.sellerWorklistHarness = h;
    return h;
}
function nodes(tree, predicate) {
    if (!tree || typeof tree !== 'object') return [];
    if (Array.isArray(tree)) return tree.flatMap(child => nodes(child, predicate));
    return [...(predicate(tree) ? [tree] : []), ...nodes(tree.props?.children, predicate)];
}
function text(tree) {
    if (tree == null || typeof tree === 'boolean') return '';
    if (typeof tree !== 'object') return String(tree);
    if (Array.isArray(tree)) return tree.map(text).join('');
    return text(tree.props?.children);
}
const orders = { orders: { data: [], total: 0, current_page: 2, last_page: 2 }, shop: { name: 'Bagoo Studio' }, shopEligible: true, returnReceiptToken: 'isolated', currentStatus: 'to_pack', counts: {}, cancellationReasons: ['Unavailable'], batchLimit: 50 };

test('seller order search and dates submit a server visit with page reset and the current status retained', () => {
    const h = harness(Orders, orders); let tree = h.render();
    nodes(tree, n => n.type === 'input' && n.props.type === 'search')[0].props.onChange({ target: { value: ' BGO-OLD ' } });
    const dates = nodes(tree, n => n.type === 'input' && n.props.type === 'date');
    dates[0].props.onChange({ target: { value: '2026-10-01' } }); dates[1].props.onChange({ target: { value: '2026-10-20' } });
    tree = h.render(); nodes(tree, n => n.type === 'form')[0].props.onSubmit({ preventDefault() {} });
    const [url, data, options] = h.requests[0]; const query = new URL(url, 'https://bagoo.test').searchParams;
    assert.equal(query.get('status'), 'to_pack'); assert.equal(query.get('search'), 'BGO-OLD'); assert.equal(query.get('from'), '2026-10-01'); assert.equal(query.get('to'), '2026-10-20'); assert.equal(query.has('page'), false);
    assert.deepEqual(data, {}); assert.equal(options.preserveState, true); assert.ok(options.only.includes('filters'));
    options.onFinish();
    options.onError({ from: 'Choose a real date.' }); assert.match(text(h.render()), /Choose a real date/);
});

test('seller review rating selection visits the full owned list and keeps purchased options visible', () => {
    const props = { reviews: { total: 1, data: [{ id: 1, rating: 1, comment: 'Useful feedback.', purchased_variant: { color: 'Clay', size: 'Medium', sku_snapshot: 'BGO-CLAY-M' } }], last_page: 1 }, stats: { average_rating: 1, total_reviews: 1, response_rate: '0%', rating_breakdown: {} }, shop: { name: 'Bagoo Studio' } };
    const h = harness(Reviews, props); const tree = h.render();
    assert.match(text(tree), /Clay \/ Medium/); assert.match(text(tree), /BGO-CLAY-M/);
    nodes(tree, n => n.type === 'select')[0].props.onChange({ target: { value: '5' } });
    const [url, data, options] = h.requests[0]; assert.equal(url, '/seller/reviews/index'); assert.deepEqual(data, { rating: '5' }); assert.equal(options.preserveScroll, true);
    options.onFinish(); options.onError({ rating: 'Choose a rating.' }); assert.match(text(h.render()), /Choose a rating/);
});
