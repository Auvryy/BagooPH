import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { buildSync } from 'esbuild';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createInertiaApp } from '@inertiajs/react';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-sale-events-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const bundle = buildSync({
    stdin: { contents: `export { default as Campaign, countdownParts, voucherHeadline } from '@/Pages/Buyer/Campaign'; export { default as SellerEvent } from '@/Pages/Seller/SaleEvents/Show'; export { default as AdminEvents } from '@/Pages/Admin/SaleEvents/Index'; export { manilaInput } from '@/Components/Promotions/saleEvents';`, resolveDir: resolve(import.meta.dirname, '../..'), loader: 'tsx' },
    bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', loader: { '.css': 'empty' }, write: false,
});
const path = join(temporary, 'sale-events.mjs');
writeFileSync(path, bundle.outputFiles[0].text);
const ui = await import(pathToFileURL(path).href);
globalThis.route = (name) => name ? `/${name.replaceAll('.', '/')}` : { current: () => false, has: () => true };
after(() => delete globalThis.route);

async function render(Component, name, props, user = { id: 1, name: 'Ana Santos', role: 'buyer' }) {
    let view;
    const result = await createInertiaApp({
        page: { component: name, props: { auth: { user }, flash: {}, errors: {}, cartCount: 0, ...props }, url: '/campaign/payday', version: null },
        resolve: () => Component, setup: ({ App, props }) => { view = createElement(App, props); return view; }, render: () => renderToStaticMarkup(view),
    });
    return result.body;
}

const event = { id: 1, slug: 'payday', title: 'Payday Mega Sale', badge: 'PAYDAY DEAL', banner_url: '/images/payday.webp', status: 'live',
    starts_at: '2026-11-16T00:00:00Z', ends_at: '2026-11-17T01:02:03Z' };
const voucher = { id: 4, code: 'PAYDAY100', name: 'Payday', discount_type: 'fixed', discount_value: 100, min_spend: 699, max_discount: 100, shop_name: null,
    issuer: 'platform', expires_at: event.ends_at, claim_limit: 100, claimed_count: 100, sold_out: true, claimed: false };
const product = { sale_id: 9, id: 3, name: 'Wireless headphones', slug: 'wireless-headphones', image: null, regular_price: 1000, price: 750, discount_pct: 25,
    live: true, stock: 4, rating: null, sales_count: 2, shop: { id: 2, name: 'TechCore', slug: 'techcore' }, category: { id: 5, name: 'Electronics', slug: 'electronics' } };

test('countdown splits real remaining time and voucher headlines say what buyers save', () => {
    assert.deepEqual(ui.countdownParts(((26 * 60 + 2) * 60 + 3) * 1000), { days: 1, hours: 2, minutes: 2, seconds: 3 });
    assert.equal(ui.voucherHeadline({ discount_type: 'percent', discount_value: 15 }), '15% off');
    assert.equal(ui.voucherHeadline({ discount_type: 'free_shipping', discount_value: 50 }), '₱50 off shipping');
    assert.equal(ui.manilaInput('2026-11-16T16:30:00Z'), '2026-11-17T00:30');
});

test('the event page shows real prices, a sold-out limited voucher and no invented counts', async () => {
    const html = await render(ui.Campaign, 'Buyer/Campaign', { campaign: event, vouchers: [voucher, { ...voucher, id: 5, code: 'PAYDAY15', claim_limit: null, claimed_count: 3, sold_out: false, discount_type: 'percent', discount_value: 15 }],
        products: [product], categories: [product.category], allCampaigns: [{ slug: 'payday', title: event.title, badge: event.badge, status: 'live' }], serverNow: '2026-11-16T00:00:00Z' });
    assert.match(html, /Payday Mega Sale/);
    assert.match(html, /role="timer"/);
    assert.match(html, /₱750\.00[\s\S]*₱1,000\.00/);
    assert.match(html, /-25%/);
    assert.match(html, /All claimed/);
    assert.match(html, /href="\/product\/wireless-headphones"/);
    assert.doesNotMatch(html, /Claimed successfully|% Claimed|Seller Nominated/);
});

test('the admin list leads with one primary action and links each event', async () => {
    const html = await render(ui.AdminEvents, 'Admin/SaleEvents/Index', { events: { data: [{ ...event, vouchers_count: 2, products_count: 24, claims_count: 7 }], total: 1, prev_page_url: null, next_page_url: null },
        base: '/admin/sale-events' }, { id: 1, name: 'Admin', role: 'admin' });
    assert.match(html, /href="\/admin\/sale-events\/create"/);
    assert.match(html, /href="\/admin\/sale-events\/1"/);
    assert.match(html, />Live</);
});

test('the event shows its top three vouchers and keeps the rest behind Show all', async () => {
    const many = [1, 2, 3, 4, 5].map(id => ({ ...voucher, id, code: `CODE${id}`, sold_out: false, claim_limit: null }));
    const html = await render(ui.Campaign, 'Buyer/Campaign', { campaign: event, vouchers: many, products: [], categories: [], allCampaigns: [], serverNow: '2026-11-16T00:00:00Z' });
    for (const code of ['CODE1', 'CODE2', 'CODE3']) assert.match(html, new RegExp(code));
    assert.doesNotMatch(html, /CODE4|CODE5/);
    assert.match(html, /Show all 5 vouchers/);
    assert.match(html, /aria-expanded="false"/);
});
