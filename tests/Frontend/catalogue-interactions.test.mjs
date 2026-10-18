import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build as buildBundle } from 'esbuild';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-catalogue-interactions-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const build = await buildBundle({
    stdin: { contents: "export { default as Search } from '@/hooks/useCatalogueSearch'; export { default as Link } from '@/Components/CatalogueLink'; export { default as Notice } from '@/Components/CatalogueNavigationNotice'; export { default as PortalLink } from '@/Components/PortalLink'; export { default as ResolvedPage } from '@/hooks/useResolvedPage'; export { default as Registry } from '@/Pages/Hub/Deliveries';", resolveDir: resolve(import.meta.dirname, '../..'), loader: 'tsx' },
    bundle: true, packages: 'external', platform: 'node', format: 'esm', jsx: 'automatic', write: false,
    plugins: [{ name: 'controlled-catalogue-interactions', setup(builder) {
        builder.onResolve({ filter: /^react$/ }, () => ({ path: 'hooks', namespace: 'catalogue-hooks' }));
        builder.onLoad({ filter: /.*/, namespace: 'catalogue-hooks' }, () => ({ loader: 'js', contents: `
            export const useState = initial => globalThis.catalogueHarness.state(initial);
            export const useRef = initial => globalThis.catalogueHarness.ref(initial);
            export const useEffect = (effect, deps) => globalThis.catalogueHarness.effect(effect, deps);
        ` }));
        builder.onResolve({ filter: /^@inertiajs\/react$/ }, () => ({ path: 'inertia', namespace: 'catalogue-inertia' }));
        builder.onLoad({ filter: /.*/, namespace: 'catalogue-inertia' }, () => ({ loader: 'js', contents: `
            export const Head = () => null; export const Link = 'a';
            export const usePage = () => globalThis.catalogueHarness.page;
            export const router = { on: (...args) => globalThis.catalogueHarness.on(...args), replace: options => globalThis.catalogueHarness.replacements.push(options) };
        ` }));
        builder.onResolve({ filter: /Layouts\/DashboardLayout$/ }, () => ({path:'layout',namespace:'catalogue-layout'}));
        builder.onLoad({filter:/.*/,namespace:'catalogue-layout'}, () => ({contents:'export default props => props.children;'}));
        builder.onResolve({ filter: /utils\/catalogueRuntime$/ }, () => ({ path: 'runtime', namespace: 'catalogue-runtime' }));
        builder.onLoad({ filter: /.*/, namespace: 'catalogue-runtime' }, () => ({ loader: 'js', contents: `
            export const cataloguePrefetch = {
                prepare: url => { globalThis.catalogueHarness.prepared.push(url); return Promise.resolve(true); },
                prioritize() {}, cancel: url => globalThis.catalogueHarness.cancelled.push(url)
            };
            export const preparePortal = url => { globalThis.catalogueHarness.prepared.push(url); return Promise.resolve(true); };
            export const visitPortal = (url, options = {}) => visitCatalogue(url, options);
            export const visitCatalogue = (url, options = {}) => {
                const harness = globalThis.catalogueHarness;
                harness.visits.push({ url, options });
                options.onCancelToken?.({ cancel: () => harness.cancelled.push(url) });
                return Promise.resolve();
            };
        ` }));
    } }],
});
const file = join(temporary, 'interactions.mjs'); writeFileSync(file, build.outputFiles[0].text);
const ui = await import(pathToFileURL(file).href);
function harness(t, component = ui.Search, props) {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const original = Object.fromEntries(['window', 'document', 'navigator', 'route'].map(key => [key, Object.getOwnPropertyDescriptor(globalThis, key)]));
    const window = Object.assign(new EventTarget(), { location: new URL('https://bagoo.test/buyer/search?search=Bag') });
    const document = Object.assign(new EventTarget(), { visibilityState: 'visible' });
    const navigator = { onLine: true };
    for (const [key, value] of Object.entries({ window, document, navigator, route: () => '/buyer/search' })) Object.defineProperty(globalThis, key, { value, configurable: true });
    const slots = [], effects = [], listeners = new Map(); let cursor = 0, queued = [];
    const h = {
        page: { component: 'Buyer/Search', url: '/buyer/search?search=Bag', props: { filters: { search: 'Bag', category: 'apparel', min_price: '0' } } },
        prepared: [], visits: [], cancelled: [], replacements: [], window, document, navigator,
        state(initial) { const index = cursor++; if (!(index in slots)) slots[index] = typeof initial === 'function' ? initial() : initial; return [slots[index], value => { slots[index] = typeof value === 'function' ? value(slots[index]) : value; }]; },
        ref(initial) { return this.state(() => ({ current: initial }))[0]; },
        effect(callback, deps) { const index = cursor++; const old = effects[index]; if (!old || !deps || deps.some((value, i) => !Object.is(value, old.deps[i]))) { queued.push(() => { old?.cleanup?.(); effects[index] = { deps, cleanup: callback() }; }); } },
        on(name, callback) { const list = listeners.get(name) ?? new Set(); list.add(callback); listeners.set(name, list); return () => list.delete(callback); },
        emit(name, detail) { for (const callback of listeners.get(name) ?? []) callback({ detail, preventDefault() { h.prevented = true; } }); },
        render() { cursor = 0; queued = []; let result = component(props); const pending = queued; queued = []; pending.forEach(effect => effect()); cursor = 0; return component(props); },
    };
    globalThis.catalogueHarness = h;
    t.after(() => {
        effects.forEach(effect => effect?.cleanup?.());
        delete globalThis.catalogueHarness;
        for (const [key, descriptor] of Object.entries(original)) {
            if (descriptor) Object.defineProperty(globalThis, key, descriptor); else delete globalThis[key];
        }
    });
    return h;
}

test('the header input synchronizes with Back/Forward props while preserving current filters', t => {
    const h = harness(t);
    let search = h.render(); assert.equal(search.value, 'Bag');
    search.update('Unsaved draft'); assert.equal(h.render().value, 'Unsaved draft');
    h.page = { ...h.page, url: '/buyer/search?search=Hat&page=2', props: { filters: { search: 'Hat', category: 'apparel', min_price: '0' } } };
    search = h.render(); assert.equal(search.value, 'Hat');
    search.update('Shoes'); t.mock.timers.tick(300);
    assert.equal(h.visits.at(-1).url, '/buyer/search?category=apparel&min_price=0&search=Shoes');
    assert.equal(h.visits.at(-1).options.preserveState, true);
});

test('a pagination or filter visit cancels scheduled typing rather than being replaced by it', t => {
    const h = harness(t); h.render().update('Hat');
    h.emit('before', { visit: { method: 'get', headers: {}, url: new URL('https://bagoo.test/buyer/search?search=Bag&page=2') } });
    t.mock.timers.tick(1_000); assert.equal(h.visits.length, 0);
});

test('offline typing preserves the query and explicit submit remains the recovery action', t => {
    const h = harness(t); h.render().update('Hat'); h.navigator.onLine = false;
    t.mock.timers.tick(300); assert.equal(h.visits.length, 0);
    let search = h.render(); assert.equal(search.value, 'Hat'); search.submit();
    assert.equal(h.visits.length, 1);
    h.emit('exception', { exception: new Error('Offline') });
    search = h.render(); assert.equal(search.state.status, 'error'); assert.equal(h.prevented, true);
    assert.equal(search.state.invalid, undefined);
    h.navigator.onLine = true; search.submit(); assert.equal(h.visits.length, 2);
});

test('clear uses the empty query immediately and retains category/price without stale input', t => {
    const h = harness(t); h.render().clear();
    assert.equal(h.render().value, '');
    assert.equal(h.visits.at(-1).url, '/buyer/search?category=apparel&min_price=0');
});

test('mouse/focus preparation respects the intent delay and a quick pass is cancelled', t => {
    const h = harness(t, ui.Link, { href: '/product/bag', children: 'Everyday bag' });
    let link = h.render();
    link.props.onPointerEnter({ pointerType: 'mouse' }); t.mock.timers.tick(149); assert.equal(h.prepared.length, 0);
    link.props.onPointerLeave({}); t.mock.timers.tick(10); assert.equal(h.prepared.length, 0);
    link.props.onFocus({}); t.mock.timers.tick(150); assert.deepEqual(h.prepared, ['/product/bag']);
    link = h.render(); link.props.onBlur({});
});

test('touch does not block scrolling and ordinary/modified link activations retain their meanings', t => {
    const h = harness(t, ui.Link, { href: '/product/bag', children: 'Everyday bag' });
    const link = h.render(); let prevented = 0;
    link.props.onTouchStart({ preventDefault: () => prevented++ });
    assert.equal(prevented, 0); assert.equal(h.prepared.length, 1);
    const event = { defaultPrevented: false, button: 0, preventDefault: () => prevented++ };
    link.props.onClick({ ...event, ctrlKey: true }); assert.equal(prevented, 0); assert.equal(h.visits.length, 0);
    link.props.onClick(event); assert.equal(prevented, 1); assert.equal(h.visits.length, 1);
});

test('navigation errors present a visible retry for the failed page and clear on another visit', t => {
    const h = harness(t, ui.Notice);
    assert.equal(h.render(), null);
    h.window.dispatchEvent(new CustomEvent('bagoo:catalogue-error', { detail: { href: '/product/bag' } }));
    const notice = h.render(); assert.equal(notice.props.role, 'alert');
    const retry = notice.props.children[0].props.children[1];
    retry.props.onClick(); assert.equal(h.visits.at(-1).url, '/product/bag'); assert.equal(h.render(), null);
    h.window.dispatchEvent(new CustomEvent('bagoo:catalogue-error', { detail: { href: 'https://foreign.test' } }));
    assert.equal(h.render(), null);
    h.window.dispatchEvent(new CustomEvent('bagoo:catalogue-error', { detail: { href: '/product/bag' } }));
    h.emit('before', { visit: { headers: {}, method: 'get' } }); assert.equal(h.render(), null);
});


test('portal links prepare code on deliberate focus or hover and cancel abandoned intent', t => {
    const props = { href: '/buyer/saved-products', children: 'Saved products' };
    const h = harness(t, ui.PortalLink, props);
    let link = h.render();
    link.props.onPointerEnter({ pointerType: 'mouse' }); t.mock.timers.tick(149);
    assert.equal(h.prepared.length, 0);
    link.props.onPointerLeave({}); t.mock.timers.tick(20); assert.equal(h.prepared.length, 0);
    link.props.onFocus({}); t.mock.timers.tick(150); assert.deepEqual(h.prepared, ['/buyer/saved-products']);
    link.props.onFocus({}); props.href = '/buyer/profile'; h.render(); t.mock.timers.tick(200);
    assert.equal(h.prepared.length, 1, 'an old destination cannot warm after its link changes');
    link = h.render(); link.props.onTouchStart({});
    assert.deepEqual(h.prepared, ['/buyer/saved-products', '/buyer/profile']);
});

test('portal links preserve modified clicks, other origins and downloads while guarding duplicate ordinary clicks', t => {
    const props = { href: '/buyer/saved-products', children: 'Saved products' };
    const h = harness(t, ui.PortalLink, props);
    const event = extra => ({ button: 0, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, ...extra });
    for (const extra of [{ ctrlKey: true }, { metaKey: true }, { shiftKey: true }, { button: 1 }]) {
        const click = event(extra); h.render().props.onClick(click); assert.equal(click.defaultPrevented, false);
    }
    props.href = 'https://foreign.test/buyer/profile'; const external = event(); h.render().props.onClick(external);
    assert.equal(external.defaultPrevented, false);
    props.href = '/buyer/saved-products'; props.download = 'saved.txt'; const download = event(); h.render().props.onClick(download);
    assert.equal(download.defaultPrevented, false);
    delete props.download;
    h.render().props.onClick(event()); h.render().props.onClick(event());
    assert.equal(h.visits.length, 1); assert.equal(h.render().props['aria-busy'], true);
    h.visits[0].options.onFinish({}); assert.equal(h.render().props['aria-busy'], undefined);
    h.render().props.onClick(event()); assert.equal(h.visits.length, 2);
});


test('an emptied last page corrects only the page parameter after the fresh server result', t => {
    const h = harness(t, () => ui.ResolvedPage(1));
    h.window.location = new URL('https://bagoo.test/buyer/saved-products?page=2&search=Bag');
    h.render();
    assert.deepEqual(h.replacements, [{ url: '/buyer/saved-products?page=1&search=Bag', preserveState: true, preserveScroll: true }]);
});

test('inactive order pagination cannot overwrite another profile section URL', t => {
    const h = harness(t, () => ui.ResolvedPage(1, false));
    h.window.location = new URL('https://bagoo.test/buyer/profile?tab=vouchers&page=2');
    h.render(); assert.equal(h.replacements.length, 0);
});


test('hub registry filters make one fresh coherent visit and retain useful input after a rejected read', t => {
    const props = {activeHub:{id:2}, deliveries:{data:[],current_page:1,last_page:1,total:0,links:[]},
        counts:{all:0,in_hub:0,out_for_delivery:0,ready_pickup:0,handovers_today:0}, filters:{search:'BGO',status:'in_hub',delivery_type:'doorstep'}, eligibleRiders:[],basePath:'/hub',canScan:false};
    const Registry = input => { const root=ui.Registry(input); return root.type(root.props); };
    const h = harness(t,Registry,props);
    const all = tree => !tree || typeof tree !== 'object' ? [] : [tree,...[tree.props?.children].flat(Infinity).flatMap(all)];
    let tree=h.render(); const form=all(tree).find(n=>n.type==='form');
    const input=all(tree).find(n=>n.type==='input'&&n.props['aria-label']==='Search parcels');
    assert.equal(input.props.maxLength,120); input.props.onChange({target:{value:'BGO-NEW'}}); tree=h.render();
    const submit=all(tree).find(n=>n.type==='form').props.onSubmit;
    submit({preventDefault(){}}); submit({preventDefault(){}});
    assert.equal(h.visits.length,1);
    const visit=h.visits[0]; const url=new URL(visit.url,'https://bagoo.test');
    assert.equal(url.searchParams.get('hub_id'),'2'); assert.equal(url.searchParams.get('search'),'BGO-NEW');
    assert.equal(url.searchParams.get('status'),'in_hub'); assert.equal(url.searchParams.get('delivery_type'),'doorstep');
    assert.equal(url.searchParams.has('page'),false);
    for (const prop of ['auth','activeHub','deliveries','counts','filters','eligibleRiders','canScan']) assert.ok(visit.options.only.includes(prop));
    visit.options.onError({search:'Please correct this search.'}); visit.options.onFinish();
    tree=h.render(); assert.ok(all(tree).some(n=>n.props?.role==='alert'&&n.props.children==='Please correct this search.'));
    assert.equal(all(tree).find(n=>n.type==='input'&&n.props['aria-label']==='Search parcels').props.value,'BGO-NEW');
    form.props.onSubmit({preventDefault(){}}); assert.equal(h.visits.length,2);
});
