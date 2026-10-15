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
after(() => { rmSync(temporary, { recursive: true, force: true }); delete globalThis.route; delete globalThis.adTestHooks; delete globalThis.adFormData; delete globalThis.adReviewState; });
const bundle = await build({
    stdin: { contents: `export { default as Banners } from '@/Components/BuyerAdvertisements';
        export { default as Promotion } from '@/Pages/Buyer/Promotion';
        export { default as Terms } from '@/Components/AdvertisementOfferTerms';
        export { default as AdminList } from '@/Pages/Admin/Advertisements/Index';
        export { default as AdminEditor } from '@/Pages/Admin/Advertisements/Edit';
        export { default as Preview } from '@/Pages/Admin/Advertisements/Preview';
        export { default as Review } from '@/Pages/Admin/Advertisements/Review';
        export { default as SellerList } from '@/Pages/Seller/Promotions/Index';
        export { default as SellerEditor } from '@/Pages/Seller/Promotions/Edit';
        export { default as Performance } from '@/Pages/Shared/AdvertisementPerformance';
        export { default as SellerPreview } from '@/Pages/Seller/Promotions/Preview';`, resolveDir: root, loader: 'tsx' },
    bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', write: false, loader: { '.css': 'empty' },
    plugins: [{ name: 'advertisement-interaction-hooks', setup(builder) {
        builder.onResolve({ filter: /^react$/ }, args => ['ad-hooks', 'ad-review-hooks'].includes(args.namespace) ? { path: 'react', external: true }
            : args.importer.endsWith('/BuyerAdvertisements.tsx') ? { path: 'ad-hooks', namespace: 'ad-hooks' }
            : args.importer.endsWith('/Advertisements/Review.tsx') ? { path: 'ad-review-hooks', namespace: 'ad-review-hooks' } : undefined);
        builder.onLoad({ filter: /.*/, namespace: 'ad-hooks' }, () => ({ contents: `import React from 'react'; export * from 'react';
            export const useState = initial => globalThis.adTestHooks ? globalThis.adTestHooks.useState(initial) : React.useState(initial);
            export const useRef = initial => globalThis.adTestHooks ? globalThis.adTestHooks.useState(() => ({ current: initial }))[0] : React.useRef(initial);` }));
        builder.onLoad({ filter: /.*/, namespace: 'ad-review-hooks' }, () => ({ contents: `import React from 'react';
            export const useState = initial => React.useState(() => { const h = globalThis.adReviewState;
                const i = h ? h.index++ : -1; return h && Object.hasOwn(h.values, i) ? h.values[i] : typeof initial === 'function' ? initial() : initial; });` }));
        builder.onResolve({ filter: /^@inertiajs\/react$/ }, args => args.namespace === 'ad-forms' ? { path: '@inertiajs/react', external: true }
            : /\/(?:Advertisements|Promotions)\/Edit\.tsx$/.test(args.importer) ? { path: 'ad-forms', namespace: 'ad-forms' } : undefined);
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

const savedRevision = { id: 11, revision_number: 1, title: 'Reviewed shop artwork', alt_text: 'Actual submitted artwork', summary: 'The original submitted message.', purpose: 'feature',
    destination_type: 'product', destination_id: 1, product_ids: [], offer_revision_id: null, slot: null, starts_at: '2026-10-15T10:00', ends_at: '2026-10-16T10:00',
    image: '/private/artwork/11', image_bytes: 40000, width: 1880, height: 880, target: { label: 'Everyday bag', url: '/product/everyday-bag' }, offer: null, imported_candidate: false };
const submission = { id: 20, revision_id: 11, state: 'pending', submitted_at: '2026-10-15T02:00:00Z', revision: savedRevision, can_review: true, reviews: [] };
const sellerPromotion = { id: 4, version: 3, title: savedRevision.title, creator: 'Maria Santos', owner_type: 'seller', shop_name: 'Bagoo Crafts', target: savedRevision.target,
    state: 'draft', review_state: 'pending', has_draft: true, archived: false, draft: savedRevision, submissions: [submission], publications: [], history: page([]), can_submit_revision: false, can_stop: true };
const sellerOptions = { products: [{ id: 1, name: 'Everyday bag', shop_id: 3 }], shops: [{ id: 3, name: 'Bagoo Crafts' }], categories: [], offers: [] };
const sellerProps = promotion => ({ promotion, shop: { id: 3, name: 'Bagoo Crafts' }, canSubmit: true, options: sellerOptions, baseUrl: '/seller/promotions' });
const reviewProps = advertisement => ({ advertisement, slots: ['main_1', 'side_1'], displayEnabled: true, baseUrl: '/admin/advertisements' });

test('seller draft has the real upload limits and owned destinations without publication or position controls', async () => {
    const html = await render(ui.SellerEditor, sellerProps(null), 'seller');
    for (const text of ['Private draft', '5 MiB (5,242,880 bytes)', '47:22', 'Your product', 'Your shop offer', 'Requested start (Asia/Manila)', 'Save draft']) assert.ok(html.includes(text), text);
    assert.match(html, /accept="image\/jpeg,image\/png,image\/webp"/);
    assert.doesNotMatch(html, /<option value="main_1"|>Publish now<|type="url"/);
});
test('seller review and actual display state remain separate and duplicate submission is disabled', async () => {
    const html = await render(ui.SellerEditor, sellerProps(sellerPromotion), 'seller');
    assert.match(html, /Review: <strong>Under review<\/strong>/);
    assert.match(html, /Display: <strong>Not published<\/strong>/);
    assert.match(html.match(/<button\b[^>]*>Waiting for review<\/button>/)[0], /disabled=""/);
    assert.match(html, /Withdraw this version/);
});
test('a restricted shop can stop its owned promotion while new draft controls are disabled', async () => {
    const html = await render(ui.SellerEditor, { ...sellerProps(sellerPromotion), canSubmit: false }, 'seller');
    assert.match(html, /Your shop cannot submit new work/);
    assert.doesNotMatch(html.match(/<button\b[^>]*>Stop promotion<\/button>/)[0], /disabled=/);
    assert.match(html, /<fieldset disabled=""/);
});
test('seller stale forms keep entered values and block submitting a different saved version', async () => {
    globalThis.adFormData = { source_version: 2, title: 'My retained seller draft' };
    try {
        const html = await render(ui.SellerEditor, sellerProps({ ...sellerPromotion, review_state: 'not_submitted', can_submit_revision: true }), 'seller');
        assert.match(html, /The saved promotion changed/);
        assert.match(html, /value="My retained seller draft"/);
        assert.match(html.match(/<button\b[^>]*>Submit for review<\/button>/)[0], /disabled=""/);
    } finally { delete globalThis.adFormData; }
});
test('admin review displays the exact submitted artwork even when a newer private draft exists', async () => {
    const ad = { ...sellerPromotion, draft: { ...savedRevision, id: 12, title: 'Unsubmitted replacement', image: '/private/artwork/12' },
        review_state: 'not_submitted', submissions: [{ ...submission, state: 'approved', can_review: false }] };
    const html = await render(ui.Review, reviewProps(ad), 'admin');
    assert.match(html, /<img src="\/private\/artwork\/11"/);
    assert.doesNotMatch(html, /<img src="\/private\/artwork\/12"/);
    assert.match(html, /<h3[^>]*>Reviewed shop artwork<\/h3>/);
    assert.match(html, /Approval does not reserve a position/);
    assert.match(html, /Homepage placement/);
    assert.match(html.match(/<button\b[^>]*>Approve version<\/button>/)[0], /disabled=""/);
});
test('approval alone does not enable direct publication for a pending seller submission', async () => {
    const html = await render(ui.Review, reviewProps(sellerPromotion), 'admin');
    assert.match(html, /Approve version/);
    assert.doesNotMatch(html, /<h2[^>]*>Homepage placement<\/h2>|>Publish now</);
    assert.match(html.match(/<button\b[^>]*>Request changes<\/button>/)[0], /disabled=""/);
});
test('a stale review keeps the note and blocks approval even if the old confirmation was checked', async () => {
    globalThis.adReviewState = { index: 0, values: { 0: 2, 6: true, 7: 'My retained review note' } };
    try {
        const html = await render(ui.Review, reviewProps(sellerPromotion), 'admin');
        assert.match(html, /This promotion changed/);
        assert.match(html, /My retained review note/);
        assert.match(html, /type="checkbox"[^>]*checked=""/);
        assert.match(html.match(/<button\b[^>]*>Approve version<\/button>/)[0], /disabled=""/);
    } finally { delete globalThis.adReviewState; }
});
test('seller empty state is centered and explains that branding stays in Settings', async () => {
    const html = await render(ui.SellerList, { promotions: page([]), shop: { id: 3, name: 'Bagoo Crafts' }, available: true, canSubmit: true, baseUrl: '/seller/promotions', filters: {} }, 'seller');
    assert.match(html, /items-center justify-center/);
    assert.match(html, /No promotions found/);
    assert.match(html, /Shop logo, banner, description and contact edits remain in Settings/);
});
test('seller private preview uses the saved rendition and viewport controls without publishing a request', async () => {
    const html = await render(ui.SellerPreview, { promotionId: 4, revision: savedRevision, baseUrl: '/seller/promotions' }, 'seller');
    assert.match(html, /width:1140px/);
    assert.match(html, /Preview width/);
    assert.match(html, /This preview does not submit or publish/);
    assert.match(html, /src="\/private\/artwork\/11"/);
    assert.doesNotMatch(html, /href="\/advertisement\//);
});

test('changed reviewed shop details prevent reuse of a retained approval for placement', async () => {
    const ad = { ...sellerPromotion, submissions: [{ ...submission, state: 'approved', can_review: false, context_current: false,
        shop_scope: { name: 'Original Bagoo shop', root_category_name: 'Pet Supplies' } }] };
    const html = await render(ui.Review, reviewProps(ad), 'admin');
    assert.match(html, /Submitted shop: Original Bagoo shop/);
    assert.match(html, /The reviewed shop details changed/);
    assert.match(html.match(/<button\b[^>]*>Publish now<\/button>/)[0], /disabled=""/);
});

const performanceProps = {
    available: true, collecting: true, seller: false, baseUrl: '/admin/advertisements',
    filters: { from: '2026-10-01', to: '2026-10-15', advertisement_id: null, revision_id: null, publication_id: null },
    dateLimits: { minimum: '2025-10-15', maximum: '2026-10-15' }, totals: { impressions: 0, clicks: 0, click_through_rate: null },
    options: { advertisements: [], revisions: [], placements: [] }, placements: page([]),
};
test('performance distinguishes recorded zero, unavailable rates and unavailable storage', async () => {
    let html = await render(ui.Performance, performanceProps, 'admin');
    assert.match(html, /Recorded views/); assert.match(html, /Image clicks/);
    assert.match(html, /Click-through rate<\/dt><dd[^>]*>—<\/dd>/);
    assert.match(html, /No placements found/); assert.doesNotMatch(html, /NaN|Infinity|Estimated revenue/);
    html = await render(ui.Performance, { ...performanceProps, available: false, totals: null, placements: null, failureReference: 'test-report-reference' }, 'admin');
    assert.match(html, /Reporting unavailable/); assert.match(html, /test-report-reference/); assert.doesNotMatch(html, /Recorded views|<table/);
});
test('retained results keep exact revision, placement and period with collection paused', async () => {
    const placement = { id: 8, advertisement_id: 4, revision_id: 11, revision_number: 2, title: 'Reviewed everyday essentials',
        owner: 'Seller', creator: 'Shop owner', purpose: 'feature', slot: 'side_1', state: 'ended', starts_at: '2026-10-14T02:00:00Z', ends_at: '2026-10-15T02:00:00Z',
        impressions: 12, clicks: 3, click_through_rate: 25 };
    const html = await render(ui.Performance, { ...performanceProps, collecting: false, totals: placement, placements: page([placement]) }, 'admin');
    for (const text of ['New activity is not being recorded', 'Reviewed everyday essentials', 'Revision 2', 'Side image 1', '#8', '25.00%', 'Manila', 'Shop owner']) assert.ok(html.includes(text), text);
    assert.match(html, /href="\/admin\/advertisements\/4"/);
});
test('seller performance stays in its own portal and filters revision and placement choices to the selected records', async () => {
    const options = { advertisements: [{ id: 4, title: 'Own campaign' }], revisions: [{ id: 11, advertisement_id: 4, title: 'Owned revision' }, { id: 12, advertisement_id: 9, title: 'Different campaign revision' }],
        placements: [{ id: 8, advertisement_id: 4, revision_id: 11, title: 'Owned placement' }, { id: 9, advertisement_id: 4, revision_id: 12, title: 'Other revision placement' }] };
    const html = await render(ui.Performance, { ...performanceProps, seller: true, baseUrl: '/seller/promotions', options,
        filters: { ...performanceProps.filters, advertisement_id: 4, revision_id: 11 } }, 'seller');
    assert.match(html, /Promotion performance/); assert.match(html, /href="\/seller\/promotions"/);
    assert.match(html, /Owned revision/); assert.match(html, /Owned placement/);
    assert.doesNotMatch(html, /Different campaign revision|Other revision placement|\/admin\/advertisements/);
    assert.match(html, /type="date"[^>]*min="2025-10-15"[^>]*max="2026-10-15"/);
    assert.match(html, /up to 90 days/);
});
test('carousel controls send no click while an image activation is recorded once without delaying its link', async () => {
    const previousDoc = globalThis.document;
    const previousFetch = globalThis.fetch;
    const requests = [];
    globalThis.document = { querySelector: () => ({ content: 'test-csrf' }), cookie: '' };
    globalThis.fetch = async (url, request) => { requests.push({ url, request }); return { ok: true }; };
    try {
        const measured = banners.slice(0, 3).map(item => ({ ...item, measurement: { token: `test-token-${item.id}`, revision_id: item.id, endpoint: '/advertisement-events' } }));
        const draw = interactions({ banners: measured });
        let tree = draw();
        nodes(tree, node => node.props['aria-label'] === 'Next advertisement')[0].props.onClick();
        assert.equal(requests.length, 0);
        tree = draw();
        const picture = nodes(tree, node => node.props.href === measured[1].href && typeof node.props.onClick === 'function')[0];
        assert.equal(picture.props.onClick(), undefined);
        picture.props.onClick();
        await new Promise(resolve => setImmediate(resolve));
        assert.equal(requests.length, 1);
        assert.deepEqual(JSON.parse(requests[0].request.body), { token: 'test-token-2', type: 'click' });
        assert.equal(picture.props.href, '/advertisement/collection-2?publication=2');
    } finally {
        if (previousDoc === undefined) delete globalThis.document; else globalThis.document = previousDoc;
        globalThis.fetch = previousFetch;
    }
});
