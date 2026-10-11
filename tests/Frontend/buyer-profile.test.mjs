import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createInertiaApp, router } from '@inertiajs/react';
import postcss from 'postcss';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-buyer-profile-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const bundle = await build({
    stdin: { contents: `export { default as Profile } from '@/Pages/Buyer/Profile';
        export { default as Filters } from '@/Components/BuyerOrderFilters';
        export { setupBuyerProfileWorkspace } from '@/utils/buyerProfileWorkspace';`,
        resolveDir: resolve(import.meta.dirname, '../..'), loader: 'tsx' },
    bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', write: false,
    loader: { '.css': 'empty' },
    plugins: [{ name: 'capture-buyer-filters', setup(builder) {
        builder.onResolve({ filter: /BuyerOrderFilters$/ }, args => args.importer.endsWith('/Pages/Buyer/Profile.tsx')
            ? { path: 'capture-filters', namespace: 'capture-filters' } : undefined);
        builder.onLoad({ filter: /.*/, namespace: 'capture-filters' }, () => ({ contents: `
            import { createElement } from 'react';
            import Filters from '@/Components/BuyerOrderFilters';
            export default function Capture(props) { globalThis.buyerProfileFilters = props; return createElement(Filters, props); }
        `, resolveDir: resolve(import.meta.dirname, '../..') }));
    } }],
});
const path = join(temporary, 'buyer-profile.mjs');
writeFileSync(path, bundle.outputFiles[0].text);
const ui = await import(pathToFileURL(path).href);
globalThis.route = name => name ? `/${name.replaceAll('.', '/')}` : { current: () => false, has: () => true };
after(() => { delete globalThis.route; delete globalThis.buyerProfileFilters; });
const counts = { all: 45, to_ship: 13, in_transit: 9, delivered: 8, completed: 6, delivery_failed: 4, returned: 3, cancelled: 2 };
const user = { id: 7, name: 'Ana Santos', email: 'buyer@bagoo.test', email_verified_at: '2026-10-11T07:00:00Z', role: 'buyer', status: 'active', kyc_status: 'approved', phone: '+639171234567', birthday: null };
async function render(props = {}) {
    let view;
    const result = await createInertiaApp({
        page: { component: 'Buyer/Profile', props: { auth: { user }, flash: {}, cartCount: 0, user, addresses: [],
            wallet: { available: false, balance: null, currency: 'PHP', recent_transactions: [] },
            orders: { data: [], current_page: 1, last_page: 1, per_page: 12, total: 0 },
            orderCounts: counts, ordersCount: 45, currentOrderStatus: 'all', initialTab: 'orders', ...props },
            url: '/buyer/profile', version: null },
        resolve: () => ui.Profile,
        setup: ({ App, props }) => { view = createElement(App, props); return view; },
        render: () => renderToStaticMarkup(view),
    });
    return result.body;
}
function nodes(node, predicate) {
    if (!node || typeof node !== 'object') return [];
    const found = predicate(node) ? [node] : [];
    return found.concat([node.props?.children].flat(Infinity).flatMap(child => nodes(child, predicate)));
}

test('purchase progress stays in five visible buttons while exceptions have a separate labelled selector', async () => {
    const html = await render();
    const nav = html.match(/<nav aria-label="Purchase status"[\s\S]*?<\/nav>/)[0];
    assert.equal((nav.match(/<button\b/g) || []).length, 5);
    for (const label of ['All', 'To ship', 'In transit', 'Delivered', 'Completed']) assert.ok(nav.includes(label));
    assert.doesNotMatch(nav, /Delivery issue|Returned|Cancelled|overflow-x-auto/);
    assert.match(html, /<label[^>]*for="buyer-more-statuses"[^>]*>More statuses<\/label>/);
    for (const [status, count] of [['delivery_failed', 4], ['returned', 3], ['cancelled', 2]]) {
        assert.match(html, new RegExp(`<option value="${status}"[^>]*>[^<]+ \\(${count}\\)<`));
    }
    assert.ok(html.indexOf('buyer-more-statuses') < html.indexOf('aria-label="Purchase status"'));
});

test('direct exception links select the matching option and never mark a progress button active', async () => {
    for (const currentOrderStatus of ['delivery_failed', 'returned', 'cancelled']) {
        const html = await render({ currentOrderStatus });
        assert.match(html, new RegExp(`<option value="${currentOrderStatus}" selected="">`));
        const nav = html.match(/<nav aria-label="Purchase status"[\s\S]*?<\/nav>/)[0];
        assert.doesNotMatch(nav, /aria-pressed="true"/);
    }
});

test('the selector and progress buttons invoke existing status loads; controls disable together during an update', () => {
    const selections = []; let refreshed = false;
    const tree = ui.Filters({ selected: 'returned', counts, disabled: false, refreshing: false,
        onChange: value => selections.push(value), onRefresh: () => { refreshed = true; } });
    const select = nodes(tree, node => node.type === 'select')[0];
    select.props.onChange({ target: { value: 'cancelled' } });
    select.props.onChange({ target: { value: '' } });
    const buttons = nodes(tree, node => node.type === 'button');
    buttons[1].props.onClick();
    buttons[0].props.onClick();
    assert.deepEqual(selections, ['cancelled', 'all']);
    assert.equal(refreshed, true);
    const loading = ui.Filters({ selected: 'all', counts, disabled: true, refreshing: true, onChange() {}, onRefresh() {} });
    assert.ok(nodes(loading, node => node.type === 'button' || node.type === 'select').every(node => node.props.disabled));
    assert.match(renderToStaticMarkup(loading), /Refreshing…/);
});

test('profile filters reset the page and refresh retains the selected exception and current page', async () => {
    await render({ currentOrderStatus: 'returned', orders: { data: [], current_page: 2, last_page: 2, per_page: 12, total: 13 } });
    const original = router.get; const visits = [];
    router.get = (...args) => visits.push(args);
    try {
        globalThis.buyerProfileFilters.onChange('cancelled');
        globalThis.buyerProfileFilters.onRefresh();
        assert.equal(visits[0][0], '/buyer/profile');
        assert.deepEqual(visits[0][1], { tab: 'orders', order_status: 'cancelled' });
        assert.equal(visits[1][0], '/buyer/profile?page=2');
        assert.deepEqual(visits[1][1], { tab: 'orders', order_status: 'returned' });
        assert.equal(visits[1][2].preserveScroll, true);
        assert.ok(visits[1][2].only.includes('orderCounts'));
    } finally { router.get = original; }
});

test('purchase controls and sidebar sit outside the focusable results region; footer remains in the outer page', async () => {
    const html = await render();
    const results = html.indexOf('aria-label="Purchase results"');
    assert.ok(html.indexOf('buyer-profile-sidebar') < results);
    assert.ok(html.indexOf('aria-label="Purchase status"') < results);
    assert.ok(html.indexOf('Refresh tracking') < results);
    assert.match(html, /scroll-region="" role="region" aria-label="Purchase results" tabindex="0"/);
    assert.ok(html.indexOf('</main>') < html.indexOf('<footer'));
    assert.doesNotMatch(html, /h-screen overflow-hidden/);
});

test('Account and Security uses a separate scroll region and retains contact, identity and password settings', async () => {
    const html = await render({ initialTab: 'account' });
    const region = html.indexOf('aria-label="Account and security settings"');
    assert.ok(region > html.indexOf('buyer-profile-sidebar'));
    for (const label of ['Personal Information', 'Mobile Contact', 'Current Password', 'identity-correction']) {
        assert.ok(html.indexOf(label) > region, `${label} belongs in the account region`);
    }
    assert.doesNotMatch(html, /aria-label="Purchase results"/);
    assert.match(html, /aria-label="Account and security settings" tabindex="0"/);
});

test('bounded scrolling applies only to the desktop profile and never locks the footer or changes other portals', () => {
    const css = postcss.parse(readFileSync(resolve(import.meta.dirname, '../../resources/css/buyer-profile.css'), 'utf8'));
    css.walkRules(rule => {
        assert.equal(rule.parent.type, 'atrule');
        assert.equal(rule.parent.params, '(min-width: 1024px)');
        assert.ok(rule.selector.split(',').every(selector => selector.trim().startsWith('.buyer-profile-')));
    });
    const scrolling = [];
    css.walkDecls('overflow-y', declaration => scrolling.push(declaration.parent));
    assert.equal(scrolling.length, 1);
    assert.ok(scrolling[0].nodes.some(declaration => declaration.prop === 'overscroll-behavior' && declaration.value === 'contain'));
    assert.ok(scrolling[0].nodes.some(declaration => declaration.prop === 'min-height' && declaration.value === '0'));
});

function environment() {
    const originals = new Map(['window', 'Element', 'ResizeObserver'].map(key => [key, globalThis[key]]));
    const listeners = new Map(); const viewportListeners = new Map(); const wheelListeners = new Map();
    const frames = new Map(); const properties = new Map(); let frameId = 0; let observer;
    const main = {}; const desktop = { matches: true };
    class Element { closest() { return this.region; } }
    const workspace = { top: 240, getBoundingClientRect() { return { top: this.top }; }, closest: () => main,
        style: { setProperty: (key, value) => properties.set(key, value) },
        addEventListener: (type, listener, options) => { assert.equal(options.passive, false); wheelListeners.set(type, listener); },
        removeEventListener: type => wheelListeners.delete(type) };
    globalThis.Element = Element;
    globalThis.ResizeObserver = class { constructor(callback) { this.callback = callback; this.observed = []; observer = this; }
        observe(element) { this.observed.push(element); } disconnect() { this.disconnected = true; } };
    globalThis.window = { innerHeight: 900, scrollY: 0, visualViewport: { height: 900,
        addEventListener: (type, listener) => viewportListeners.set(type, listener), removeEventListener: type => viewportListeners.delete(type) },
        matchMedia: query => { assert.equal(query, '(min-width: 1024px)'); return desktop; },
        requestAnimationFrame: callback => { const id = ++frameId; frames.set(id, callback); return id; },
        cancelAnimationFrame: id => frames.delete(id),
        addEventListener: (type, listener) => listeners.set(type, listener), removeEventListener: type => listeners.delete(type) };
    return { workspace, desktop, properties, listeners, viewportListeners, wheelListeners, frames, get observer() { return observer; },
        flush() { const pending = [...frames.values()]; frames.clear(); pending.forEach(callback => callback()); },
        restore() { for (const [key, original] of originals) { if (original === undefined) delete globalThis[key]; else globalThis[key] = original; } } };
}

test('desktop wheel input over stationary or empty panels stays contained, while content and zoom retain native behavior', () => {
    const env = environment(); const cleanup = ui.setupBuyerProfileWorkspace(env.workspace);
    try {
        const wheel = (region, extra = {}) => {
            const target = new globalThis.Element(); target.region = region;
            let prevented = false;
            env.wheelListeners.get('wheel')({ target, ctrlKey: false, defaultPrevented: false, preventDefault: () => { prevented = true; }, ...extra });
            return prevented;
        };
        assert.equal(wheel(null), true);
        assert.equal(wheel({ scrollHeight: 320, clientHeight: 320 }), true);
        assert.equal(wheel({ scrollHeight: 1200, clientHeight: 320 }), false);
        assert.equal(wheel(null, { ctrlKey: true }), false);
        env.desktop.matches = false;
        assert.equal(wheel(null), false);
    } finally { cleanup(); env.restore(); }
});

test('workspace height follows resize and header changes without growing after outer-page scrolling; listeners clean up', () => {
    const env = environment(); const cleanup = ui.setupBuyerProfileWorkspace(env.workspace);
    try {
        assert.equal(env.properties.get('--buyer-profile-height'), '636px');
        assert.equal(env.observer.observed.length, 2);
        globalThis.window.scrollY = 200; env.workspace.top = 40;
        env.listeners.get('resize')(); env.observer.callback();
        assert.equal(env.frames.size, 1); env.flush();
        assert.equal(env.properties.get('--buyer-profile-height'), '636px');
        globalThis.window.visualViewport.height = 700;
        env.viewportListeners.get('resize')(); env.flush();
        assert.equal(env.properties.get('--buyer-profile-height'), '436px');
        env.workspace.top = 100; env.observer.callback(); env.flush();
        assert.equal(env.properties.get('--buyer-profile-height'), '376px');
        globalThis.window.visualViewport.height = 500;
        env.listeners.get('resize')(); env.flush();
        assert.equal(env.properties.get('--buyer-profile-height'), '320px');
        env.listeners.get('resize')(); cleanup();
        assert.equal(env.frames.size, 0);
        assert.equal(env.listeners.size + env.viewportListeners.size + env.wheelListeners.size, 0);
        assert.equal(env.observer.disconnected, true);
    } finally { cleanup(); env.restore(); }
});
