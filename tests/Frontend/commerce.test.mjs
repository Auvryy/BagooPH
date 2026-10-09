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
        export { default as Detail } from '@/Pages/Buyer/OrderDetail';`,
        resolveDir: resolve(import.meta.dirname, '../..'), loader: 'tsx' },
    bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', write: false,
    loader: { '.css': 'empty' },
});
const path = join(temporary, 'commerce.mjs');
writeFileSync(path, bundle.outputFiles[0].text);
const ui = await import(pathToFileURL(path).href);
globalThis.route = (name, parameter) => name ? `/${name.replaceAll('.', '/')}${parameter ? `/${typeof parameter === 'object' ? parameter.id : parameter}` : ''}` : { current: () => false, has: () => true };
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
