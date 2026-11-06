import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build, buildSync } from 'esbuild';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createInertiaApp } from '@inertiajs/react';
import { File as NodeFile } from 'node:buffer';

const originalFileConstructor = globalThis.File;
if (!globalThis.File) globalThis.File = NodeFile;
after(() => { if (!originalFileConstructor) delete globalThis.File; });

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-commerce-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const bundle = buildSync({
    stdin: { contents: `export { default as ProductCard } from '@/Components/ProductCard';
        export { default as BagHeartButton } from '@/Components/BagHeartButton';
        export { default as SellerListingDetails } from '@/Components/SellerListingDetails';
        export { bagHeartAction } from '@/utils/bagHeart';
        export { default as BuyAgain } from '@/Components/BuyAgainButton';
        export { default as SavedProducts } from '@/Pages/Buyer/SavedProducts';
        export { default as Search } from '@/Pages/Buyer/Search';
        export { default as Product } from '@/Pages/Buyer/ProductDetail';
        export { default as Store } from '@/Pages/Marketplace/ShopDetail';
        export { default as FollowedShops } from '@/Pages/Buyer/FollowedShops';
        export { productQuantityError } from '@/utils/productQuantity';
        export { default as Reviews } from '@/Pages/Seller/Reviews';
        export { default as Detail } from '@/Pages/Buyer/OrderDetail';
        export { default as Purchases } from '@/Pages/Buyer/Profile';
        export { default as RestrictedOrders } from '@/Pages/Buyer/Orders';
        export { default as SellerOrders } from '@/Pages/Seller/Orders';
        export { default as Dashboard } from '@/Pages/Seller/Dashboard';
        export { default as Inventory } from '@/Pages/Seller/Products';
        export { default as SellerSettings } from '@/Pages/Seller/Settings';
        export { newListingStatus, sellerInventoryQuery, sellerVariantStock } from '@/utils/sellerInventory';
        export { sellerListingError, acceptPriceDraft, acceptStockDraft } from '@/utils/sellerListingValidation';
        export { saveSellerListingDraft, loadSellerListingDraft, clearSellerListingDraft, saveSellerListingDraftImages, restoreSellerListingDraftImages, clearSellerListingDraftImages, scheduleSellerListingDraftSave, registerSellerListingDraftExitFlush } from '@/utils/sellerListingDraft';`,
        resolveDir: resolve(import.meta.dirname, '../..'), loader: 'tsx' },
    bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', write: false,
    loader: { '.css': 'empty' },
});
const path = join(temporary, 'commerce.mjs');
writeFileSync(path, bundle.outputFiles[0].text);
const ui = await import(pathToFileURL(path).href);
const heartBundle = await build({
    entryPoints: [resolve(import.meta.dirname, '../../resources/js/Components/BagHeartButton.tsx')],
    bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', write: false,
    plugins: [{ name: 'bag-heart-click-interactions', setup(builder) {
        builder.onResolve({ filter: /^react$/ }, () => ({ path: 'hooks', namespace: 'bag-heart' }));
        builder.onResolve({ filter: /^@inertiajs\/react$/ }, () => ({ path: 'inertia', namespace: 'bag-heart' }));
        builder.onLoad({ filter: /.*/, namespace: 'bag-heart' }, ({ path }) => ({ contents: {
            hooks: `export const useState = initial => globalThis.bagHeartHarness.state(initial);`,
            inertia: `export const usePage = () => globalThis.bagHeartHarness.page; export const router = {
                get: (...args) => globalThis.bagHeartHarness.gets.push(args),
                post: (...args) => globalThis.bagHeartHarness.posts.push(args),
            };`,
        }[path] }));
    } }],
});
const heartPath = join(temporary, 'bag-heart.mjs');
writeFileSync(heartPath, heartBundle.outputFiles[0].text);
const { default: InteractiveBagHeartButton } = await import(pathToFileURL(heartPath).href);
globalThis.route = (name, parameter) => {
    if (!name) return { current: () => false, has: () => true };
    const suffix = parameter && typeof parameter === 'object' && !parameter.id ? `?${new URLSearchParams(parameter)}` : parameter ? `/${typeof parameter === 'object' ? parameter.id : parameter}` : '';
    return `/${name.replaceAll('.', '/')}${suffix}`;
};
after(() => delete globalThis.route);
after(() => delete globalThis.bagHeartHarness);
const shop = { id: 5, name: 'Bagoo Crafts', slug: 'bagoo-crafts', user_id: 7, review_status: 'approved', city: 'Naval', rating: null, created_at: '2026-10-01' };
const product = { id: 3, name: 'Everyday Bag', slug: 'everyday-bag', price: '250.00', stock: 10, rating: null, verified_review_count: 0, sales_count: 0, shop, variants: {}, images: [] };
const summary = { rating: null, review_count: 0, response_rate: null, products_count: 1, joined: '2026-10-01' };
const paginated = data => ({ data, current_page: 1, last_page: 1, per_page: 12, total: data.length });

function bagHeartHarness(cartItemIdsByProduct = {}) {
    const slots = []; let cursor = 0;
    const harness = {
        page: { props: { auth: { user: { id: 7, role: 'buyer', status: 'active' } }, cartItemIdsByProduct } },
        gets: [], posts: [],
        state(initial) {
            const index = cursor++;
            if (!(index in slots)) slots[index] = typeof initial === 'function' ? initial() : initial;
            return [slots[index], next => { slots[index] = typeof next === 'function' ? next(slots[index]) : next; }];
        },
        render(item = product) {
            globalThis.bagHeartHarness = harness;
            cursor = 0;
            return InteractiveBagHeartButton({ product: item });
        },
    };
    return harness;
}

function findElement(tree, predicate) {
    if (!tree || typeof tree !== 'object') return undefined;
    if (Array.isArray(tree)) return tree.map(child => findElement(child, predicate)).find(Boolean);
    if (predicate(tree)) return tree;
    return findElement(tree.props?.children, predicate);
}

test('seller field feedback accepts valid limits and identifies invalid input without trimming or rounding it', () => {
    const accepted = { name: ['Totes', 'N'.repeat(120)], description: ['d'.repeat(20), 'd'.repeat(3000)],
        price: ['1.00', '1.01', '999999.99', '1000000.00'], stock: ['0', '1', '99999'] };
    const rejected = { name: ['Tote', 'N'.repeat(121), '     '], description: ['d'.repeat(19), 'd'.repeat(3001)],
        price: ['0', '-50', '0.99', '199.999', '1000000.01', 'abc', '1e3'], stock: ['-1', '100000', '2.5', 'abc'] };
    for (const [field, values] of Object.entries(accepted)) for (const value of values) assert.equal(ui.sellerListingError(field, value), undefined, `${field}: ${value}`);
    for (const [field, values] of Object.entries(rejected)) for (const value of values) assert.equal(typeof ui.sellerListingError(field, value), 'string', `${field}: ${value}`);
});

test('seller numeric draft controls reject leading zeros, text, excess precision and out-of-range values', () => {
    for (const value of ['1', '1.0', '1.00', '999999.99', '1000000.00', '']) assert.equal(ui.acceptPriceDraft(value), value);
    for (const value of ['0234', '01.00', '1.234', '1000000.01', '1e3', 'letters']) assert.equal(ui.acceptPriceDraft(value), null, value);
    assert.equal(ui.acceptPriceDraft('99999999.99', 99999999.99), '99999999.99');
    assert.equal(ui.acceptPriceDraft('100000000', 99999999.99), null);
    for (const value of ['0', '1', '99999', '']) assert.equal(ui.acceptStockDraft(value), value);
    for (const value of ['00', '01', '09999', '100000', '2.5', 'text']) assert.equal(ui.acceptStockDraft(value), null, value);
});

test('seller draft recovery keeps permitted fields and file references but excludes credentials, file data and blob previews', () => {
    const values = new Map();
    const storage = { setItem: (key, value) => values.set(key, value), getItem: key => values.get(key) ?? null, removeItem: key => values.delete(key) };
    const key = 'bagoo:seller-product-draft:v1:2:create';
    assert.equal(ui.saveSellerListingDraft(storage, key, {
        form: { name: 'Canvas Tote', price: '0234', tag_names: ['weekend carryall', { password: 'nested-form-secret' }], store_category_ids: [9], request_token: 'never-cache', password: 'never-cache', image_files: ['file'] },
        gallery: [{ id: 'upload', type: 'file', url: 'blob:local-preview', file: new NodeFile([new Uint8Array([1, 2, 3])], 'front.png', { type: 'image/png', lastModified: 456 }) }, { id: 'extra', type: 'url', url: 'https://img.test/detail.jpg' }],
        variantState: { hasOption1: true, password: 'nested-state-secret', variants: {
            pricing_mode: 'independent', access_token: 'nested-variant-secret',
            colors: [{ id: 'natural', name: 'Natural', hex: '#d2c3a5', in_stock: true, image_url: 'blob:local-preview', gallery_item_id: 'upload', gallery_index: 0, password: 'nested-color-secret' }],
            sizes: [{ id: 'large', name: 'Large', extra_price: '20', stock: '3', otp: 'nested-size-secret' }],
            combinations: [{ color: 'Natural', size: 'Large', stock: '3', price: '520.00', sku: 'BGO-NAT-L', api_token: 'nested-combination-secret' }],
        } },
    }), true);
    const saved = ui.loadSellerListingDraft(storage, key);
    assert.deepEqual(saved.form, { name: 'Canvas Tote', price: '0234', tag_names: ['weekend carryall'] });
    assert.deepEqual(saved.gallery, [
        { id: 'upload', type: 'file', name: 'front.png', size: 3, mimeType: 'image/png', lastModified: 456 },
        { id: 'extra', type: 'url', url: 'https://img.test/detail.jpg' },
    ]);
    assert.equal(saved.variantState.variants.colors[0].image_url, null);
    assert.equal(saved.variantState.variants.colors[0].gallery_item_id, 'upload');
    assert.equal(saved.variantState.variants.pricing_mode, 'independent');
    assert.deepEqual(saved.variantState.variants.combinations, [{ color: 'Natural', size: 'Large', stock: '3', price: '520.00', sku: 'BGO-NAT-L' }]);
    const raw = values.get(key);
    for (const secret of ['never-cache', 'nested-form-secret', 'nested-state-secret', 'nested-variant-secret', 'nested-color-secret', 'nested-size-secret', 'nested-combination-secret']) {
        assert.equal(raw.includes(secret), false, `raw local storage excludes ${secret}`);
    }
    ui.clearSellerListingDraft(storage, key);
    assert.equal(ui.loadSellerListingDraft(storage, key), null);
});

function memoryDraftImageStore() {
    const scopes = new Map();
    return {
        async replaceScope(scope, records) { scopes.set(scope, records.map(record => ({ ...record }))); },
        async readScope(scope) { return (scopes.get(scope) ?? []).map(record => ({ ...record })); },
        async clearScope(scope) { scopes.delete(scope); },
    };
}

test('seller listing image recovery roundtrips files by shop and draft, prunes removed files, and clears on discard', async () => {
    const store = memoryDraftImageStore();
    const front = new NodeFile([new Uint8Array([1, 2, 3])], 'front.png', { type: 'image/png', lastModified: 100 });
    const back = new NodeFile([new Uint8Array([4, 5])], 'back.webp', { type: 'image/webp', lastModified: 200 });
    const firstGallery = [{ id: 'front', type: 'file', file: front }, { id: 'back', type: 'file', file: back }];
    assert.equal(await ui.saveSellerListingDraftImages(store, 12, 'create', firstGallery), true);
    const refs = [
        { id: 'front', type: 'file', name: front.name, size: front.size, mimeType: front.type, lastModified: front.lastModified },
        { id: 'back', type: 'file', name: back.name, size: back.size, mimeType: back.type, lastModified: back.lastModified },
    ];
    const recovered = await ui.restoreSellerListingDraftImages(store, 12, 'create', refs);
    assert.equal(recovered.available, true);
    assert.deepEqual(recovered.missingIds, []);
    assert.equal(recovered.files.get('front').name, 'front.png');
    assert.equal(recovered.files.get('front').type, 'image/png');
    assert.equal(recovered.files.get('front').size, 3);
    assert.deepEqual([...((await ui.restoreSellerListingDraftImages(store, 13, 'create', refs)).missingIds)], ['front', 'back']);
    assert.deepEqual([...((await ui.restoreSellerListingDraftImages(store, 12, 'product:3', refs)).missingIds)], ['front', 'back']);

    assert.equal(await ui.saveSellerListingDraftImages(store, 12, 'create', [{ id: 'back', type: 'file', file: back }]), true);
    assert.deepEqual((await ui.restoreSellerListingDraftImages(store, 12, 'create', refs)).missingIds, ['front']);
    assert.equal(await ui.clearSellerListingDraftImages(store, 12, 'create'), true);
    assert.deepEqual((await ui.restoreSellerListingDraftImages(store, 12, 'create', refs)).missingIds, ['front', 'back']);
});

test('seller listing image recovery enforces file and count limits and handles unavailable or quota-failed storage', async () => {
    const store = memoryDraftImageStore();
    const validFile = { name: 'image.png', size: 10, type: 'image/png', lastModified: 100 };
    assert.equal(await ui.saveSellerListingDraftImages(store, 1, 'create', [{ id: 'bad-size', type: 'file', file: { ...validFile, size: 5 * 1024 * 1024 + 1 } }]), false);
    assert.equal(await ui.saveSellerListingDraftImages(store, 1, 'create', [{ id: 'bad-type', type: 'file', file: { ...validFile, type: 'text/plain' } }]), false);
    assert.equal(await ui.saveSellerListingDraftImages(store, 1, 'create', Array.from({ length: 10 }, (_, index) => ({ id: `item-${index}`, type: 'file', file: validFile }))), false);
    const refs = [{ id: 'image', type: 'file', name: 'image.png', size: 10, mimeType: 'image/png', lastModified: 100 }];
    const noStorage = await ui.restoreSellerListingDraftImages(undefined, 1, 'create', refs);
    assert.equal(noStorage.available, false);
    assert.deepEqual(noStorage.missingIds, ['image']);
    const quotaStore = { ...store, async replaceScope() { throw new Error('QuotaExceededError'); } };
    assert.equal(await ui.saveSellerListingDraftImages(quotaStore, 1, 'create', [{ id: 'image', type: 'file', file: validFile }]), false);
});

test('discarding an image draft clears after an IndexedDB save already in flight', async () => {
    let startWrite;
    let releaseWrite;
    const writeStarted = new Promise(resolve => { startWrite = resolve; });
    const writeGate = new Promise(resolve => { releaseWrite = resolve; });
    const scopes = new Map();
    const store = {
        async replaceScope(scope, records) { startWrite(); await writeGate; scopes.set(scope, records); },
        async readScope(scope) { return scopes.get(scope) ?? []; },
        async clearScope(scope) { scopes.delete(scope); },
    };
    const file = new NodeFile([new Uint8Array([1])], 'image.png', { type: 'image/png', lastModified: 100 });
    const save = ui.saveSellerListingDraftImages(store, 8, 'create', [{ id: 'image', type: 'file', file }]);
    await writeStarted;
    const discard = ui.clearSellerListingDraftImages(store, 8, 'create');
    releaseWrite();
    assert.equal(await save, true);
    assert.equal(await discard, true);
    assert.equal(scopes.size, 0);
});

test('seller draft autosave waits for three seconds and cancels the earlier pending save', () => {
    const pending = new Map(); let nextHandle = 0; const saved = [];
    const schedule = (callback, delayMs) => { const id = ++nextHandle; pending.set(id, { callback, delayMs }); return id; };
    const cancel = id => pending.delete(id);
    const cancelFirst = ui.scheduleSellerListingDraftSave(schedule, cancel, () => saved.push('old'));
    assert.equal(pending.get(1).delayMs, 3000);
    cancelFirst();
    const cancelLatest = ui.scheduleSellerListingDraftSave(schedule, cancel, () => saved.push('latest'));
    assert.equal(pending.size, 1);
    pending.get(2).callback();
    assert.deepEqual(saved, ['latest']);
    cancelLatest();
    assert.equal(pending.size, 0);
});

test('seller draft flush runs on pagehide and beforeunload and removes listeners on cleanup', () => {
    const listeners = new Map(); const saved = [];
    const target = {
        addEventListener: (type, listener) => listeners.set(type, listener),
        removeEventListener: (type, listener) => { if (listeners.get(type) === listener) listeners.delete(type); },
    };
    const cleanup = ui.registerSellerListingDraftExitFlush(target, () => saved.push('saved'));
    listeners.get('pagehide')();
    listeners.get('beforeunload')();
    assert.deepEqual(saved, ['saved', 'saved']);
    cleanup();
    assert.equal(listeners.size, 0);
});
async function render(Component, component, props, role = 'buyer') {
    let view;
    const result = await createInertiaApp({
        page: { component, props: { auth: { user: { id: 7, name: 'Ana Santos', role, email: `${role}@bagoo.test`, status: 'active', kyc_status: 'approved' } }, flash: {}, cartCount: 0, ...props }, url: '/buyer/orders', version: null },
        resolve: () => Component,
        setup: ({ App, props }) => { view = createElement(App, props); return view; },
        render: () => renderToStaticMarkup(view),
    });
    return result.body;
}

test('shop settings present real review feedback instead of an unconditional verification badge', async () => {
    const reviewed = { ...shop, status: 'suspended', eligible: false, review_status: 'rejected',
        review_feedback: 'Please provide the current permit.', review_issues: [], owner_birthday: '1990-01-01',
        can_submit: true, documents: {}, decision_history: [], review_token: 'retained-review-token' };
    const html = await render(ui.SellerSettings, 'Seller/Settings', { shop, shopReview: reviewed, categories: [], pickupPin: { latitude: null, longitude: null, location_token: 'token' } }, 'seller');
    assert.match(html, /Shop approval/);
    assert.match(html, /Please provide the current permit\./);
    assert.match(html, /rejected[\s\S]*?suspended/);
    assert.match(html, /Update submission/);
    assert.match(html, /Review history/);
    assert.match(html, /Pickup map pin/);
    assert.match(html, /Seller pickup location map/);
    assert.match(html, /<button[^>]*min-h-\[44px\][^>]*>Save pickup pin<\/button>/);
    assert.doesNotMatch(html, /PREFERRED MALL|Auto-dispatch enabled|Active Store|Open dashboard/);
});

test('an unrated product card displays an honest empty state', () => {
    const html = renderToStaticMarkup(createElement(ui.ProductCard, { product }));
    assert.match(html, /No ratings yet/);
    assert.doesNotMatch(html, />5\.0</);
});
test('Bag heart follows existing product lines and remains clickable to open an unavailable item', async () => {
    const unavailable = { ...product, stock: 0, shop: { ...shop, vacation_mode: true } };
    const html = await render(ui.ProductCard, 'Buyer/Search', {
        product: unavailable,
        heartControl: createElement(ui.BagHeartButton, { product: unavailable }),
        cartItemIdsByProduct: { [product.id]: 42 },
    });
    assert.match(html, /aria-pressed="true"/);
    assert.match(html, /Already in Bag: Everyday Bag/);
    assert.doesNotMatch(html, /<button[^>]*disabled[^>]*Already in Bag/);
    assert.deepEqual(ui.bagHeartAction(42), { type: 'open', itemId: 42 });
    assert.deepEqual(ui.bagHeartAction(undefined), { type: 'add' });
});

test('Bag heart click adds one unit when absent and opens the existing Bag line without adding again', () => {
    const addHarness = bagHeartHarness();
    const addButton = findElement(addHarness.render(), node => node.type === 'button');
    const addEvent = { prevented: false, stopped: false, preventDefault() { this.prevented = true; }, stopPropagation() { this.stopped = true; } };

    addButton.props.onClick(addEvent);

    assert.equal(addEvent.prevented, true);
    assert.equal(addEvent.stopped, true);
    assert.equal(addHarness.posts.length, 1);
    assert.equal(addHarness.gets.length, 0);
    assert.equal(addHarness.posts[0][0], route('cart.store'));
    assert.deepEqual(addHarness.posts[0][1], { product_id: product.id, quantity: 1 });
    assert.equal(addHarness.posts[0][2].preserveScroll, true);

    const existingHarness = bagHeartHarness({ [product.id]: 42 });
    const existingButton = findElement(existingHarness.render(), node => node.type === 'button');
    const existingEvent = { preventDefault() {}, stopPropagation() {} };

    existingButton.props.onClick(existingEvent);

    assert.deepEqual(existingHarness.gets, [[route('buyer.cart', { focus: 42 })]]);
    assert.equal(existingHarness.posts.length, 0);
});
test('seller product details use a fixed approved department and flat tags', async () => {
    const html = await render(ui.SellerListingDetails, 'Seller/Products', {
        data: { brand: '', condition: '', weight_kg: '', length_cm: '', width_cm: '', height_cm: '', tag_names: [], tag_draft: '' },
        errors: {}, tags: [{ id: 1, shop_id: 5, name: 'Travel tote' }], departmentName: 'Pet Supplies', productName: 'Carryall', onChange: () => {},
    }, 'seller');
    assert.match(html, /approved Pet Supplies department/);
    assert.match(html, /Travel tote/);
    assert.match(html, /Suggest tags/);
    assert.doesNotMatch(html, /Store categories|Parent category|Subcategories/);
});
test('public product reviews distinguish legacy rows and render persisted seller replies', async () => {
    const html = await render(ui.Product, 'Buyer/ProductDetail', {
        product: { ...product, reviews: [
            { id: 1, rating: 4, verified_purchase: true, buyer: { name: 'Verified Buyer' }, purchased_options: { color: 'Natural', size: 'Large' }, comment: 'The bag arrived.', reply: { text: 'Thank you for your review.', shop_name: shop.name, created_at: '2026-10-01', updated_at: '2026-10-02' } },
            { id: 2, rating: 5, verified_purchase: false, buyer: { name: 'Older Buyer' }, comment: 'An older review.' },
        ] }, variations: { colors: [], sizes: [] }, relatedProducts: [], shopStats: summary,
    });
    assert.match(html, /Verified Purchase/);
    assert.match(html, /Unverified legacy review/);
    assert.match(html, /Thank you for your review\./);
    assert.match(html, /Purchased option: Natural · Large/);
    assert.match(html, /No ratings yet/);
    assert.match(html, /0 Verified Reviews/);
    assert.doesNotMatch(html, /Lowest Price Guaranteed|MALL VERIFIED|PREFERRED|within hours|5\.0 Stars|Guaranteed delivery in/);
});
test('seller reviews render saved replies and edit controls after refresh', async () => {
    const html = await render(ui.Reviews, 'Seller/Reviews', {
        shop, reviews: paginated([{ id: 1, rating: 3, verified_purchase: true, comment: 'The bag arrived.', buyer: { name: 'Ana Santos' }, reply: { text: 'Your feedback has been recorded.', created_at: '2026-10-01', updated_at: '2026-10-02' } }]),
        stats: { average_rating: 3, total_reviews: 1, response_rate: '100%', rating_breakdown: { '5_star': 0, '4_star': 0, '3_star': 1, '2_star': 0, '1_star': 0 } },
    }, 'seller');
    assert.match(html, /Your feedback has been recorded\./);
    assert.match(html, /Edit saved reply/);
    assert.match(html, /Review reply rate/);
    assert.match(html, /3\.0 out of 5 stars/);
    assert.doesNotMatch(html, /Top Rated Seller|Mall Merchant|within hours/);
});
test('storefront metrics use recorded reviews and a real shop opening date', async () => {
    const html = await render(ui.Store, 'Marketplace/ShopDetail', { shop, products: paginated([product]), shopStats: summary, filters: {}, categories: [], followerCount: 0, isFollowing: false });
    assert.match(html, /No ratings yet/);
    assert.match(html, /Review reply rate/);
    assert.match(html, /Shop opened/);
    assert.doesNotMatch(html, /4\.95|99%|98%|MALL PREFERRED|>MALL</);
    assert.match(html, /0 followers/);
    assert.doesNotMatch(html, /14\.2k|COIN10|PRIMESHIP|FREE DOORSTEP DELIVERY/);
});

test('unavailable followed shops retain removal without disclosing restricted storefront details', async () => {
    const html = await render(ui.FollowedShops, 'Buyer/FollowedShops', { entries: paginated([{ id: 1, shop_id: shop.id, shop: null }]) });
    assert.match(html, /Shop currently unavailable/);
    assert.match(html, /Following/);
    assert.doesNotMatch(html, /Bagoo Crafts/);
});

test('typed product quantities reject invalid values without silently converting them', () => {
    for (const value of ['', '0', '01', '-1', '1.5', '1e1', 'abc', '100', ' 1', '１']) assert.ok(ui.productQuantityError(value, 99), value);
    assert.equal(ui.productQuantityError('1', 1), null);
    assert.equal(ui.productQuantityError('99', 99), null);
    assert.match(ui.productQuantityError('3', 2), /only 2/);
    assert.match(ui.productQuantityError('1', 0), /no more/);
});
test('completed order details hide review creation once all items have saved reviews', async () => {
    const html = await render(ui.Detail, 'Buyer/OrderDetail', {
        order: { id: 4, order_number: 'BGO-REVIEW', status: 'completed', payment_status: 'paid', total_amount: 250, subtotal: 250, shipping_fee: 0, created_at: '2026-10-01', items: [{ id: 10, product_id: product.id, quantity: 1, unit_price: 250, product, review: { id: 1, order_item_id: 10 } }] },
        canUsePortal: true, canConfirmReceipt: false, pickupClaim: null, pickupHub: null, pickupCodeUrl: null, orderNotices: [],
    });
    assert.match(html, /Review saved/);
    assert.doesNotMatch(html, /Rate &amp; Upload Photos/);
});

test('review edit controls use the owned server action instead of assuming every saved review is editable', async () => {
    for (const url of ['/buyer/reviews/1', null]) {
        const html = await render(ui.Detail, 'Buyer/OrderDetail', {
            order: { id: 4, order_number: 'BGO-EDIT', status: 'completed', payment_status: 'paid', total_amount: 250, subtotal: 250, shipping_fee: 0, created_at: '2026-10-01', items: [{ id: 10, product_id: product.id, quantity: 1, unit_price: 250, product, review: { id: 1, order_item_id: 10, rating: 4, comment: 'The bag arrived.', edit_url: url } }] },
            canUsePortal: true, canConfirmReceipt: false, pickupClaim: null, pickupHub: null, pickupCodeUrl: null, orderNotices: [],
        });
        if (url) assert.match(html, /Edit review/);
        else assert.doesNotMatch(html, /Edit review/);
        assert.doesNotMatch(html, /Rate &amp; Upload Photos/);
    }
});

const buyer = { id: 7, name: 'Ana Santos', role: 'buyer', email: 'buyer@bagoo.test', status: 'active', kyc_status: 'approved' };
const lines = [
    { id: 10, product_id: 3, product, quantity: 2, unit_price: 250, subtotal: 500, color: 'Blue', size: 'M' },
    { id: 11, product_id: 4, product: { ...product, id: 4, name: 'Travel Pouch' }, quantity: 1, unit_price: 100, subtotal: 100, color: 'Red', size: 'L' },
];
const order = { id: 40, buyer_id: buyer.id, buyer, order_number: 'BGO-GROUPED', status: 'preparing', payment_method: 'cod', payment_status: 'pending', subtotal: 600, shipping_fee: 50, total_amount: 650, created_at: '2026-10-01', items: lines, delivery: { id: 20, status: 'unassigned', tracking_number: 'BGO-WAYBILL' }, can_mark_ready: true, can_cancel: true, can_print_waybill: true, can_fulfill: true, has_mixed_shops: false };
const counts = { all: 42, to_pack: 12, to_pickup: 2, to_ship: 14, in_transit: 8, delivered: 4, completed: 6, delivery_failed: 5, returned: 3, cancelled: 2, return_custody: 1 };
const sellerProps = { shop, shopEligible: true, returnReceiptToken: 'receipt-token', currentStatus: 'to_pack', counts, cancellationReasons: ['Out of stock / Inventory shortage', 'Other reason'], batchLimit: 50 };

test('seller fulfilment renders one card and selection for every original order, including all item variants', async () => {
    const html = await render(ui.SellerOrders, 'Seller/Orders', { ...sellerProps, orders: { ...paginated([order]), total: 12, last_page: 2, next_page_url: '/seller/orders?status=to_pack&page=2' } }, 'seller');
    assert.equal((html.match(/data-order-id="40"/g) || []).length, 1);
    assert.equal((html.match(/type="checkbox"/g) || []).length, 1);
    assert.match(html, /Everyday Bag/); assert.match(html, /Travel Pouch/);
    assert.match(html, /Blue/); assert.match(html, /Red/); assert.match(html, /Product subtotal/);
    assert.match(html, /Page 1 of 2/);
    assert.match(html, /status=to_pack&amp;page=2/);
    assert.match(html, /<span>Ready for Pickup<\/span>/);
    for (const label of ['Delivered', 'Completed', 'Delivery issue', 'Returned', 'Cancelled', 'Return parcels']) assert.ok(html.includes(label));
});

test('mixed-shop seller history displays owned products without fulfilment, cancellation, or printing controls', async () => {
    const html = await render(ui.SellerOrders, 'Seller/Orders', { ...sellerProps, orders: paginated([{ ...order, has_mixed_shops: true, can_mark_ready: false, can_cancel: false, can_print_waybill: false, can_fulfill: false }]) }, 'seller');
    assert.match(html, /read-only/i); assert.match(html, /Travel Pouch/);
    assert.doesNotMatch(html, /type="checkbox"|<span>Ready for Pickup<\/span>|Decline \/ Cancel Order/);
    assert.match(html, /<button[^>]*disabled=""[^>]*title="Print Thermal Waybill"/);
});

test('buyer purchases retain full-history counts and paginate with receipt actions only for eligible records', async () => {
    const html = await render(ui.Purchases, 'Buyer/Profile', {
        user: buyer, addresses: [], wallet: { available: false, balance: null, currency: 'PHP', recent_transactions: [] },
        orders: { ...paginated([{ ...order, status: 'delivered', can_confirm_receipt: true, return_availability: { can_request: true } }, { ...order, id: 41, order_number: 'BGO-UNREADY', status: 'delivered', can_confirm_receipt: false }]), total: 4, last_page: 2, next_page_url: '/buyer/profile?tab=orders&order_status=delivered&page=2' },
        ordersCount: 42, orderCounts: counts, currentOrderStatus: 'delivered', initialTab: 'orders',
    });
    assert.match(html, /My Purchases &amp; Order Tracking/);
    assert.match(html, /Purchase pages/); assert.match(html, /Page 1 of 2/);
    assert.match(html, />42</); assert.equal((html.match(/Confirm Received/g) || []).length, 1);
    assert.equal((html.match(/Request a return/g) || []).length, 1); assert.doesNotMatch(html, /Disputes unavailable|Report Defect/);
    for (const label of ['Delivered', 'Completed', 'Order issues']) assert.ok(html.includes(label));
});

test('restricted buyer order history retains narrow access and filtered pagination', async () => {
    const html = await render(ui.RestrictedOrders, 'Buyer/Orders', { orders: { ...paginated([{ ...order, status: 'delivery_failed' }]), total: 5, last_page: 2, next_page_url: '/buyer/orders?order_status=delivery_failed&page=2' }, canUsePortal: false, orderCounts: counts, currentOrderStatus: 'delivery_failed' });
    assert.match(html, /Delivery issue \(5\)/); assert.match(html, /Completed \(6\)/);
    assert.match(html, /order_status=delivery_failed&amp;page=2/);
    assert.doesNotMatch(html, /Confirm Received|Delivery Address Book|My Account &amp; Security/);
});

test('seller dashboard groups recent orders and links actual return and exception stages', async () => {
    const html = await render(ui.Dashboard, 'Seller/Dashboard', {
        shop, dailySales: [], recentOrders: [order], topProducts: [],
        stats: { totalProducts: 2, lowStockCount: 0, completedGrossSales: 600, completedUnits: 3, completedOrderCount: 1, averageCompletedOrderValue: 600, estimatedSellerShare: 540, openOrderValue: 600, openUnits: 3, openOrderCount: 1, pendingPackCount: 12, readyPickupCount: 2, shippedCount: 8, completedCount: 6, deliveredCount: 4, deliveryIssueCount: 5, returnedCount: 3, cancelledCount: 2, returnCount: 1 },
    }, 'seller');
    assert.equal((html.match(/Order #BGO-GROUPED/g) || []).length, 1);
    assert.match(html, /Everyday Bag \+ 1 more/); assert.match(html, /Units: 3/);
    assert.match(html, /status=return_custody/); assert.match(html, /status=delivery_failed/);
    assert.match(html, /status=delivered/); assert.match(html, /status=completed/);
    assert.match(html, /Review buyer returns and refunds/);
    assert.doesNotMatch(html, /Return Claims/);
});

test('inventory retains all filters and page links while displaying actual zero and legacy variant stock', async () => {
    const listing = { ...product, status: 'active', stock: 20, category_id: 5, category: { id: 5, name: 'Pet Supplies' }, description: 'A daily carrier.', variants: {
        option1_name: 'Finish', option2_name: 'Size',
        sizes: [{ id: 's', name: 'Small', stock: 0 }, { id: 'l', name: 'Large', stock: null }],
        colors: [{ id: 'blue', name: 'Blue', in_stock: false }, { id: 'red', name: 'Red', in_stock: true }],
    } };
    const url = '/seller/products?search=carrier&category_id=5&status=active&stock=low_stock&page=2';
    const html = await render(ui.Inventory, 'Seller/Products', {
        shop, categories: [{ id: 5, name: 'Pet Supplies' }],
        filters: { search: 'carrier', category_id: 5, status: 'active', stock: 'low_stock' },
        products: { ...paginated([listing]), total: 11, last_page: 2, links: [
            { url: null, label: 'Previous', active: false }, { url, label: 'Next', active: false },
        ] },
    }, 'seller');
    assert.match(html, /<input[^>]*id="inventory-search"[^>]*value="carrier"/);
    assert.match(html, /<option value="5" selected="">Pet Supplies/);
    assert.match(html, /<option value="active" selected="">Active/);
    assert.match(html, /<option value="low_stock" selected="">Active: low stock/);
    assert.match(html, /search=carrier&amp;category_id=5&amp;status=active&amp;stock=low_stock&amp;page=2/);
    assert.match(html, /Small: <strong>0<\/strong> available/);
    assert.match(html, /Large: <strong>20<\/strong> \(listing stock\)/);
    assert.match(html, /Blue: Unavailable/); assert.match(html, /Red: Available/);
    assert.match(html, /Drafts stay off the marketplace\./);
    assert.doesNotMatch(html, /My Purchases|Seller shop switching/);
});

test('inventory distinguishes filtered empty results and marks six units as healthy stock', async () => {
    const base = { shop, categories: [], filters: { status: 'draft' }, products: paginated([]) };
    const empty = await render(ui.Inventory, 'Seller/Products', base, 'seller');
    assert.match(empty, /No listings match these filters/); assert.match(empty, /Clear filters/);
    const populated = await render(ui.Inventory, 'Seller/Products', {
        ...base, filters: {}, products: paginated([{ ...product, stock: 6, status: 'active', description: '' }]),
    }, 'seller');
    assert.match(populated, /border-emerald-300[^"<]*"[^>]*>[\s\S]*?6 available/);
});

test('inventory query resets pagination and keeps category and stock when search is cleared', () => {
    const selected = { search: ' carrier ', category_id: 5, status: 'active', stock: 'out_of_stock', page: 8 };
    assert.deepEqual(ui.sellerInventoryQuery(selected), { search: 'carrier', category_id: '5', status: 'active', stock: 'out_of_stock' });
    assert.deepEqual(ui.sellerInventoryQuery({ ...selected, search: '' }), { category_id: '5', status: 'active', stock: 'out_of_stock' });
    assert.deepEqual(ui.sellerInventoryQuery({ search: ' ', category_id: null, status: 'all', stock: 'all' }), {});
});

test('editing and serializing size quantities keeps numeric and string zero rather than copying listing stock', () => {
    const sizes = [0, '0', null, undefined, 5].map((stock, index) => ({ id: String(index), stock: ui.sellerVariantStock(stock, 20) }));
    const saved = JSON.parse(JSON.stringify({ sizes }));
    assert.deepEqual(saved.sizes.map(size => size.stock), [0, 0, 20, 20, 5]);
    assert.equal(ui.sellerVariantStock(null, 0), 0);
});

test('the clicked draft or publish submitter selects the intended creation status', () => {
    assert.equal(ui.newListingStatus('draft'), 'draft');
    assert.equal(ui.newListingStatus('active'), 'active');
    assert.equal(ui.newListingStatus(null), 'active');
});

test('dashboard stock alerts link to separate active inventory worklists', async () => {
    const html = await render(ui.Dashboard, 'Seller/Dashboard', {
        shop, dailySales: [], recentOrders: [], topProducts: [],
        stats: { totalProducts: 9, lowStockCount: 2, outOfStockCount: 1, completedGrossSales: 0, completedUnits: 0, completedOrderCount: 0, averageCompletedOrderValue: 0, estimatedSellerShare: 0, openOrderValue: 0, openUnits: 0, openOrderCount: 0, pendingPackCount: 0, readyPickupCount: 0, shippedCount: 0, completedCount: 0, deliveredCount: 0, deliveryIssueCount: 0, returnedCount: 0, cancelledCount: 0, returnCount: 0 },
    }, 'seller');
    assert.match(html, /status=active&amp;stock=low_stock/); assert.match(html, /2 low stock \(1–5\)/);
    assert.match(html, /status=active&amp;stock=out_of_stock/); assert.match(html, /1 out of stock/);
    assert.doesNotMatch(html, /Stock healthy/);
});


test('saved products keep buyer styling, sold-out labels and removal controls without restricted listing links', async () => {
    const html = await render(ui.SavedProducts, 'Buyer/SavedProducts', {
        savedProductIds: [3, 99], entries: paginated([
            { id: 1, product_id: 3, product: { ...product, stock: 0 }, available: false, unavailable_reason: 'Out of stock' },
            { id: 2, product_id: 99, product: null, available: false, unavailable_reason: 'This saved product is currently unavailable.' },
        ]),
        cartItemIdsByProduct: { 3: 42 },
    });
    assert.match(html, /Saving does not reserve stock/);
    assert.match(html, /Out of stock/); assert.match(html, /Saved product unavailable/);
    assert.equal((html.match(/Remove from saved/g) || []).length, 2);
    assert.equal((html.match(/aria-pressed="true"/g) || []).length, 3);
    assert.match(html, /Already in Bag: Everyday Bag/);
    assert.match(html, /Already in Bag: Everyday Bag/);
    assert.match(html, /aria-label="Saved list options"/);
    assert.match(html, /Remove from saved list/);
    assert.match(html, /disabled=""[^>]*>Unavailable for purchase/);
    assert.doesNotMatch(html, /buyer\/products\/show\/99/);
});

test('every buyer product-card page exposes the Bag heart action', () => {
    const pages = [
        'resources/js/Pages/Buyer/Home.tsx',
        'resources/js/Pages/Buyer/Search.tsx',
        'resources/js/Pages/Buyer/SavedProducts.tsx',
        'resources/js/Pages/Buyer/Promotion.tsx',
        'resources/js/Pages/Marketplace/Catalog.tsx',
    ];
    for (const page of pages) {
        const source = readFileSync(resolve(import.meta.dirname, '../..', page), 'utf8');
        assert.match(source, /import BagHeartButton from ['"]@\/Components\/BagHeartButton['"]/);
        assert.match(source, /heartControl=\{<BagHeartButton product=\{(?:product|p|entry\.product)\} \/>\}/);
    }
    const card = readFileSync(resolve(import.meta.dirname, '../../resources/js/Components/ProductCard.tsx'), 'utf8');
    const saved = readFileSync(resolve(import.meta.dirname, '../../resources/js/Pages/Buyer/SavedProducts.tsx'), 'utf8');
    assert.doesNotMatch(card, /saveControl|SavedProductButton/);
    assert.doesNotMatch(saved, /saveControl=/);
});

test('catalogue guest heart adds a product to the Bag and invalid ranges show readable feedback', async () => {
    const html = await render(ui.Search, 'Buyer/Search', {
        auth: { user: null }, products: paginated([product]), categories: [], relatedProducts: [],
        filters: { sort: 'price_asc', min_price: '200', max_price: '100' },
        errors: { max_price: 'The maximum price must be at least the minimum price.' },
    });
    assert.match(html, /aria-label="Add one Everyday Bag to Bag"/);
    assert.match(html, /role="alert"/); assert.match(html, /maximum price must be at least/);
    assert.doesNotMatch(html, /aria-pressed="true"/);
});


test('Buy again is one mutation button without another product-selection page', async () => {
    const html = await render(ui.BuyAgain, 'Buyer/OrderDetail', { url: '/buyer/orders/40/buy-again', token: 'owned-token' });
    assert.match(html, /type="button"[^>]*>Buy again/);
    assert.doesNotMatch(html, /Quantity to add|Original quantity|type="checkbox"|Add selected to Bag/);
    const disabled = await render(ui.BuyAgain, 'Buyer/OrderDetail', { url: '/buyer/orders/40/buy-again', token: 'owned-token', disabled: true });
    assert.match(disabled, /<button[^>]*disabled=""/);
});

test('completed purchase details show Buy again only with ordinary portal access and an owned action URL', async () => {
    const props = {
        order: { id: 4, order_number: 'BGO-REPEAT', status: 'completed', total_amount: 250, subtotal: 250, shipping_fee: 0, created_at: '2026-10-01', items: [] },
        canUsePortal: true, buyAgainUrl: '/buyer/orders/4/buy-again', buyAgainToken: 'owned-token', canConfirmReceipt: false, pickupClaim: null, pickupHub: null, pickupCodeUrl: null, orderNotices: [],
    };
    assert.match(await render(ui.Detail, 'Buyer/OrderDetail', props), /type="button"[^>]*>Buy again/);
    assert.doesNotMatch(await render(ui.Detail, 'Buyer/OrderDetail', { ...props, canUsePortal: false, buyAgainUrl: null }), />Buy again</);
});
