import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-seller-worklists-'));
after(() => { rmSync(temporary, { recursive: true, force: true }); delete globalThis.sellerWorklistHarness; delete globalThis.route; delete globalThis.window; });
const result = await build({
    stdin: { contents: "export { default as Orders } from '@/Pages/Seller/Orders'; export { default as Reviews } from '@/Pages/Seller/Reviews'; export { default as Categories } from '@/Pages/Seller/StoreCategories'; export { default as Listings } from '@/Pages/Seller/Products'; export { default as ProductCard } from '@/Components/ProductCard'; export { default as Detail } from '@/Pages/Buyer/ProductDetail'; export { default as ListingDetails, emptyListingDetails } from '@/Components/SellerListingDetails'; export { declaredParcelWeight } from '@/utils/declaredParcelWeight'; export { default as Matrix } from '@/Components/VariantStockMatrix'; export { default as Sales } from '@/Pages/Seller/ProductSales'; export { default as Specifications, ProductFactsSummary, productFacts } from '@/Components/ProductSpecifications'; export { selectedRegularPrice, saleUnitPrice, percentagePrice } from '@/utils/productPricing';", resolveDir: resolve(import.meta.dirname, '../..'), loader: 'tsx' },
    bundle: true, packages: 'external', platform: 'node', format: 'esm', jsx: 'automatic', write: false,
    plugins: [{ name: 'seller-worklist-hooks', setup(builder) {
        builder.onResolve({ filter: /^react-dom$/ }, () => ({ path: 'portal', namespace: 'seller-portal' }));
        builder.onLoad({ filter: /.*/, namespace: 'seller-portal' }, () => ({ loader: 'js', contents: 'export const createPortal = children => children;' }));
        builder.onResolve({ filter: /^react$/ }, () => ({ path: 'hooks', namespace: 'seller-hooks' }));
        builder.onLoad({ filter: /.*/, namespace: 'seller-hooks' }, () => ({ loader: 'js', contents: `
            export const useState = value => globalThis.sellerWorklistHarness.state(value);
            export const useRef = value => useState(() => ({ current: value }))[0];
            export const useEffect = () => {}; export const useLayoutEffect = () => {};
            export const useId = () => 'isolated-seller-id'; export const useMemo = callback => callback(); export const useCallback = callback => callback; export default {};
        ` }));
        builder.onResolve({ filter: /^@inertiajs\/react$/ }, () => ({ path: 'inertia', namespace: 'seller-inertia' }));
        builder.onLoad({ filter: /.*/, namespace: 'seller-inertia' }, () => ({ loader: 'js', contents: `
            export const Head = () => null; export const Link = () => null;
            export const usePage = () => ({ props: { auth: { user: {} }, flash: {} } });
            export const useForm = data => globalThis.sellerWorklistHarness.form(data);
            export const router = Object.fromEntries(['get', 'post', 'put', 'delete'].map(method => [method, (...args) => { globalThis.sellerWorklistHarness.requests.push(args); globalThis.sellerWorklistHarness.methods.push(method); }]));
        ` }));
        builder.onResolve({ filter: /Layouts\/DashboardLayout$/ }, () => ({ path: 'layout', namespace: 'seller-layout' }));
        builder.onLoad({ filter: /.*/, namespace: 'seller-layout' }, () => ({ loader: 'js', contents: 'export default function DashboardLayout() {}' }));
    } }],
});
const file = join(temporary, 'worklists.mjs'); writeFileSync(file, result.outputFiles[0].text);
const { Orders, Reviews, Categories, Listings, ProductCard, Detail, ListingDetails, emptyListingDetails, declaredParcelWeight, Matrix, Sales, Specifications, ProductFactsSummary, productFacts, selectedRegularPrice, saleUnitPrice, percentagePrice } = await import(pathToFileURL(file).href);
globalThis.route = name => '/' + name.replaceAll('.', '/');
globalThis.window = { location: new URL('https://bagoo.test/seller/orders?status=to_pack&page=2') };
function harness(Component, props) {
    const slots = [], forms = []; let cursor = 0, formCursor = 0;
    const h = { requests: [], methods: [],
        state(initial) { const i = cursor++; if (!(i in slots)) slots[i] = typeof initial === 'function' ? initial() : initial; return [slots[i], value => { slots[i] = typeof value === 'function' ? value(slots[i]) : value; }]; },
        form(initial) {
            const i = formCursor++; forms[i] ??= { data: structuredClone(initial), errors: {}, processing: false };
            const value = forms[i];
            return { ...value, setData(key, next) { value.data = typeof key === 'function' ? key(value.data) : typeof key === 'object' ? key : { ...value.data, [key]: next }; },
                clearErrors() { value.errors = {}; }, reset() { value.data = structuredClone(initial); }, setError(key, next) { value.errors[key] = next; },
                transform(callback) { value.transform = callback; },
                ...Object.fromEntries(['post', 'put'].map(method => [method, (url, options) => { h.methods.push(method); h.requests.push([url, value.transform ? value.transform(value.data) : structuredClone(value.data), options]); }])) };
        },
        render() { cursor = 0; formCursor = 0; return Component(props); }, forms,
    };
    globalThis.sellerWorklistHarness = h;
    return h;
}
function nodes(tree, predicate) {
    if (!tree || typeof tree !== 'object') return [];
    if (Array.isArray(tree)) return tree.flatMap(child => nodes(child, predicate));
    return [...(predicate(tree) ? [tree] : []), ...nodes([tree.props?.children, tree.props?.actions, tree.props?.topBanner], predicate)];
}
function text(tree) {
    if (tree == null || typeof tree === 'boolean') return '';
    if (typeof tree !== 'object') return String(tree);
    if (Array.isArray(tree)) return tree.map(text).join('');
    return text(tree.props?.children);
}

test('independent option fields retain blank input and highlight only the invalid row', () => {
    const props = { colors: [{ name: 'Natural' }], sizes: [{ name: 'Compact', extra_price: 0 }, { name: 'Large', extra_price: 0 }],
        pricingMode: 'independent', basePrice: '500.00', value: [
            { color: 'Natural', size: 'Compact', stock: 2, price: '199.95', sku: 'BGO-COMPACT' },
            { color: 'Natural', size: 'Large', stock: 3, price: '450.00', sku: 'BGO-LARGE' },
        ], onChange: rows => { props.value = rows; } };
    const h = harness(Matrix, props); let tree = h.render();
    const largePrice = nodes(tree, node => node.props?.['aria-label'] === 'Selling price for Natural / Large')[0];
    largePrice.props.onChange({ target: { value: '' } }); tree = h.render();
    assert.equal(props.value[1].price, ''); assert.equal(props.value[0].price, '199.95');
    assert.equal(nodes(tree, node => node.props?.['aria-label'] === 'Selling price for Natural / Large')[0].props['aria-invalid'], true);
    assert.equal(nodes(tree, node => node.props?.['aria-label'] === 'Selling price for Natural / Compact')[0].props['aria-invalid'], false);
    assert.match(text(tree), /Enter ₱1.00/);
    nodes(tree, node => node.props?.['aria-label'] === 'SKU for Natural / Large')[0].props.onChange({ target: { value: 'bgo-compact' } }); tree = h.render();
    assert.equal(props.value[1].sku, 'BGO-COMPACT'); assert.match(text(tree), /different SKU/);
    assert.match(text(tree), /Two option groups maximum/);
});

test('server row errors stay beside the matching option field', () => {
    const props = { colors: [], sizes: [{ name: 'Compact', extra_price: 0 }, { name: 'Large', extra_price: 0 }],
        pricingMode: 'independent', value: [{ color: null, size: 'Compact', stock: 2, price: '100.00', sku: 'BGO-C' }, { color: null, size: 'Large', stock: 1, price: '200.00', sku: 'BGO-L' }],
        errors: { 'combinations.1.sku': 'This SKU is already used by another listing or option.' }, onChange() {} };
    const tree = harness(Matrix, props).render();
    assert.equal(nodes(tree, node => node.props?.['aria-label'] === 'SKU for Compact')[0].props['aria-invalid'], false);
    assert.equal(nodes(tree, node => node.props?.['aria-label'] === 'SKU for Large')[0].props['aria-invalid'], true);
    assert.equal(nodes(tree, node => node.props?.role === 'alert').length, 1);
});

test('buyer specification and summary components render saved condition, dimensions and public groups', () => {
    const product = { condition: 'used', brand: 'Bagoo Studio', weight_kg: '0.80', length_cm: '30.00', width_cm: '20.00', height_cm: '5.00',
        stock: 3, category: { name: 'Travel essentials' }, store_categories: [{ name: 'Weekend bags' }], shop: { city: 'Taguig City' } };
    const summary = text(ProductFactsSummary({ product }));
    assert.match(summary, /Condition: Used/); assert.match(summary, /Weight: 0.8 kg/); assert.match(summary, /30 × 20 × 5 cm/);
    const specs = text(Specifications({ product }));
    for (const value of ['Used', 'Bagoo Studio', 'Travel essentials', 'Weekend bags', '0.8 kg', '30 × 20 × 5 cm']) assert.ok(specs.includes(value));
    assert.deepEqual(productFacts({}), { condition: null, weight: null, dimensions: null });
    assert.match(text(ProductFactsSummary({ product: { ...product, condition: 'new' } })), /Condition: New/);
    assert.match(text(Specifications({ product: { stock: 0 } })), /Not provided/);
    assert.equal(ProductFactsSummary({ product: {} }), null);
});

test('selected option pricing discounts the full price with the same centavo rounding', () => {
    const product = { price: '500.00', variants: { pricing_mode: 'independent', combinations: [{ color: null, size: 'Compact', price: '199.95' }] },
        sale_offer: { active: { discount_type: 'percentage', percentage: 15 } } };
    assert.equal(selectedRegularPrice(product, null, 'Compact'), 199.95);
    assert.equal(saleUnitPrice(product, selectedRegularPrice(product, null, 'Compact')), 169.96);
    assert.equal(percentagePrice(1, 99), 0.01);
    const legacy = { price: '100.00', variants: { sizes: [{ name: 'Large', extra_price: '99.95' }] }, sale_offer: product.sale_offer };
    assert.equal(saleUnitPrice(legacy, selectedRegularPrice(legacy, null, 'Large')), 169.96);
});

test('seller percentage authoring previews the whole range and submits retained percentage terms', () => {
    const h = harness(Sales, { shop: { name: 'Bagoo Studio' }, product: { id: 1, name: 'Canvas bag', price: '500.00', stock: 10, status: 'active', price_range: { regular_min: '199.95', regular_max: '450.00' } }, sales: { data: [] } });
    let tree = h.render(); const form = h.forms[0]; form.data.percentage = '15';
    tree = h.render(); assert.match(text(tree), /169.96.*382.50/);
    nodes(tree, node => node.type === 'form')[0].props.onSubmit({ preventDefault() {} });
    assert.equal(h.requests[0][1].discount_type, 'percentage'); assert.equal(h.requests[0][1].percentage, '15');
    form.data.percentage = '10.5'; tree = h.render(); assert.match(text(tree), /whole discount percentage from 1 to 99/);
    assert.equal(nodes(tree, node => node.props?.role === 'status').length, 0);
});

test('buyer selection shows its full discounted price and original row SKU', () => {
    const variations = { pricing_mode: 'independent', colors: [], sizes: [{ id: 'c', name: 'Compact', stock: 2 }, { id: 'l', name: 'Large', stock: 2 }],
        combinations: [{ color: null, size: 'Compact', stock: 2, price: '199.95', sku: 'BGO-COMPACT' }, { color: null, size: 'Large', stock: 2, price: '450.00', sku: 'BGO-LARGE' }] };
    const product = { id: 1, name: 'Canvas bag', slug: 'canvas-bag', price: '500.00', sku: 'BGO-BASE', stock: 4, variants: variations,
        price_range: { min: '169.96', max: '382.50' }, sale_offer: { effective_price: 169.96, active: { discount_type: 'percentage', percentage: 15 } } };
    const h = harness(Detail, { product, variations, relatedProducts: [], shopStats: { products_count: 1, review_count: 0 } });
    let tree = h.render(); nodes(tree, node => node.type === 'button' && text(node).startsWith('Large'))[0].props.onClick(); tree = h.render();
    assert.match(text(tree), /382.50/); assert.match(text(tree), /SKU: BGO-LARGE/);
});

test('both linked option images update the buyer main image through the actual selection handlers', () => {
    const colors = [{ id: 'n', name: 'Natural', hex: '#111111', in_stock: true, image_url: '/storage/products/natural.jpg' }, { id: 'b', name: 'Black', hex: '#111111', in_stock: true, image_url: '/storage/products/black.jpg' }];
    const product = { id: 1, name: 'Canvas bag', slug: 'canvas-bag', price: '100.00', stock: 4 };
    const h = harness(Detail, { product, variations: { colors, sizes: [] }, relatedProducts: [], shopStats: { products_count: 1, review_count: 0 } });
    for (const color of colors) {
        let tree = h.render(); nodes(tree, node => node.type === 'button' && text(node) === color.name)[0].props.onClick(); tree = h.render();
        assert.equal(nodes(tree, node => node.type === 'img' && node.props.alt === product.name)[0].props.src, color.image_url);
    }
});

test('equal independent prices show the option price before selection and use its actual regular price for sale labels', () => {
    const variations = { pricing_mode: 'independent', colors: [], sizes: [{ id: 'c', name: 'Compact', stock: 2 }],
        combinations: [{ color: null, size: 'Compact', stock: 2, price: '100.00', sku: 'BGO-C' }] };
    const product = { id: 1, name: 'Canvas bag', slug: 'canvas-bag', price: '500.00', stock: 2, variants: variations,
        price_range: { min: '85.00', max: '85.00', regular_min: '100.00', regular_max: '100.00' }, sale_offer: { effective_price: 85, active: { discount_type: 'percentage', percentage: 15, price: '85.00' } } };
    const tree = harness(Detail, { product, variations, relatedProducts: [], shopStats: { products_count: 1, review_count: 0 } }).render();
    assert.match(text(tree), /85.00/); assert.doesNotMatch(text(tree), /500.00/);
    assert.match(text(ProductCard({ product })), /15%/); assert.doesNotMatch(text(ProductCard({ product })), /83%/);
    assert.equal(saleUnitPrice({ price: 100, price_range: { regular_min: 150 }, sale_offer: { active: { price: 130 } } }, 200), 180);
});
