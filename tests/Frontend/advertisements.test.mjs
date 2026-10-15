import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';
import { createElement, isValidElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createInertiaApp } from '@inertiajs/react';

const root = resolve(import.meta.dirname, '../..');
const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-advertisements-'));
after(() => { rmSync(temporary, { recursive: true, force: true }); delete globalThis.route; delete globalThis.adTestHooks; delete globalThis.adFormData; });
const bundle = await build({
    stdin: { contents: `export { default as Banners } from '@/Components/BuyerAdvertisements';
        export { default as Promotion } from '@/Pages/Buyer/Promotion';
        export { default as Terms } from '@/Components/AdvertisementOfferTerms';
        export { default as AdminList } from '@/Pages/Admin/Advertisements/Index';
        export { default as AdminEditor } from '@/Pages/Admin/Advertisements/Edit';
        export { default as Preview } from '@/Pages/Admin/Advertisements/Preview';`, resolveDir: root, loader: 'tsx' },
    bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', write: false, loader: { '.css': 'empty' },
    plugins: [{ name: 'advertisement-interaction-hooks', setup(builder) {
        builder.onResolve({ filter: /^react$/ }, args => args.namespace === 'ad-hooks' ? { path: 'react', external: true }
            : args.importer.endsWith('/BuyerAdvertisements.tsx') ? { path: 'ad-hooks', namespace: 'ad-hooks' } : undefined);
        builder.onLoad({ filter: /.*/, namespace: 'ad-hooks' }, () => ({ contents: `import React from 'react';
            export const useState = initial => globalThis.adTestHooks ? globalThis.adTestHooks.useState(initial) : React.useState(initial);` }));
        builder.onResolve({ filter: /^@inertiajs\/react$/ }, args => args.namespace === 'ad-forms' ? { path: '@inertiajs/react', external: true }
            : args.importer.endsWith('/Advertisements/Edit.tsx') ? { path: 'ad-forms', namespace: 'ad-forms' } : undefined);
        builder.onLoad({ filter: /.*/, namespace: 'ad-forms' }, () => ({ contents: `export * from '@inertiajs/react';
            import { useForm as realForm } from '@inertiajs/react';
            export const useForm = initial => { const form = realForm(initial); return globalThis.adFormData ? { ...form, data: { ...form.data, ...globalThis.adFormData } } : form; };` }));
    } }],
});
const modulePath = join(temporary, 'advertisements.mjs');
writeFileSync(modulePath, bundle.outputFiles[0].text);
const ui = await import(pathToFileURL(modulePath).href);
globalThis.route = (name, parameter) => name ? `/${name.replaceAll('.', '/')}${typeof parameter === 'string' ? `/${parameter}` : parameter?.tab ? `?tab=${parameter.tab}` : ''}` : { current: () => false, has: () => true };

async function render(Component, props, role = 'buyer') {
    let view;
    const response = await createInertiaApp({
        page: { component: 'Advertisements', props: { auth: { user: role ? { id: 7, name: 'Maria Santos', role, email: `${role}@bagoo.test` } : null }, flash: {}, errors: {}, cartCount: 0, ...props }, url: '/', version: null },
        resolve: () => Component, setup: ({ App, props }) => { view = createElement(App, props); return view; }, render: () => renderToStaticMarkup(view),
    });
    return response.body;
}
const banner = (id, placement = 'main') => ({ id, placement, slot: `${placement}_${id}`, title: `Bagoo collection ${id}`, alt_text: `Artwork for collection ${id}`, image: `/artwork/${id}.webp`, width: 1880, height: 880, href: `/advertisement/collection-${id}?publication=${id}` });
const banners = [banner(1), banner(2), banner(3), banner(4, 'side'), banner(5, 'side')];
const offer = { code: 'BAGOO_SHIP', name: 'Bagoo delivery savings', description: 'A real shipping discount.', issuer_type: 'platform', shop_name: null,
    discount_type: 'free_shipping', value_cents: 5000, percentage_bps: null, minimum_cents: 10000, maximum_cents: 3000, per_buyer_limit: 2,
    starts_at: '2026-10-15T02:00:00Z', ends_at: '2026-10-16T02:00:00Z' };
const promotion = { title: 'Everyday collection', summary: 'Browse everyday essentials.', available: true, state: 'live', image: '/artwork/1.webp', alt_text: 'Everyday essentials', width: 1880, height: 880,
    starts_at: offer.starts_at, ends_at: offer.ends_at, offer: null, target: { label: 'Matching product collection', url: null } };
const product = { id: 1, slug: 'everyday-bag', name: 'Everyday bag', price: 500, stock: 5, featured_image: null, rating: null, shop: { name: 'Bagoo shop', city: 'Manila' } };
const page = data => ({ data, current_page: 1, last_page: 1, total: data.length, prev_page_url: null, next_page_url: null });

test('empty placements leave no promotional panel', async () => {
    const html = await render(ui.Banners, { banners: [] });
    assert.doesNotMatch(html, /<section|<img|Featured advertisements/);
});
test('full grid keeps overlay controls and matching real destinations without caption panels', async () => {
    const html = await render(ui.Banners, { banners });
    assert.equal((html.match(/<img /g) ?? []).length, 3);
    assert.match(html, /lg:grid-cols-\[minmax\(0,2fr\)_minmax\(0,1fr\)\]/);
    assert.match(html, /href="\/advertisement\/collection-1\?publication=1"/);
    assert.match(html, /href="\/advertisement\/collection-4\?publication=4"/);
    assert.match(html, /aria-label="Next advertisement" class="absolute right-2 top-1\/2/);
    assert.match(html, /alt="Artwork for collection 1" width="1880" height="880"/);
    assert.doesNotMatch(html, /href="#product-feed"|<figcaption|animate-|setInterval/);
});
test('one main image stays bounded and has no carousel controls', async () => {
    const html = await render(ui.Banners, { banners: [banner(1)] });
    assert.match(html, /max-w-3xl/);
    assert.doesNotMatch(html, /Previous advertisement|Next advertisement|Choose an advertisement/);
});
test('side-only content is visible with a compact layout', async () => {
    const html = await render(ui.Banners, { banners: [banner(1, 'side')] });
    assert.equal((html.match(/<img /g) ?? []).length, 1);
    assert.match(html, /max-w-md/);
    assert.doesNotMatch(html, /aria-roledescription="carousel"/);
});

function nodes(tree, predicate) {
    if (Array.isArray(tree)) return tree.flatMap(child => nodes(child, predicate));
    if (!isValidElement(tree)) return [];
    return [...(predicate(tree) ? [tree] : []), ...nodes(tree.props.children, predicate)];
}
function interactions(props) {
    const values = [];
    let cursor = 0;
    const hooks = { useState(initial) { const i = cursor++; if (!(i in values)) values[i] = typeof initial === 'function' ? initial() : initial;
        return [values[i], update => { values[i] = typeof update === 'function' ? update(values[i]) : update; }]; } };
    return () => { cursor = 0; globalThis.adTestHooks = hooks; try { return ui.Banners(props); } finally { delete globalThis.adTestHooks; } };
}
test('arrows change the actual image and broken images are removed until the section is empty', () => {
    const draw = interactions({ banners: [banner(1), banner(2), banner(3)] });
    let tree = draw();
    nodes(tree, node => node.props['aria-label'] === 'Next advertisement')[0].props.onClick();
    tree = draw();
    assert.equal(nodes(tree, node => node.type === 'img')[0].props.src, '/artwork/2.webp');
    for (let i = 0; i < 3; i++) { nodes(tree, node => node.type === 'img')[0].props.onError(); tree = draw(); }
    assert.equal(tree, null);
});
test('private preview opens its selected draft and does not render buyer links', async () => {
    const html = await render(ui.Banners, { banners, interactive: false, initialSelectedId: 2 });
    assert.match(html, /src="\/artwork\/2.webp"/);
    assert.doesNotMatch(html, /<a /);
    const css = readFileSync(join(root, 'resources/css/advertisement-preview.css'), 'utf8');
    assert.match(css, /@container \(min-width: 1024px\)/);
});
test('shipping terms state the cap, minimum and remaining-fee rule without automatic application', async () => {
    const html = await render(ui.Terms, { offer });
    for (const text of ['Shipping discount up to ₱30.00', '₱100.00', 'Up to 2', 'BAGOO_SHIP', 'checked again at checkout', 'remaining shipping charge']) assert.ok(html.includes(text), text);
    assert.doesNotMatch(html, />Free shipping</);
});
test('a guest sees matching collection products with product links and no unsupported delivery badge', async () => {
    const html = await render(ui.Promotion, { promotion, products: [product], categories: [] }, null);
    assert.match(html, /Products in this promotion/);
    assert.match(html, /href="\/products\/show\/everyday-bag"/);
    assert.doesNotMatch(html, /Free Delivery|Add to Bag|Claimed successfully/);
});
test('ended promotions give a useful centered next step without offer or product controls', async () => {
    const html = await render(ui.Promotion, { promotion: { ...promotion, available: false, image: null }, products: [], categories: [] }, null);
    assert.match(html, /items-center justify-center/);
    assert.match(html, /This promotion is no longer available/);
    assert.match(html, /Browse products/);
    assert.doesNotMatch(html, /Offer terms|Products in this promotion|View my vouchers/);
});
test('an offer page directs guests to the existing owned voucher flow', async () => {
    const html = await render(ui.Promotion, { promotion: { ...promotion, offer }, products: [product], categories: [] }, null);
    assert.match(html, /Sign in to save a voucher/);
    assert.match(html, /href="\/buyer\/profile\?tab=vouchers"/);
});
test('admin empty and disabled states explain the display behavior without invented statistics', async () => {
    const html = await render(ui.AdminList, { advertisements: page([]), available: true, displayEnabled: false, baseUrl: '/admin/advertisements', filters: {}, suppliedArtwork: [] }, 'admin');
    assert.match(html, /No advertisements found/);
    assert.match(html, /Public advertisement display is disabled/);
    assert.match(html, /New advertisement/);
    assert.doesNotMatch(html, /Impressions|Click-through|Estimated revenue/);
});
test('admin creation shows exact upload limits and typed destinations without arbitrary URL entry', async () => {
    const html = await render(ui.AdminEditor, { advertisement: null, options: { products: [{ id: 1, name: 'Everyday bag' }], shops: [], categories: [], offers: [] }, slots: ['main_1', 'side_1'], displayEnabled: true, baseUrl: '/admin/advertisements' }, 'admin');
    for (const text of ['5 MiB (5,242,880 bytes)', '940 × 440', '47:22', '180 characters', 'Click destination', 'Asia/Manila', 'Save draft']) assert.ok(html.includes(text), text);
    assert.match(html, /accept="image\/jpeg,image\/png,image\/webp"/);
    assert.doesNotMatch(html, /type="url"|name="destination_url"|>Publish now</);
});

test('a stale admin editor retains entered details but requires reload before approving changed content', async () => {
    const advertisement = { id: 1, version: 2, title: 'Saved feature', state: 'draft', has_draft: true, archived: false,
        publications: [], history: page([]), draft: { id: 2, title: 'Saved feature', alt_text: 'Saved artwork', summary: null,
            purpose: 'feature', destination_type: 'product', destination_id: 1, product_ids: [], offer_revision_id: null, slot: 'main_1',
            starts_at: '2026-10-15T10:00', ends_at: '2026-10-16T10:00', image: '/private/artwork/2', image_bytes: 40000, width: 1880, height: 880,
            target: { label: 'Everyday bag' }, imported_candidate: false } };
    globalThis.adFormData = { source_version: 1, title: 'My retained title' };
    try {
        const html = await render(ui.AdminEditor, { advertisement, options: { products: [{ id: 1, name: 'Everyday bag' }], shops: [], categories: [], offers: [] }, slots: ['main_1'], displayEnabled: true, baseUrl: '/admin/advertisements' }, 'admin');
        assert.match(html, /The saved advertisement changed/);
        assert.match(html, /value="My retained title"/);
        assert.match(html.match(/<button\b[^>]*>Publish now<\/button>/)[0], /disabled=""/);
        assert.match(html.match(/<button\b[^>]*>Schedule<\/button>/)[0], /disabled=""/);
    } finally { delete globalThis.adFormData; }
});
