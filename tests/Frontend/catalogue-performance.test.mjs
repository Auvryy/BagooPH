import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { buildSync } from 'esbuild';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-catalogue-performance-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const bundle = buildSync({
    stdin: { contents: "export * from '@/utils/cataloguePrefetch'; export * from '@/utils/catalogueSearch';", resolveDir: resolve(import.meta.dirname, '../..'), loader: 'tsx' },
    bundle: true, packages: 'external', platform: 'node', format: 'esm', write: false,
});
const file = join(temporary, 'performance.mjs');
writeFileSync(file, bundle.outputFiles[0].text);
const ui = await import(pathToFileURL(file).href);
const tick = async () => { await Promise.resolve(); await Promise.resolve(); };
const snapshot = (id = null, role = 'buyer', extra = {}) => ({ version: 'build-one', props: { cataloguePrefetchEnabled: true, auth: { user: id ? { id, role, status: 'active', kyc_status: 'approved' } : null }, flash: {}, ...extra } });
function fixture() {
    const requests = [], flushed = [];
    let clock = 0;
    const env = { origin: 'https://bagoo.test', currentUrl: 'https://bagoo.test/', visible: true, online: true, connection: { effectiveType: '4g' } };
    const policy = new ui.CataloguePrefetch({
        prepare(url, headers, account) {
            let complete;
            const row = { url, headers, account, cancelled: false, finish: value => complete(value) };
            const done = new Promise(resolve => { complete = resolve; });
            requests.push(row);
            return { done, cancel: () => { row.cancelled = true; complete(false); } };
        },
        flush: (url, headers) => flushed.push({ url, headers }),
    }, () => env, () => clock);
    policy.observe(snapshot());
    return { policy, requests, flushed, env, advance: amount => { clock += amount; } };
}

test('canonical descriptors retain every page/filter and reject operational or foreign destinations', () => {
    assert.equal(ui.canonicalCatalogueUrl('/buyer/search?sort=top_sales&page=2&search=Bag', 'https://bagoo.test'), '/buyer/search?page=2&search=Bag&sort=top_sales');
    for (const href of ['/buyer/orders', '/checkout', '/notifications', '/messages', '/buyer/profile', '/hub/scan', '/admin/users', '/advertisements/offer', 'https://other.test/product/bag', 'javascript:alert(1)']) {
        assert.equal(ui.canonicalCatalogueUrl(href, 'https://bagoo.test'), undefined);
    }
});

test('one speculative request serves matching intent and waits for the same in-flight navigation', async () => {
    const { policy, requests } = fixture();
    const first = policy.prepare('/product/bag');
    const repeated = policy.prepare('/product/bag');
    assert.equal(await policy.prepare('/product/another'), false);
    const navigation = policy.ready('/product/bag');
    assert.equal(requests.length, 1);
    requests[0].finish(true);
    assert.deepEqual(await Promise.all([first, repeated, navigation]), [true, true, true]);
    assert.equal(await policy.prepare('/product/bag'), true);
    assert.equal(requests.length, 1);
});

test('different queries/pages never reuse a result and expired data requires another preparation', async () => {
    const { policy, requests, advance, flushed } = fixture();
    const first = policy.prepare('/buyer/search?search=Bag&page=1'); requests.at(-1).finish(true); await first;
    assert.equal(await policy.ready('/buyer/search?search=Bag&page=2'), false);
    assert.equal(await policy.ready('/buyer/search?search=Hat&page=1'), false);
    advance(10_001);
    assert.equal(await policy.ready('/buyer/search?page=1&search=Bag'), false);
    assert.ok(flushed.length);
    const next = policy.prepare('/buyer/search?page=1&search=Bag'); requests.at(-1).finish(true); await next;
    assert.equal(requests.length, 2);
});

test('context changes cancel old work, change cache identity and reject late completion', async () => {
    const { policy, requests } = fixture();
    policy.observe(snapshot(7));
    const oldHeaders = policy.headers();
    const old = policy.prepare('/product/bag');
    policy.observe(snapshot(8));
    requests[0].finish(true);
    assert.equal(await old, false);
    assert.equal(requests[0].cancelled, true);
    assert.notDeepEqual(policy.headers(), oldHeaders);
    assert.equal(await policy.ready('/product/bag'), false);
    policy.observe(snapshot(8, 'buyer', { auth: { user: { id: 8, role: 'buyer', activeHub: { id: 9 } } } }));
    assert.notEqual(policy.headers()['X-Bagoo-Catalogue-Context'], oldHeaders['X-Bagoo-Catalogue-Context']);
});

test('disabled, worker-role, pending feedback, offline, hidden and slow-connection cases do not speculate', async () => {
    const cases = [
        f => f.policy.observe(snapshot(7, 'seller')),
        f => f.policy.observe(snapshot(null, 'buyer', { cataloguePrefetchEnabled: false })),
        f => f.policy.observe(snapshot(null, 'buyer', { flash: { success: 'Saved.' } })),
        f => f.policy.observe(snapshot(null, 'buyer', { errors: { search: 'Invalid.' } })),
        f => { f.env.visible = false; }, f => { f.env.online = false; },
        f => { f.env.connection.saveData = true; }, f => { f.env.connection.effectiveType = '2g'; },
    ];
    for (const change of cases) {
        const f = fixture(); change(f);
        assert.equal(await f.policy.prepare('/product/bag'), false);
        assert.equal(f.requests.length, 0);
    }
    const f = fixture(); delete f.env.connection;
    assert.equal(await f.policy.prepare('/buyer/search?page=2', true), false);
    const hovered = f.policy.prepare('/product/bag'); f.requests.at(-1).finish(true);
    assert.equal(await hovered, true);
});

test('foreground work pauses speculation, takes priority over other work and invalidation removes completed data', async () => {
    const { policy, requests, flushed } = fixture();
    const old = policy.prepare('/product/bag');
    assert.equal(await policy.ready('/product/another'), false);
    assert.equal(await old, false);
    assert.equal(requests[0].cancelled, true);
    policy.setBusy(true); assert.equal(await policy.prepare('/product/another'), false);
    policy.setBusy(false);
    const fresh = policy.prepare('/product/another'); requests.at(-1).finish(true); await fresh;
    policy.clear(); assert.equal(await policy.ready('/product/another'), false);
    assert.ok(flushed.some(row => row.url === '/product/another'));
});

test('failed responses are not ready or automatically retried within their short cooldown', async () => {
    const { policy, requests, advance } = fixture();
    const failed = policy.prepare('/product/bag'); requests.at(-1).finish(false);
    assert.equal(await failed, false);
    assert.equal(await policy.prepare('/product/bag'), false);
    assert.equal(await policy.ready('/product/bag'), false);
    assert.equal(requests.length, 1);
    advance(10_001);
    const retry = policy.prepare('/product/bag'); requests.at(-1).finish(true);
    assert.equal(await retry, true);
});

test('retention stays bounded while abandoned pages are flushed', async () => {
    const { policy, requests, flushed } = fixture();
    for (let index = 0; index < 25; index++) {
        const work = policy.prepare(`/product/bag-${index}`); requests.at(-1).finish(true); await work;
    }
    assert.equal(await policy.ready('/product/bag-0'), false);
    assert.equal(await policy.ready('/product/bag-24'), true);
    assert.equal(flushed.filter(row => row.url.startsWith('/product/')).length, 5);
});

test('idle preparation waits for visible settled controls, yields to activity and runs once', t => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    let allowed = true, requests = 0;
    const idle = new ui.CatalogueIdle(() => allowed, () => requests++);
    t.after(() => idle.dispose());
    t.mock.timers.tick(2_000); assert.equal(requests, 0);
    idle.display(true); t.mock.timers.tick(900); idle.activity(); t.mock.timers.tick(900);
    assert.equal(requests, 0);
    allowed = false; t.mock.timers.tick(100); assert.equal(requests, 0);
    allowed = true; idle.activity(); t.mock.timers.tick(1_000); assert.equal(requests, 1);
    idle.activity(); idle.display(false); idle.display(true); t.mock.timers.tick(3_000); assert.equal(requests, 1);
});

function searchFixture(t, results = false) {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const prepared = [], visits = [], states = [], cancelled = [];
    const filters = { search: '', category: 'apparel', sort: 'price_asc', min_price: '0', in_stock: true, page: 2 };
    const controller = new ui.CatalogueSearch({
        base: () => '/buyer/search', filters: () => filters, resultsPage: () => results,
        prepare: url => { let finish; const done = new Promise(resolve => { finish = resolve; }); prepared.push({ url, finish }); return done; },
        cancelPreparation: url => cancelled.push(url), visit: (url, options) => visits.push({ url, options }), notify: state => states.push(state),
    });
    t.after(() => controller.dispose());
    return { controller, prepared, visits, states, cancelled, filters };
}

test('typing pauses once before preparation and never navigates away from an unrelated page', t => {
    const f = searchFixture(t);
    f.controller.update('Ba'); t.mock.timers.tick(250);
    assert.equal(f.prepared.length, 0);
    f.controller.update('Bag'); t.mock.timers.tick(299);
    assert.equal(f.prepared.length, 0);
    t.mock.timers.tick(1);
    assert.equal(f.prepared.length, 1); assert.equal(f.visits.length, 0);
    assert.equal(f.prepared[0].url, '/buyer/search?category=apparel&in_stock=1&min_price=0&search=Bag&sort=price_asc');
});

test('Enter flushes the debounce and keeps matching preparation alive for one foreground visit', t => {
    const f = searchFixture(t);
    f.controller.update('Bag'); t.mock.timers.tick(300);
    f.controller.submit('Bag'); t.mock.timers.tick(500);
    assert.equal(f.prepared.length, 1); assert.equal(f.visits.length, 1); assert.equal(f.cancelled.length, 0);
    assert.equal(f.visits[0].url, f.prepared[0].url);
    assert.equal(f.visits[0].options.replace, false);
});

test('1/2/100/101-character boundaries and literal punctuation retain the existing server contract', t => {
    const f = searchFixture(t);
    f.controller.update('B'); t.mock.timers.tick(300); assert.equal(f.prepared.length, 0);
    f.controller.submit('B'); assert.equal(f.visits.length, 1);
    f.controller.update('BB'); t.mock.timers.tick(300); assert.equal(f.prepared.length, 1);
    f.controller.update('B'.repeat(100)); t.mock.timers.tick(300); assert.equal(f.prepared.length, 2);
    f.controller.update('B'.repeat(101)); t.mock.timers.tick(300); f.controller.submit('B'.repeat(101));
    assert.equal(f.prepared.length, 2); assert.equal(f.visits.length, 1); assert.equal(f.states.at(-1).status, 'error');
    assert.equal(f.states.at(-1).invalid, true);
    f.controller.submit("  O'Bag %_!  ");
    assert.equal(new URL(f.visits.at(-1).url, 'https://bagoo.test').searchParams.get('search'), "O'Bag %_!");
});

test('composition does not fetch intermediate text; paste/end composition prepares only the final query', t => {
    const f = searchFixture(t);
    f.controller.update('中', true); f.controller.update('中文', true); t.mock.timers.tick(500); f.controller.submit('中文');
    assert.equal(f.prepared.length, 0); assert.equal(f.visits.length, 0);
    f.controller.update('中文', false); t.mock.timers.tick(300);
    assert.equal(f.prepared.length, 1);
    f.controller.update('Pasted query'); t.mock.timers.tick(300);
    assert.equal(f.prepared.length, 2);
});

test('live search replaces typing history with coherent partial props and rejects late replies', t => {
    const f = searchFixture(t, true);
    f.controller.update('Bag'); t.mock.timers.tick(300);
    const old = f.visits[0]; let cancelled = false;
    old.options.onCancelToken({ cancel: () => { cancelled = true; } });
    f.controller.update('Hat');
    assert.equal(cancelled, true);
    assert.throws(() => old.options.onBeforeUpdate(), { name: 'SupersededCatalogueVisit' });
    old.options.onSuccess(); assert.notEqual(f.states.at(-1).status, 'ready');
    t.mock.timers.tick(300);
    const current = f.visits.at(-1);
    assert.doesNotThrow(() => current.options.onBeforeUpdate());
    assert.equal(current.options.replace, true);
    assert.deepEqual(current.options.only, ['products', 'relatedProducts', 'filters', 'suggestions']);
    assert.equal(f.prepared.length, 0);
});

test('clear preserves unrelated filters and resets paging rather than jumping to the homepage', t => {
    const f = searchFixture(t, true);
    f.controller.submit('');
    assert.equal(f.visits.at(-1).url, '/buyer/search?category=apparel&in_stock=1&min_price=0&sort=price_asc');
    assert.equal(f.visits.at(-1).options.replace, false);
});

test('foreground failures offer retry and leaving/disposal prevent delayed navigation or stale updates', async t => {
    const f = searchFixture(t);
    f.controller.submit('Bag'); f.controller.networkFailure(); assert.equal(f.states.at(-1).status, 'error');
    f.controller.submit('Bag'); assert.equal(f.visits.length, 2);
    f.controller.update('Hat'); t.mock.timers.tick(300);
    const old = f.prepared.at(-1);
    f.controller.leaving('/buyer/profile'); old.finish(true); await tick();
    assert.equal(f.states.at(-1).status, 'idle');
    f.controller.update('Shoes'); f.controller.dispose(); t.mock.timers.tick(300);
    assert.equal(f.prepared.length, 1);
});
