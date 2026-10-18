import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { buildSync } from 'esbuild';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-portal-navigation-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const bundle = buildSync({
    stdin: { contents: "export * from '@/utils/cataloguePrefetch'; export * from '@/utils/portalNavigation';", resolveDir: resolve(import.meta.dirname, '../..') },
    bundle: true, packages: 'external', platform: 'node', format: 'esm', write: false,
});
const file = join(temporary, 'policy.mjs'); writeFileSync(file, bundle.outputFiles[0].text);
const ui = await import(pathToFileURL(file).href);
const snapshot = (role = 'buyer', id = 7) => ({ component: role === 'seller' ? 'Seller/Dashboard' : 'Buyer/Home', version: 'build-one', props: { cataloguePrefetchEnabled: true, auth: { user: { id, role, status: 'active', kyc_status: 'approved' } }, flash: {}, errors: {} } });
const tick = async () => { for (let i = 0; i < 8; i++) await Promise.resolve(); };
function fixture() {
    let clock = 0;
    const requests = [], imports = [];
    const env = { origin: 'https://bagoo.test', currentUrl: 'https://bagoo.test/', visible: true, online: true, connection: { effectiveType: '4g' } };
    const policy = new ui.CataloguePrefetch({
        prepare(url) { let finish; const done = new Promise(resolve => { finish = resolve; }); requests.push({ url, finish }); return { done, cancel: () => finish(false) }; },
        flush() {},
        prepareComponent(component) { return new Promise((finish, fail) => imports.push({ component, finish, fail })); },
    }, () => env, () => clock);
    policy.observe(snapshot());
    return { policy, requests, imports, env, advance: n => { clock += n; } };
}

test('reviewed buyer and seller code destinations retain exact portal and origin boundaries', () => {
    const buyer = snapshot(), seller = snapshot('seller');
    assert.equal(ui.portalPageComponent('/buyer/saved-products?page=2', 'https://bagoo.test', buyer), 'Buyer/SavedProducts');
    assert.equal(ui.portalPageComponent('/buyer/profile?tab=addresses', 'https://bagoo.test', buyer), 'Buyer/Profile');
    assert.equal(ui.portalPageComponent('/buyer/orders', 'https://bagoo.test', buyer), 'Buyer/Profile');
    const restricted = { ...buyer, props: { ...buyer.props, auth: { user: { ...buyer.props.auth.user, status: 'inactive' } } } };
    assert.equal(ui.portalPageComponent('/buyer/orders', 'https://bagoo.test', restricted), 'Buyer/Orders');
    const pending = { ...restricted, props: { ...restricted.props, auth: { user: { ...restricted.props.auth.user, kyc_status: 'pending_approval' } } } };
    assert.equal(ui.portalPageComponent('/buyer/orders', 'https://bagoo.test', pending), 'Buyer/Profile');
    for (const [prefix, origin] of [['/seller', 'https://bagoo.test'], ['', 'https://seller.bagoo.test']]) {
        assert.equal(ui.portalPageComponent(`${prefix}/products?search=Bag&page=2`, origin, seller), 'Seller/Products');
        assert.equal(ui.portalPageComponent(`${prefix}/orders`, origin, seller), 'Seller/Orders');
        assert.equal(ui.portalPageComponent(`${prefix}/preview`, origin, seller), 'Marketplace/ShopDetail');
    }
    assert.equal(ui.portalPageComponent('/products', 'https://bagoo.test', seller), undefined, 'a root catalogue is not a seller inventory');
    for (const page of [buyer, seller]) {
        for (const href of ['/messages', '/notifications', '/checkout', '/financial-oversight', '/account/settings', '/verification-documents/7/id', '/buyer/orders/8/pickup-code', '/hub/scan', '/admin/users', 'https://foreign.test/buyer/profile', 'javascript:alert(1)']) {
            assert.equal(ui.portalPageComponent(href, 'https://bagoo.test', page), undefined);
        }
    }
    assert.equal(ui.portalPageComponent('/seller/products', 'https://bagoo.test', buyer), undefined);
    assert.equal(ui.portalPageComponent('/buyer/profile', 'https://bagoo.test', seller), undefined);
    assert.equal(ui.portalPageComponent('/seller/products', 'https://bagoo.test', snapshot('admin')), undefined);
});

test('private intent imports only code and shares one request budget with catalogue data', async () => {
    const { policy, requests, imports } = fixture();
    const first = policy.prepareCode('Buyer/Profile'), repeated = policy.prepareCode('Buyer/Profile'); await tick();
    assert.equal(imports.length, 1); assert.equal(requests.length, 0);
    assert.equal(await policy.prepare('/product/bag'), false);
    assert.equal(await policy.prepareCode('Buyer/SavedProducts'), false);
    imports[0].finish(); assert.deepEqual(await Promise.all([first, repeated]), [true, true]);
    const data = policy.prepare('/product/bag');
    assert.equal(await policy.prepareCode('Buyer/SavedProducts'), false);
    requests[0].finish(true); await data;
    policy.observe(snapshot('seller'));
    const seller = policy.prepareCode('Seller/Products'); await tick();
    assert.equal(imports[1].component, 'Seller/Products');
    assert.equal(await policy.prepare('/product/bag'), false, 'seller cannot opt into buyer data caching');
    imports[1].finish(); assert.equal(await seller, true);
});

test('account changes and invalidation discard late imports without opening a second request slot', async () => {
    const { policy, imports } = fixture();
    const first = policy.prepareCode('Buyer/Profile'); await tick();
    policy.observe(snapshot('buyer', 8));
    assert.equal(await policy.prepareCode('Buyer/SavedProducts'), false);
    assert.equal(imports.length, 1);
    imports[0].finish(); assert.equal(await first, false);
    const next = policy.prepareCode('Buyer/SavedProducts'); await tick(); imports[1].finish(); assert.equal(await next, true);
    policy.clear();
    const again = policy.prepareCode('Buyer/SavedProducts'); await tick(); assert.equal(imports.length, 3); imports[2].finish(); await again;
});

test('code preparation observes feedback, disabled settings, foreground work and connection limits', async () => {
    const cases = [
        f => { f.env.visible = false; }, f => { f.env.online = false; },
        f => { f.env.connection.saveData = true; }, f => { f.env.connection.effectiveType = '2g'; },
        f => f.policy.setBusy(true),
        f => f.policy.observe({ ...snapshot(), props: { ...snapshot().props, cataloguePrefetchEnabled: false } }),
        f => f.policy.observe({ ...snapshot(), props: { ...snapshot().props, flash: { success: 'Saved.' } } }),
        f => f.policy.observe({ ...snapshot(), props: { ...snapshot().props, errors: { phone: 'Correct this.' } } }),
        f => f.policy.observe(snapshot('courier')), f => f.policy.observe(snapshot('admin')),
    ];
    for (const configure of cases) {
        const f = fixture(); configure(f);
        assert.equal(await f.policy.prepareCode('Buyer/Profile'), false);
        assert.equal(f.imports.length, 0); assert.equal(f.requests.length, 0);
    }
});

test('a failed import is bounded and permits a later intentional attempt after its cooldown', async () => {
    const { policy, imports, advance } = fixture();
    const first = policy.prepareCode('Buyer/Profile'); await tick(); imports[0].fail(new Error('Offline'));
    assert.equal(await first, false);
    assert.equal(await policy.prepareCode('Buyer/Profile'), false); assert.equal(imports.length, 1);
    advance(10_001);
    const next = policy.prepareCode('Buyer/Profile'); await tick(); imports[1].finish(); assert.equal(await next, true);
});

test('profile partial requests keep the current identity plus the selected coherent section', () => {
    for (const tab of ['orders', 'account', 'addresses', 'wallet', 'vouchers']) {
        const props = ui.buyerProfileProps(tab);
        for (const key of ['auth', 'user', 'canUsePortal', 'initialTab', 'flash', 'errors']) assert.ok(props.includes(key));
        if (tab !== 'orders') assert.ok(!props.includes('orders'));
        if (tab !== 'vouchers') assert.ok(!props.includes('voucherCollection'));
        if (tab !== 'account') assert.ok(!props.includes('emailSettings'));
    }
    assert.ok(ui.buyerProfileProps('orders').includes('orderCounts'));
    assert.ok(ui.buyerProfileProps('addresses').includes('addresses'));
    assert.ok(ui.buyerProfileProps('account').includes('identityCorrection'));
    assert.ok(ui.buyerProfileProps('vouchers').includes('availableVoucherCount'));
});
