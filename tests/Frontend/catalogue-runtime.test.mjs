import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { buildSync } from 'esbuild';
import { router } from '@inertiajs/react';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-catalogue-runtime-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const build = buildSync({
    stdin: { contents: "export * from '@/utils/catalogueRuntime'; export { SupersededCatalogueVisit } from '@/utils/catalogueNavigation';", resolveDir: resolve(import.meta.dirname, '../..'), loader: 'tsx' },
    bundle: true, packages: 'external', platform: 'node', format: 'esm', write: false,
});
const file = join(temporary, 'runtime.mjs'); writeFileSync(file, build.outputFiles[0].text);
const ui = await import(pathToFileURL(file).href);
const tick = async () => { for (let i = 0; i < 8; i++) await Promise.resolve(); };
const page = { version: 'build-one', component: 'Buyer/ProductDetail', props: { cataloguePrefetchEnabled: true, auth: { user: { id: 7, role: 'buyer', status: 'active', kyc_status: 'approved' } }, errors: {}, flash: {} } };

test('the runtime bridges cached and fresh visits, cancellation and session boundaries', async t => {
    const original = Object.fromEntries(['prefetch', 'getPrefetching', 'getCached', 'flush', 'resolveComponent', 'visit', 'on'].map(key => [key, router[key]]));
    const descriptors = Object.fromEntries(['window', 'document', 'navigator', 'BroadcastChannel'].map(key => [key, Object.getOwnPropertyDescriptor(globalThis, key)]));
    const events = new Map(), requests = [], visits = [], flushed = [], components = [], notices = [];
    let warmGate, prevented = 0;
    const window = Object.assign(new EventTarget(), { location: new URL('https://bagoo.test/') });
    const document = Object.assign(new EventTarget(), { visibilityState: 'visible' });
    let channel;
    class Channel extends EventTarget { constructor() { super(); channel = this; } postMessage(message) { this.lastMessage = message; } }
    for (const [key, value] of Object.entries({ window, document, navigator: { onLine: true, connection: { effectiveType: '4g' } }, BroadcastChannel: Channel })) Object.defineProperty(globalThis, key, { value, configurable: true });
    const emit = (name, detail) => (events.get(name) ?? []).forEach(callback => callback({ detail, preventDefault: () => prevented++ }));
    window.addEventListener('bagoo:catalogue-error', event => notices.push(event.detail.href));
    router.on = (name, callback) => { events.set(name, [...events.get(name) ?? [], callback]); return () => {}; };
    router.prefetch = (url, options, cache) => {
        let finish;
        const response = new Promise(resolve => { finish = resolve; });
        const entry = { url, options, cache, response, cancelled: false,
            complete(data = page, status = 200) { options.onPrefetched({ status, data: JSON.stringify(data) }); finish(); },
        };
        requests.push(entry);
        options.onCancelToken({ cancel: () => { entry.cancelled = true; finish(); } });
    };
    const find = (url, options) => requests.findLast(row => !row.cancelled && row.url === url && row.options.headers['X-Bagoo-Catalogue-Context'] === options.headers['X-Bagoo-Catalogue-Context']);
    router.getPrefetching = find; router.getCached = () => null;
    router.flush = (url, options) => flushed.push({ url, options });
    router.resolveComponent = async component => { components.push(component); await warmGate; };
    router.visit = (url, options) => {
        visits.push({ url, options });
        const visit = { url: new URL(url, window.location.origin), method: 'get', headers: options.headers ?? {} };
        emit('before', { visit });
        // Inertia replays per-visit callbacks on a cache hit without a new HTTP lifecycle.
        if (options.fresh === false) options.onFinish?.(visit);
    };
    t.after(() => {
        ui.cataloguePrefetch.clear();
        Object.assign(router, original);
        for (const [key, descriptor] of Object.entries(descriptors)) {
            if (descriptor) Object.defineProperty(globalThis, key, descriptor); else delete globalThis[key];
        }
    });
    ui.installCatalogueNavigation(page);

    await t.test('a matching pending page is consumed with identical headers and canonical query order', async () => {
        const work = ui.cataloguePrefetch.prepare('/buyer/product/bag?b=2&a=1'); await tick();
        const visit = ui.visitCatalogue('/buyer/product/bag?b=2&a=1'); await tick();
        assert.equal(visits.length, 0); assert.equal(requests.length, 1);
        requests[0].complete(); await work; await visit;
        assert.equal(visits.length, 1); assert.equal(visits[0].options.fresh, false);
        assert.equal(visits[0].url, '/buyer/product/bag?a=1&b=2');
        assert.equal(requests[0].options.headers.Purpose, 'prefetch');
        assert.equal(visits[0].options.headers.Purpose, undefined);
        assert.equal(visits[0].options.headers['X-Bagoo-Catalogue-Context'], requests[0].options.headers['X-Bagoo-Catalogue-Context']);
        assert.equal(requests[0].cache.cacheFor, 10_000);
        assert.deepEqual(components, ['Buyer/ProductDetail']);
        assert.equal(ui.cataloguePrefetch.allowed(), true, 'cached callback releases the request budget');
    });
    await t.test('rejected/redirected or changed-account responses fall back to an actual fresh visit', async () => {
        for (const response of [{ ...page, component: 'Auth/Login' }, { ...page, props: { ...page.props, auth: { user: { id: 8, role: 'buyer' } } } }]) {
            ui.cataloguePrefetch.clear();
            const work = ui.cataloguePrefetch.prepare('/product/rejected'); await tick();
            requests.at(-1).complete(response); assert.equal(await work, false);
            await ui.visitCatalogue('/product/rejected');
            assert.equal(visits.at(-1).options.fresh, true);
            visits.at(-1).options.onFinish({});
        }
    });
    await t.test('a cancelled search waiting on preparation never sends its obsolete foreground visit', async () => {
        ui.cataloguePrefetch.clear();
        const work = ui.cataloguePrefetch.prepare('/product/cancelled'); await tick();
        let cancel;
        const before = visits.length;
        const navigation = ui.visitCatalogue('/product/cancelled', { onCancelToken: token => { cancel = token.cancel; } });
        cancel(); requests.at(-1).complete(); await work; await navigation;
        assert.equal(visits.length, before);
        assert.equal(ui.cataloguePrefetch.allowed(), true);
    });
    await t.test('slow page-code loading remains cancellable after the data is fetched', async () => {
        ui.cataloguePrefetch.clear(); let loaded;
        warmGate = new Promise(resolve => { loaded = resolve; });
        const work = ui.cataloguePrefetch.prepare('/product/slow-code'); await tick();
        requests.at(-1).complete(); await tick();
        let cancel; const before = visits.length;
        const navigation = ui.visitCatalogue('/product/slow-code', { onCancelToken: token => { cancel = token.cancel; } });
        await tick(); assert.equal(visits.length, before);
        cancel(); loaded(); warmGate = undefined;
        assert.equal(await work, false); await navigation;
        assert.equal(visits.length, before);
    });
    await t.test('foreground network and unreadable responses provide the intended retry target', async () => {
        for (const type of ['exception', 'invalid']) {
            let error;
            await ui.visitCatalogue(`/product/${type}`, { onError: value => { error = value; } });
            emit(type, { exception: new Error('Unavailable'), response: { status: 503 } });
            assert.match(error.navigation, /try again/i);
            assert.equal(notices.at(-1), `/product/${type}`);
            visits.at(-1).options.onFinish({});
        }
        assert.equal(prevented, 2);
        emit('exception', { exception: new Error('An unrelated request') });
        assert.equal(prevented, 2, 'other application errors retain their own handling');
    });
    await t.test('superseded updates are intentional cancellations for HTTP and cached response paths', () => {
        const count = notices.length;
        emit('exception', { exception: new ui.SupersededCatalogueVisit() });
        assert.equal(prevented, 3);
        const rejection = new Event('unhandledrejection', { cancelable: true });
        Object.defineProperty(rejection, 'reason', { value: new ui.SupersededCatalogueVisit() });
        window.dispatchEvent(rejection);
        assert.equal(rejection.defaultPrevented, true);
        assert.equal(notices.length, count);
        const other = new Event('unhandledrejection', { cancelable: true });
        Object.defineProperty(other, 'reason', { value: new Error('Unrelated error') });
        window.dispatchEvent(other); assert.equal(other.defaultPrevented, false);
    });
    await t.test('other-tab invalidation, return/focus and mutation events remove retained pages', async () => {
        for (const trigger of [() => channel.dispatchEvent(new Event('message')), () => window.dispatchEvent(new Event('focus')), () => document.dispatchEvent(new Event('visibilitychange')), () => emit('before', { visit: { method: 'post', headers: {}, url: new URL('/cart', window.location.origin) } })]) {
            const work = ui.cataloguePrefetch.prepare('/product/retained'); await tick(); requests.at(-1).complete(); await work;
            trigger(); assert.equal(await ui.cataloguePrefetch.ready('/product/retained'), false);
        }
        assert.equal(channel.lastMessage, 'invalidate');
        assert.ok(flushed.length);
    });
    await t.test('private intent prepares code only and activation immediately makes a fresh authorized request', async () => {
        ui.cataloguePrefetch.clear();
        ui.cataloguePrefetch.observe(page);
        let loaded;
        warmGate = new Promise(resolve => { loaded = resolve; });
        const before = requests.length;
        const prep = ui.preparePortal('/buyer/saved-products?page=2', { ...page, component: 'Buyer/Home' }); await tick();
        assert.equal(components.at(-1), 'Buyer/SavedProducts');
        assert.equal(requests.length, before, 'no owned response is requested on intent');
        const count = visits.length;
        ui.visitPortal('/buyer/saved-products?page=2');
        assert.equal(visits.length, count + 1, 'slow code does not delay the foreground visit');
        assert.equal(visits.at(-1).options.fresh, true);
        assert.equal(visits.at(-1).options.headers.Purpose, undefined);
        assert.equal(visits.at(-1).options.headers['X-Bagoo-Catalogue'], undefined);
        assert.equal(visits.at(-1).options.headers['X-Bagoo-Navigation'], '1');
        visits.at(-1).options.onFinish({});
        loaded(); warmGate = undefined; assert.equal(await prep, false, 'activation invalidates the old preparation generation');
    });
    await t.test('private network failures retain a retry destination instead of becoming an empty list', async () => {
        let error;
        ui.visitPortal('/buyer/profile?tab=addresses', { onError: value => { error = value; } });
        emit('exception', { exception: new Error('Offline') });
        assert.match(error.navigation, /try again/i);
        assert.equal(notices.at(-1), '/buyer/profile?tab=addresses');
        visits.at(-1).options.onFinish({});
    });
    await t.test('unreviewed destinations keep their normal error handling without private preparation headers', () => {
        ui.visitPortal('/messages');
        assert.equal(visits.at(-1).options.fresh, true);
        assert.equal(visits.at(-1).options.headers, undefined);
    });
    await t.test('ordinary non-catalogue navigation carries no cache context headers', async () => {
        await ui.visitCatalogue('/buyer/profile');
        assert.equal(visits.at(-1).options.headers, undefined);
    });
});
