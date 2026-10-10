import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { buildSync } from 'esbuild';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createInertiaApp } from '@inertiajs/react';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-commerce-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const bundle = buildSync({
    stdin: { contents: `export { default as ProductCard } from '@/Components/ProductCard';
        export { default as Product } from '@/Pages/Buyer/ProductDetail';
        export { default as Store } from '@/Pages/Marketplace/ShopDetail';
        export { default as Reviews } from '@/Pages/Seller/Reviews';
        export { default as Detail } from '@/Pages/Buyer/OrderDetail';
        export { default as Purchases } from '@/Pages/Buyer/Profile';
        export { default as RestrictedOrders } from '@/Pages/Buyer/Orders';
        export { default as SellerOrders } from '@/Pages/Seller/Orders';
        export { default as Dashboard } from '@/Pages/Seller/Dashboard';
        export { default as Inventory } from '@/Pages/Seller/Products';
        export { newListingStatus, sellerInventoryQuery, sellerVariantStock } from '@/utils/sellerInventory';`,
        resolveDir: resolve(import.meta.dirname, '../..'), loader: 'tsx' },
    bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', write: false,
    loader: { '.css': 'empty' },
});
const path = join(temporary, 'commerce.mjs');
writeFileSync(path, bundle.outputFiles[0].text);
const ui = await import(pathToFileURL(path).href);
globalThis.route = (name, parameter) => {
    if (!name) return { current: () => false, has: () => true };
    const suffix = parameter && typeof parameter === 'object' && !parameter.id ? `?${new URLSearchParams(parameter)}` : parameter ? `/${typeof parameter === 'object' ? parameter.id : parameter}` : '';
    return `/${name.replaceAll('.', '/')}${suffix}`;
};
after(() => delete globalThis.route);
const shop = { id: 5, name: 'Bagoo Crafts', slug: 'bagoo-crafts', user_id: 7, review_status: 'approved', city: 'Naval', rating: null, created_at: '2026-10-01' };
const product = { id: 3, name: 'Everyday Bag', slug: 'everyday-bag', price: '250.00', stock: 10, rating: null, verified_review_count: 0, sales_count: 0, shop, variants: {}, images: [] };
const summary = { rating: null, review_count: 0, response_rate: null, products_count: 1, joined: '2026-10-01' };
const paginated = data => ({ data, current_page: 1, last_page: 1, per_page: 12, total: data.length });
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

test('an unrated product card displays an honest empty state', () => {
    const html = renderToStaticMarkup(createElement(ui.ProductCard, { product }));
    assert.match(html, /No ratings yet/);
    assert.doesNotMatch(html, />5\.0</);
});
test('public product reviews distinguish legacy rows and render persisted seller replies', async () => {
    const html = await render(ui.Product, 'Buyer/ProductDetail', {
        product: { ...product, reviews: [
            { id: 1, rating: 4, verified_purchase: true, buyer: { name: 'Verified Buyer' }, comment: 'The bag arrived.', reply: { text: 'Thank you for your review.', shop_name: shop.name, created_at: '2026-10-01', updated_at: '2026-10-02' } },
            { id: 2, rating: 5, verified_purchase: false, buyer: { name: 'Older Buyer' }, comment: 'An older review.' },
        ] }, variations: { colors: [], sizes: [] }, relatedProducts: [], shopStats: summary,
    });
    assert.match(html, /Verified Purchase/);
    assert.match(html, /Unverified legacy review/);
    assert.match(html, /Thank you for your review\./);
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
    const html = await render(ui.Store, 'Marketplace/ShopDetail', { shop, products: paginated([product]), shopStats: summary });
    assert.match(html, /No ratings yet/);
    assert.match(html, /Review reply rate/);
    assert.match(html, /Shop opened/);
    assert.doesNotMatch(html, /4\.95|99%|98%|MALL PREFERRED|>MALL</);
});
test('completed order details hide review creation once all items have saved reviews', async () => {
    const html = await render(ui.Detail, 'Buyer/OrderDetail', {
        order: { id: 4, order_number: 'BGO-REVIEW', status: 'completed', payment_status: 'paid', total_amount: 250, subtotal: 250, shipping_fee: 0, created_at: '2026-10-01', items: [{ id: 10, product_id: product.id, quantity: 1, unit_price: 250, product, review: { id: 1, order_item_id: 10 } }] },
        canUsePortal: true, canConfirmReceipt: false, pickupClaim: null, pickupHub: null, pickupCodeUrl: null, orderNotices: [],
    });
    assert.match(html, /Review saved/);
    assert.doesNotMatch(html, /Rate &amp; Upload Photos/);
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
        orders: { ...paginated([{ ...order, status: 'delivered', can_confirm_receipt: true }, { ...order, id: 41, order_number: 'BGO-UNREADY', status: 'delivered', can_confirm_receipt: false }]), total: 4, last_page: 2, next_page_url: '/buyer/profile?tab=orders&order_status=delivered&page=2' },
        ordersCount: 42, orderCounts: counts, currentOrderStatus: 'delivered', initialTab: 'orders',
    });
    assert.match(html, /My Purchases &amp; Order Tracking/);
    assert.match(html, /Purchase pages/); assert.match(html, /Page 1 of 2/);
    assert.match(html, />42</); assert.equal((html.match(/Confirm Received/g) || []).length, 1);
    assert.match(html, /Disputes unavailable/); assert.doesNotMatch(html, /Report Defect/);
    for (const label of ['Delivered', 'Completed', 'Delivery issue', 'Returned', 'Cancelled']) assert.ok(html.includes(label));
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
    assert.match(html, /Post-delivery disputes remain unavailable\./);
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
