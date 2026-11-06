import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-seller-worklists-'));
after(() => { rmSync(temporary, { recursive: true, force: true }); delete globalThis.sellerWorklistHarness; delete globalThis.route; delete globalThis.window; });
const result = await build({
    stdin: { contents: "export { default as Orders } from '@/Pages/Seller/Orders'; export { default as Reviews } from '@/Pages/Seller/Reviews'; export { default as Tags } from '@/Pages/Seller/StoreTags'; export { default as Listings } from '@/Pages/Seller/Products'; export { default as ProductCard } from '@/Components/ProductCard'; export { default as Detail } from '@/Pages/Buyer/ProductDetail'; export { default as ListingDetails, emptyListingDetails } from '@/Components/SellerListingDetails'; export { declaredParcelWeight } from '@/utils/declaredParcelWeight';", resolveDir: resolve(import.meta.dirname, '../..'), loader: 'tsx' },
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
const { Orders, Reviews, Tags, Listings, ProductCard, Detail, ListingDetails, emptyListingDetails, declaredParcelWeight } = await import(pathToFileURL(file).href);
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
                clearErrors() { value.errors = {}; }, reset() { value.data = structuredClone(initial); }, setError(key, next) { value.errors[key] = next; }, setDefaults() {},
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


test('flat tag manager has no category tree and enforces the one-to-fifty character name limit', () => {
    const h = harness(Tags, { shop: { name: 'Bagoo Studio' }, tags: [{ id: 1, shop_id: 5, name: 'Travel tote', products_count: 2 }] });
    let tree = h.render(); assert.match(text(tree), /Your tags/); assert.match(text(tree), /Travel tote/); assert.match(text(tree), /2 linked products/);
    assert.doesNotMatch(text(tree), /Parent category|Subcategories|Move Travel/);
    const contextInput = nodes(tree, n => n.type === 'input' && n.props.placeholder === 'Which product will use this tag?')[0];
    assert.equal(contextInput.props.required, true);
    assert.equal(contextInput.props.minLength, 5);
    assert.equal(contextInput.props.maxLength, 120);
    nodes(tree, n => n.type === 'input' && n.props.maxLength === 50)[0].props.onChange({ target: { value: 'x'.repeat(51) } });
    tree = h.render(); assert.match(text(tree), /Use 1–50 characters/);
    assert.equal(nodes(tree, n => n.type === 'button' && text(n) === 'Add tag')[0].props.disabled, true);
});

test('listing details show measurement feedback and product tags under the fixed approved department', () => {
    const tree = ListingDetails({ data: { ...emptyListingDetails, weight_kg: '0', tag_names: ['Weekend bags'] }, errors: {}, tags: [{ id: 1, shop_id: 5, name: 'Weekend bags' }], departmentName: 'Pet Supplies', productName: 'Carryall', onChange() {} });
    assert.match(text(tree), /Enter 0.01–50,000/); assert.match(text(tree), /Weekend bags/); assert.match(text(tree), /approved Pet Supplies department/);
    assert.doesNotMatch(text(tree), /Parent category|Store categories|Subcategories/);
});

test('name-only draft submits with the approved root and an empty flat tag list', () => {
    globalThis.document = { body: {} };
    try {
        const h = harness(Listings, { products: { data: [], current_page: 1, total: 0, last_page: 1 }, categories: [], shop: { id: 5, name: 'Bagoo Studio', root_category_id: 12, root_category: { id: 12, name: 'Pet Supplies' } } });
        let tree = h.render(); nodes(tree, n => n.type === 'button' && text(n).includes('List New Product'))[0].props.onClick();
        tree = h.render();
        const price = nodes(tree, n => n.type === 'input' && n.props.inputMode === 'decimal' && n.props.maxLength === 10)[0];
        const stock = nodes(tree, n => n.type === 'input' && n.props.inputMode === 'numeric')[0];
        assert.equal(price.props.value, ''); assert.equal(stock.props.value, '');
        h.forms[0].data.name = 'Private Canvas Draft';
        tree = h.render(); const draft = nodes(tree, n => n.type === 'button' && n.props.value === 'draft')[0]; assert.equal(draft.props.formNoValidate, true);
        nodes(tree, n => n.type === 'form' && n.props.onSubmit?.name === 'handleCreate')[0].props.onSubmit({ preventDefault() {}, nativeEvent: { submitter: { value: 'draft' } } });
        const payload = h.requests[0][1]; assert.equal(payload.status, 'draft'); assert.equal(payload.name, 'Private Canvas Draft'); assert.equal(payload.tag_names, '[]'); assert.equal(payload.category_id, '12');
        assert.match(payload.request_token, /^[a-f0-9-]{36}$/); assert.equal(payload.request_token, h.forms[0].data.request_token);
    } finally { delete globalThis.document; }
});

test('variant extra-price inputs start blank in both create and edit drawers', () => {
    globalThis.document = { body: {} };
    try {
        const create = harness(Listings, { products: { data: [], current_page: 1, total: 0, last_page: 1 }, categories: [], shop: { id: 5, name: 'Bagoo Studio', root_category_id: 12 } });
        let tree = create.render(); nodes(tree, node => node.type === 'button' && text(node).includes('List New Product'))[0].props.onClick();
        tree = create.render(); nodes(tree, node => node.type === 'button' && text(node).includes('Add Variation'))[0].props.onClick();
        tree = create.render(); nodes(tree, node => node.type === 'button' && text(node).includes('Add option group'))[0].props.onClick();
        tree = create.render();
        assert.equal(nodes(tree, node => node.type === 'input' && node.props.placeholder === 'Extra ₱')[0].props.value, '');

        const editProduct = { id: 9, name: 'Canvas carrier', status: 'active', price: '500.00', stock: 5, category_id: 12,
            weight_kg: '0.80', description: 'A durable canvas carrier for everyday journeys.',
            variants: { option1_name: null, option2_name: 'Size', colors: [], sizes: [{ id: 'medium', name: 'Medium', extra_price: 0, stock: 5 }] } };
        const edit = harness(Listings, { products: { data: [editProduct], current_page: 1, total: 1, last_page: 1 }, categories: [], shop: { id: 5, name: 'Bagoo Studio', root_category_id: 12 } });
        nodes(edit.render(), node => node.type === 'button' && node.props.title === 'Edit Product')[0].props.onClick();
        tree = edit.render();
        assert.equal(nodes(tree, node => node.type === 'input' && node.props.placeholder === 'Extra ₱')[0].props.value, '');
    } finally { delete globalThis.document; }
});

test('seller listing drafts persist on device when a drawer closes and recover when reopened', async () => {
    const values = new Map();
    globalThis.document = { body: {} };
    globalThis.window = {
        location: new URL('https://bagoo.test/seller/products'),
        localStorage: { setItem: (key, value) => values.set(key, value), getItem: key => values.get(key) ?? null, removeItem: key => values.delete(key) },
        addEventListener() {}, removeEventListener() {},
    };
    try {
        const h = harness(Listings, { products: { data: [], current_page: 1, total: 0, last_page: 1 }, categories: [], shop: { id: 5, name: 'Bagoo Studio', root_category_id: 12 } });
        let tree = h.render(); nodes(tree, node => node.type === 'button' && text(node).includes('List New Product'))[0].props.onClick();
        tree = h.render(); h.forms[0].data.name = 'Recovery Canvas Tote'; h.forms[0].isDirty = true; tree = h.render();
        nodes(tree, node => node.type === 'button' && node.props.title === 'Close sidebar')[0].props.onClick();
        const key = 'bagoo:seller-product-draft:v1:5:create';
        assert.equal(JSON.parse(values.get(key)).draft.form.name, 'Recovery Canvas Tote');

        tree = h.render(); await nodes(tree, node => node.type === 'button' && text(node).includes('List New Product'))[0].props.onClick();
        tree = h.render();
        assert.equal(h.forms[0].data.name, 'Recovery Canvas Tote');
        assert.match(text(tree), /Recovered an unsaved draft from this device/);
    } finally { delete globalThis.document; delete globalThis.window; }
});

test('seller edits flush to device storage on close and restore when the edit drawer reopens', async () => {
    const values = new Map();
    globalThis.document = { body: {} };
    globalThis.window = {
        location: new URL('https://bagoo.test/seller/products'),
        localStorage: { setItem: (key, value) => values.set(key, value), getItem: key => values.get(key) ?? null, removeItem: key => values.delete(key) },
        addEventListener() {}, removeEventListener() {},
    };
    try {
        const product = { id: 9, name: 'Canvas carrier', slug: 'canvas-carrier', status: 'active', price: '500.00', stock: 5,
            category_id: 12, weight_kg: '0.80', description: 'A durable canvas carrier for everyday journeys.', variants: { colors: [], sizes: [] } };
        const h = harness(Listings, { products: { data: [product], current_page: 1, total: 1, last_page: 1 }, categories: [], shop: { id: 5, name: 'Bagoo Studio', root_category_id: 12 } });
        let tree = h.render(); nodes(tree, node => node.type === 'button' && node.props.title === 'Edit Product')[0].props.onClick();
        tree = h.render(); h.forms[1].data.name = 'Recovered Edited Carrier'; h.forms[1].isDirty = true; tree = h.render();
        nodes(tree, node => node.type === 'button' && node.props.title === 'Close sidebar')[0].props.onClick();
        const key = 'bagoo:seller-product-draft:v1:5:product:9';
        assert.equal(JSON.parse(values.get(key)).draft.form.name, 'Recovered Edited Carrier');

        tree = h.render(); await nodes(tree, node => node.type === 'button' && node.props.title === 'Edit Product')[0].props.onClick();
        tree = h.render();
        assert.equal(h.forms[1].data.name, 'Recovered Edited Carrier');
        assert.match(text(tree), /Recovered unsaved edits from this device/);
    } finally { delete globalThis.document; delete globalThis.window; }
});

test('a resumed draft offers Publish first and keeps Update Listing as the draft-only alternative', () => {
    globalThis.document = { body: {} };
    try {
        const draft = { id: 21, name: 'Draft canvas carrier', slug: 'draft-canvas-carrier', status: 'draft', price: '500.00', stock: 5,
            category_id: 12, weight_kg: '0.80', description: 'A durable canvas carrier for everyday journeys.', images: [{ id: 1, image_url: '/storage/draft-carrier.jpg' }], variants: { colors: [], sizes: [] } };
        const h = harness(Listings, { products: { data: [draft], current_page: 1, total: 1, last_page: 1 }, categories: [], shop: { id: 5, name: 'Bagoo Studio', root_category_id: 12 } });
        nodes(h.render(), node => node.type === 'button' && node.props.title === 'Edit Product')[0].props.onClick();
        const tree = h.render();
        const actions = nodes(tree, node => node.type === 'button' && ['publish', 'update'].includes(node.props.value));
        assert.deepEqual(actions.map(button => button.props.value), ['publish', 'update']);
        assert.match(text(actions[0]), /Publish/);
        assert.match(text(actions[1]), /Update Listing/);
        const form = nodes(tree, node => node.type === 'form' && node.props.onSubmit?.name === 'handleUpdate')[0];
        form.props.onSubmit({ preventDefault() {}, nativeEvent: { submitter: { value: actions[0].props.value } } });
        form.props.onSubmit({ preventDefault() {}, nativeEvent: { submitter: { value: actions[1].props.value } } });
        assert.equal(h.requests[0][1].status, 'active');
        assert.equal(h.requests[1][1].status, 'draft');
    } finally { delete globalThis.document; }
});

test('variant photo assignment prevents reusing a gallery image already assigned to another color', () => {
    globalThis.document = { body: {} };
    try {
        const product = { id: 22, name: 'Two-tone carrier', slug: 'two-tone-carrier', status: 'draft', price: '500.00', stock: 5,
            category_id: 12, weight_kg: '0.80', description: 'A durable two-tone carrier for everyday journeys.',
            images: [{ id: 1, image_url: '/images/natural.jpg' }, { id: 2, image_url: '/images/black.jpg' }, { id: 3, image_url: '/images/detail.jpg' }],
            variants: { option1_name: 'Color', colors: [
                { id: 'natural', name: 'Natural', hex: '#d2c3a5', image_url: '/images/natural.jpg', in_stock: true },
                { id: 'black', name: 'Black', hex: '#111111', image_url: '/images/black.jpg', in_stock: true },
            ], sizes: [] } };
        const h = harness(Listings, { products: { data: [product], current_page: 1, total: 1, last_page: 1 }, categories: [], shop: { id: 5, name: 'Bagoo Studio', root_category_id: 12 } });
        nodes(h.render(), node => node.type === 'button' && node.props.title === 'Edit Product')[0].props.onClick();
        let tree = h.render();
        nodes(tree, node => node.type === 'button' && node.props.title === 'Click to change photo')[0].props.onClick();
        tree = h.render();
        const usedByBlack = nodes(tree, node => node.type === 'button' && node.props.title === 'Already assigned to Black');
        assert.equal(usedByBlack.length, 1);
        assert.equal(usedByBlack[0].props.disabled, true);
        const detailPhoto = nodes(tree, node => node.type === 'button' && node.props.title === 'Select this photo')
            .find(button => nodes(button, image => image.type === 'img' && image.props.src === '/images/detail.jpg').length > 0);
        assert.ok(detailPhoto, 'an unassigned extra gallery image remains selectable');
        detailPhoto.props.onClick();
        tree = h.render();
        assert.equal(nodes(tree, node => node.type === 'img' && node.props.alt === 'Natural' && node.props.src === '/images/detail.jpg').length, 1);
    } finally { delete globalThis.document; }
});

test('an extra buyer gallery image changes the photo without selecting a product variant', () => {
    const variations = { colors: [{ id: 'natural', name: 'Natural', hex: '#d2c3a5', image_url: '/images/natural.jpg', in_stock: true }], sizes: [] };
    const product = { id: 23, name: 'Natural carrier', slug: 'natural-carrier', price: '500.00', stock: 5, featured_image: '/images/featured.jpg',
        images: [{ image_url: '/images/detail.jpg' }, { image_url: '/images/natural.jpg' }], variants: variations,
        shop: { id: 5, name: 'Bagoo Crafts', slug: 'bagoo-crafts', city: 'Naval' } };
    const h = harness(Detail, { product, variations, relatedProducts: [], shopStats: { rating: null, products_count: 1, review_count: 0 } });
    let tree = h.render();
    const detailThumbnail = nodes(tree, node => node.type === 'button' && nodes(node, image => image.type === 'img' && image.props.src === '/images/detail.jpg').length > 0)[0];
    assert.ok(detailThumbnail);
    const naturalChoice = nodes(tree, node => node.type === 'button' && text(node) === 'Natural')[0];
    assert.ok(naturalChoice);
    const defaultChoiceClass = naturalChoice.props.className;
    detailThumbnail.props.onClick();
    tree = h.render();
    assert.match(text(tree), /Color \/ Edition:Natural/);
    const naturalChoiceAfterClick = nodes(tree, node => node.type === 'button' && text(node) === 'Natural')[0];
    assert.equal(naturalChoiceAfterClick.props.className, defaultChoiceClass);
});

test('create and edit drawers debounce saves by three seconds and flush on close or page exit', () => {
    const source = readFileSync(resolve(import.meta.dirname, '../..', 'resources/js/Pages/Seller/Products.tsx'), 'utf8');
    const draftHelper = readFileSync(resolve(import.meta.dirname, '../..', 'resources/js/utils/sellerListingDraft.ts'), 'utf8');
    assert.match(source, /scheduleSellerListingDraftSave\([\s\S]*?persistCreateDraft/);
    assert.match(source, /scheduleSellerListingDraftSave\([\s\S]*?persistEditDraft/);
    assert.match(draftHelper, /schedule\(save, 3000\)/);
    assert.match(source, /registerSellerListingDraftExitFlush\(window, flushOpenDrafts\)/);
    assert.match(source, /window\.localStorage/);
    assert.doesNotMatch(source, /window\.sessionStorage/);
});

test('product cards and detail display the variant range, then the selected price', () => {
    const product = { id: 1, name: 'Canvas carrier', slug: 'canvas-carrier', price: '199.00', stock: 5, description: 'Canvas carrier for everyday trips.', shop: { id: 1 }, price_range: { min: '199.00', max: '299.00' } };
    assert.match(text(ProductCard({ product })), /199.00.*299.00/);
    const sizes = [{ id: 'small', name: 'Small', extra_price: 0, stock: 5 }, { id: 'medium', name: 'Medium', extra_price: 50, stock: 5 }, { id: 'large', name: 'Large', extra_price: 100, stock: 5 }];
    const h = harness(Detail, { product, variations: { colors: [], sizes }, relatedProducts: [], shopStats: { rating: null, products_count: 1, review_count: 0 } });
    let tree = h.render(); assert.match(text(tree), /199.00.*299.00/);
    nodes(tree, n => n.type === 'button' && text(n).startsWith('Medium'))[0].props.onClick(); tree = h.render();
    assert.match(text(tree), /249.00/); assert.equal(text(tree).includes('199.00 – ₱299.00'), false);
});

test('waybill declared weight sums original unit snapshots and leaves incomplete legacy weight unknown', () => {
    assert.equal(declaredParcelWeight([{ quantity: 2, declared_weight_kg: '0.80' }, { quantity: 1, declared_weight_kg: '0.01' }]), '1.61');
    assert.equal(declaredParcelWeight([{ quantity: 1, declared_weight_kg: null }]), null);
    assert.equal(declaredParcelWeight([{ quantity: 1, declared_weight_kg: '0.80' }, { quantity: 1 }]), null);
    assert.equal(declaredParcelWeight([]), null);
});


test('bulk product actions require confirmation and display server skip reasons without hiding them', () => {
    const products = [{ id: 1, name: 'Canvas carrier', status: 'active', price: '199.00', stock: 5 }, { id: 2, name: 'Travel carrier', status: 'active', price: '249.00', stock: 3 }];
    const h = harness(Listings, { products: { data: products, total: 2, current_page: 1, last_page: 1 }, categories: [], shop: { name: 'Bagoo Studio' }, bulkResults: [{ id: 1, name: 'Canvas carrier', result: 'skipped', reason: 'This product has unfinished orders.' }] });
    let tree = h.render(); assert.match(text(tree), /This product has unfinished orders/);
    nodes(tree, n => n.props?.['aria-label'] === 'Select this page')[0].props.onChange({ target: { checked: true } });
    tree = h.render(); nodes(tree, n => n.type === 'button' && text(n) === 'Delete')[0].props.onClick();
    tree = h.render(); assert.equal(h.requests.length, 0); assert.match(text(tree), /unfinished orders will be skipped/);
    const confirm = nodes(tree, n => n.type === 'button' && text(n) === 'Confirm selection')[0]; confirm.props.onClick(); confirm.props.onClick();
    assert.equal(h.requests.length, 1); assert.deepEqual(h.requests[0][1], { action: 'delete', product_ids: [1, 2] });
});

test('duplicate retries reuse the same key after an uncertain response and open the returned private draft for editing', () => {
    globalThis.document = { body: {} };
    try {
        const original = { id: 1, name: 'Canvas carrier', status: 'active', price: '199.00', stock: 5, description: 'A sturdy canvas carrier for everyday travel.', category_id: 1 };
        const h = harness(Listings, { products: { data: [original], total: 1, current_page: 1, last_page: 1 }, categories: [], shop: { name: 'Bagoo Studio' } });
        let tree = h.render(); nodes(tree, n => n.props?.title === 'Duplicate as draft')[0].props.onClick();
        const first = h.requests[0]; first[2].onFinish();
        tree = h.render(); nodes(tree, n => n.props?.title === 'Duplicate as draft')[0].props.onClick();
        assert.equal(h.requests[1][1].request_token, first[1].request_token);
        h.requests[1][2].onSuccess({ props: { copiedProduct: { ...original, id: 2, status: 'draft', brand: 'Bagoo Studio', weight_kg: '0.80', sku: 'BGO-COPY-ONE' } } });
        tree = h.render(); assert.equal(h.forms[1].data.status, 'draft'); assert.equal(h.forms[1].data.name, original.name);
        assert.equal(h.forms[1].data.sku, 'BGO-COPY-ONE'); assert.equal(h.forms[1].data.weight_kg, '0.80');
        assert.ok(nodes(tree, n => n.type === 'form' && n.props.onSubmit?.name === 'handleUpdate').length);
    } finally { delete globalThis.document; }
});


test('single-product deletion explains an unfinished-order rejection inside the open confirmation dialog', () => {
    globalThis.document = { body: {} };
    try {
        const product = { id: 1, name: 'Canvas carrier', status: 'active', price: '199.00', stock: 5, order_items_count: 1 };
        const h = harness(Listings, { products: { data: [product], total: 1, current_page: 1, last_page: 1 }, categories: [], shop: { name: 'Bagoo Studio' } });
        let tree = h.render(); nodes(tree, n => n.props?.title === 'Remove Product')[0].props.onClick();
        tree = h.render(); const confirm = nodes(tree, n => n.type === 'button' && text(n) === 'Confirm removal')[0]; confirm.props.onClick(); confirm.props.onClick();
        assert.equal(h.requests.length, 1);
        h.requests[0][1].onError({ product: 'This product has unfinished orders. Finish them before deleting it.' });
        h.requests[0][1].onFinish(); tree = h.render();
        const dialog = nodes(tree, n => n.props?.['aria-labelledby'] === 'remove-product-title')[0];
        assert.match(text(dialog), /This product has unfinished orders/);
    } finally { delete globalThis.document; }
});
