import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { buildSync } from 'esbuild';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createInertiaApp } from '@inertiajs/react';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-catalogue-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const bundle = buildSync({
    stdin: { contents: "export * from '@/utils/catalogueNavigation'; export { default as Pagination } from '@/Components/CataloguePagination'; export { default as Home } from '@/Pages/Buyer/Home'; export { default as Search } from '@/Pages/Buyer/Search';", resolveDir: resolve(import.meta.dirname, '../..'), loader: 'tsx' },
    bundle: true, packages: 'external', platform: 'node', format: 'esm', jsx: 'automatic', write: false,
});
const output = join(temporary, 'navigation.mjs');
writeFileSync(output, bundle.outputFiles[0].text);
const ui = await import(pathToFileURL(output).href);
globalThis.route = name => name ? `/${name.replaceAll('.', '/')}` : { current: () => false, has: () => true };
after(() => { delete globalThis.route; });

test('pagination retains path and filters on the browser origin despite TLS proxy differences', () => {
    assert.equal(ui.catalogueHref('http://bagoo.test:80/buyer/search?page=2&min_price=0', 'https://bagoo.test'), '/buyer/search?page=2&min_price=0');
    assert.equal(ui.catalogueHref('/?page=2', 'https://bagoo.test'), '/?page=2');
    for (const url of ['https://foreign.test/?page=2', 'javascript:alert(1)', 'http://user:secret@bagoo.test', '//foreign.test']) {
        assert.equal(ui.catalogueHref(url, 'https://bagoo.test'), undefined);
    }
});

test('filter changes reset pagination, preserve zero and supported values and normalize the old sort alias', () => {
    const filters = { search: 'Bag', min_price: '0', max_price: '100', category: 'apparel', in_stock: '1', rating: '4', sort: 'top_sales', page: 2, tab: 'top_sales' };
    const query = ui.catalogueFilters(filters, { search: undefined, sort: 'new_arrivals' });
    assert.deepEqual(query, { category: 'apparel', sort: 'newest', min_price: '0', max_price: '100', in_stock: '1', rating: '4' });
    assert.equal(ui.catalogueUrl('/buyer/search?page=9', filters, { search: "O'Bag %_!" }), '/buyer/search?category=apparel&in_stock=1&max_price=100&min_price=0&rating=4&search=O%27Bag+%25_%21&sort=top_sales');
});

test('an explicit page change reveals and focuses the changed grid without a decorative animation', () => {
    const calls = [];
    globalThis.document = { getElementById: id => ({ focus: options => calls.push([id, 'focus', options]), scrollIntoView: options => calls.push([id, 'scroll', options]) }) };
    try { ui.revealCatalogueResults(); } finally { delete globalThis.document; }
    assert.deepEqual(calls, [['catalogue-results', 'focus', { preventScroll: true }], ['catalogue-results', 'scroll', { block: 'start', behavior: 'auto' }]]);
});

async function render(page) {
    let view;
    const result = await createInertiaApp({
        page: { component: 'Buyer/Search', props: {}, url: '/buyer/search', version: null }, resolve: () => ui.Pagination,
        setup: ({ App, props }) => { view = createElement(App, { ...props, initialPage: { ...props.initialPage, props: { page } } }); return view; },
        render: () => renderToStaticMarkup(view),
    });
    return result.body;
}

test('page controls expose real disabled and current states and the correct product range', async () => {
    const html = await render({ current_page: 1, last_page: 2, per_page: 18, total: 25, links: [
        { url: null, label: '&laquo; Previous', active: false }, { url: '/?page=1', label: '1', active: true },
        { url: '/?page=2', label: '2', active: false }, { url: '/?page=2', label: 'Next &raquo;', active: false },
    ] });
    assert.match(html, /Showing 1–18 of 25 products/);
    assert.match(html, /<button[^>]*disabled=""[^>]*>Previous<\/button>/);
    assert.match(html, /aria-current="page"/);
    assert.match(html, /href="\/\?page=2"/);
    assert.doesNotMatch(html, /href="#"/);
    const empty = await render({ current_page: 1, last_page: 1, per_page: 18, total: 0, links: [] });
    assert.match(empty, /Showing 0–0 of 0 products/);
    assert.doesNotMatch(empty, /aria-label="Product pages"/);
});

test('the actual home and search pages render new product records and the active page from returned props', async () => {
    const product = (id, name) => ({ id, name, slug: `everyday-${id}`, price: 100, stock: 2, rating: null, shop: { name: 'Bagoo shop', city: 'Manila' } });
    for (const [component, Component, key, size] of [['Buyer/Home', ui.Home, 'feedProducts', 18], ['Buyer/Search', ui.Search, 'products', 24]]) {
        for (const number of [1, 2]) {
            const data = [product(number, `Page ${number} exclusive product`)];
            const products = { data, current_page: number, last_page: 2, per_page: size, total: 30, links: [
                { url: '/?page=1', label: '1', active: number === 1 }, { url: '/?page=2', label: '2', active: number === 2 },
            ] };
            let view;
            const result = await createInertiaApp({
                page: { component, url: `/?page=${number}`, version: null, props: { auth: { user: null }, flash: {}, cartCount: 0, banners: [], categories: [], filters: { search: 'Everyday' }, [key]: products, relatedProducts: [] } },
                resolve: () => Component, setup: ({ App, props }) => { view = createElement(App, props); return view; }, render: () => renderToStaticMarkup(view),
            });
            assert.match(result.body, new RegExp(`Page ${number} exclusive product`));
            assert.doesNotMatch(result.body, new RegExp(`Page ${number === 1 ? 2 : 1} exclusive product`));
            assert.match(result.body, new RegExp(`aria-current="page"[^>]*>${number}<`));
            assert.match(result.body, /id="catalogue-results"/);
            assert.match(result.body, /maxLength="100"|maxlength="100"/);
        }
    }
});

test('department links prepare the exact filtered search and reset only its page or category', async () => {
    let view;
    const result = await createInertiaApp({
        page: { component: 'Buyer/Search', url: '/buyer/search?page=2', version: null, props: {
            auth: { user: null }, flash: {}, cartCount: 0, categories: [{ id: 1, name: 'Pet Supplies', slug: 'pet-supplies', products_count: 4 }],
            filters: { search: 'Bag', min_price: '0', category: 'apparel', sort: 'newest' }, relatedProducts: [],
            products: { data: [], current_page: 2, last_page: 2, per_page: 24, total: 25, links: [] },
        } },
        resolve: () => ui.Search, setup: ({ App, props }) => { view = createElement(App, props); return view; }, render: () => renderToStaticMarkup(view),
    });
    const anchors = [...result.body.matchAll(/<a\b([^>]*)>([\s\S]*?)<\/a>/g)];
    const department = anchors.find(([, , body]) => body.includes('Pet Supplies'));
    const all = anchors.find(([, , body]) => body.includes('All Departments'));
    assert.ok(department); assert.ok(all);
    assert.match(department[1], /category=pet-supplies/);
    for (const anchor of [department, all]) {
        for (const query of ['min_price=0', 'search=Bag', 'sort=newest']) assert.ok(anchor[1].includes(query));
        assert.doesNotMatch(anchor[1], /page=2/);
    }
    assert.doesNotMatch(all[1], /category=/);
    assert.match(department[1], /focus-visible:outline/);
});
