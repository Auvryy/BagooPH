import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { buildSync } from 'esbuild';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createInertiaApp } from '@inertiajs/react';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-advanced-shopping-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const bundle = buildSync({ stdin: { contents: `export { default as Card } from '@/Components/ProductCard';
export { default as Segments } from '@/Components/PriceSegments';
export { default as Matrix } from '@/Components/VariantStockMatrix';
export { default as Sale } from '@/Components/ProductSaleOffer';
export { default as Sales } from '@/Pages/Seller/ProductSales';
export { saleCountdown } from '@/utils/saleTime';
export { default as ProductDetail } from '@/Pages/Buyer/ProductDetail';`, resolveDir: resolve(import.meta.dirname, '../..'), loader: 'tsx' },
    bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', write: false, loader: { '.css': 'empty' } });
const path = join(temporary, 'shopping.mjs'); writeFileSync(path, bundle.outputFiles[0].text);
const ui = await import(pathToFileURL(path).href);
globalThis.route = (name, parameter) => !name ? { current: () => false, has: () => true } : `/${name.replaceAll('.', '/')}/${typeof parameter === 'object' ? Object.values(parameter).join('/') : parameter ?? ''}`;
after(() => delete globalThis.route);
const now = '2026-10-19T02:00:00+08:00';
const slot = { id: 2, name: 'Bagoo morning sale', kind: 'flash', price: '299.00', starts_at: '2026-10-19T02:05:00+08:00', ends_at: '2026-10-19T02:30:00+08:00', remaining_quantity: 2 };
const shop = { id: 1, name: 'Bagoo Crafts', city: 'Taguig City', slug: 'bagoo-crafts' };
const product = { id: 1, name: 'Everyday Bag', slug: 'everyday-bag', stock: 10, price: '500.00', shop, status: 'active', sales_count: 0, rating: null,
    sale_offer: { effective_price: 299, server_now: now, active: slot, upcoming: null } };
async function render(Component, component, props, role = 'buyer') {
    let view;
    const result = await createInertiaApp({ page: { component, props: { auth: { user: { id: 3, name: 'Ana Santos', role, email: `${role}@bagoo.test`, status: 'active', kyc_status: 'approved' } }, flash: {}, cartCount: 0, ...props }, url: '/product/everyday-bag', version: null }, resolve: () => Component,
        setup: ({ App, props }) => { view = createElement(App, props); return view; }, render: () => renderToStaticMarkup(view) });
    return result.body;
}

test('sale cards show the actual discounted base and strike the actual regular price', () => {
    const html = renderToStaticMarkup(createElement(ui.Card, { product }));
    assert.match(html, /₱299\.00/);
    assert.match(html, /line-through[^>]*>\s*₱500\.00/);
    assert.doesNotMatch(html, /₱999/);
});
test('vacation listings remain links and disable the quick purchase action', () => {
    const html = renderToStaticMarkup(createElement(ui.Card, { product: { ...product, shop: { ...shop, vacation_mode: true } }, onQuickAdd: () => {} }));
    assert.match(html, /On vacation/);
    assert.match(html, /<a /);
    assert.match(html, /<button[^>]*\sdisabled(?:[\s=>])/);
});
test('mixed flash and ordinary quantities have distinct prices and quantities', () => {
    const html = renderToStaticMarkup(createElement(ui.Segments, { quantity: 3, unitPrice: 299, segments: [{ quantity: 1, unit_price: 299, subtotal: 299, sale_kind: 'flash' }, { quantity: 2, unit_price: 399, subtotal: 798, sale_kind: 'store' }], detailed: true }));
    assert.match(html, /1 × ₱299\.00/); assert.match(html, /2 × ₱399\.00/); assert.match(html, /₱798\.00/); assert.match(html, /Flash/);
    assert.doesNotMatch(html, /3 × ₱299\.00/);
});
test('matrix stock shows each pair independently and preserves sold out zero counts', () => {
    const html = renderToStaticMarkup(createElement(ui.Matrix, { colors: [{ name: 'Red' }, { name: 'Blue' }], sizes: [{ name: 'Large', extra_price: 0 }], value: [
        { color: 'Red', size: 'Large', stock: 0, extra_price: 5 }, { color: 'Blue', size: 'Large', stock: 4, extra_price: 10 }], onChange: () => {} }));
    assert.match(html, /Stock for Red \/ Large/); assert.match(html, /Stock for Blue \/ Large/);
    assert.match(html, /Combined stock: 4/); assert.match(html, /value="0"/);
});
test('countdowns handle boundaries and use the supplied server clock', () => {
    assert.equal(ui.saleCountdown(slot.ends_at, Date.parse(now)), '00:30:00');
    assert.equal(ui.saleCountdown(slot.ends_at, Date.parse(slot.ends_at)), 'Updating sale');
    assert.equal(ui.saleCountdown(slot.ends_at, Date.parse(slot.ends_at) + 1000), 'Updating sale');
    assert.equal(ui.saleCountdown('invalid', Date.parse(now)), 'Updating sale');
});
test('active sale panel shows real quota and explains split pricing', async () => {
    const html = await render(ui.Sale, 'Buyer/ProductDetail', { product });
    assert.match(html, /2 promotional units remain/); assert.match(html, /Ends in 00:30:00/);
    assert.match(html, /shown separately at checkout/); assert.doesNotMatch(html, /Remind me/);
});
test('upcoming sale offers an owned reminder and permits removing a recorded subscription', async () => {
    const upcoming = { ...product, sale_offer: { ...product.sale_offer, active: null, upcoming: slot } };
    const normal = await render(ui.Sale, 'Buyer/ProductDetail', { product: upcoming, reminder: { sale_id: 2, subscribed: false, notified: false } });
    assert.match(normal, /Remind me/); assert.match(normal, /10 minutes/);
    const subscribed = await render(ui.Sale, 'Buyer/ProductDetail', { product: upcoming, reminder: { sale_id: 2, subscribed: true, notified: true } });
    assert.match(subscribed, /Remove reminder/); assert.doesNotMatch(subscribed, /<button[^>]*\sdisabled(?:[\s=>])/);
});
test('seller sale page provides real settings and an honest empty state', async () => {
    const html = await render(ui.Sales, 'Seller/ProductSales', { shop, product, sales: { data: [], current_page: 1, last_page: 1, per_page: 20, total: 0, links: [] } }, 'seller');
    assert.match(html, /No sale scheduled/); assert.match(html, /Sale base price/); assert.match(html, /datetime-local/);
    assert.match(html, /Existing orders keep their original price/); assert.match(html, /Asia\/Manila/);
});

test('unselected matrix options prompt for a choice without pretending the listing is sold out', async () => {
    const variations = { colors: [{ id: 'red', name: 'Red', in_stock: true, hex: '#ff0000' }], sizes: [{ id: 'large', name: 'Large', stock: 0, extra_price: 999 }], combinations: [{ color: 'Red', size: 'Large', stock: 4, extra_price: 10 }] };
    const html = await render(ui.ProductDetail, 'Buyer/ProductDetail', { product: { ...product, variants: variations, images: [], reviews: [] }, variations, relatedProducts: [], cartQuantities: [], shopStats: { rating: null, products_count: 1, review_count: 0, response_rate: null, joined: null } });
    assert.match(html, /Choose an option/);
    assert.doesNotMatch(html, /This product selection is out of stock/);
    assert.doesNotMatch(html, /₱999\.00/);
    assert.match(html, /Add to Bag/);
});
