import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { build } from 'esbuild';

const root = resolve(import.meta.dirname, '../..');

const overlayHandling = new Map([
    ['resources/js/Components/BagooLoadingScreen.tsx', 'decorative'],
    ['resources/js/Components/BuyerAddressEditor.tsx', 'headless-dialog'],
    ['resources/js/Components/BuyerApplicationModal.tsx', 'headless-dialog'],
    ['resources/js/Components/BuyerCancelOrder.tsx', 'headless-dialog'],
    ['resources/js/Components/BuyerIdVerificationModal.tsx', 'custom-dialog'],
    ['resources/js/Components/BuyerSavedReview.tsx', 'headless-dialog'],
    ['resources/js/Components/ChatModal.tsx', 'headless-dialog'],
    ['resources/js/Components/CourierSidebar.tsx', 'headless-dialog'],
    ['resources/js/Components/CourierUI.tsx', 'headless-dialog'],
    ['resources/js/Components/CursorSpotlight.tsx', 'decorative'],
    ['resources/js/Components/Dropdown.tsx', 'click-catcher'],
    ['resources/js/Components/GrainOverlay.tsx', 'decorative'],
    ['resources/js/Components/Logistics/HubPicker.tsx', 'headless-dialog'],
    ['resources/js/Components/Logistics/NavigationDrawer.tsx', 'headless-dialog'],
    ['resources/js/Components/Modal.tsx', 'headless-dialog'],
    ['resources/js/Components/OtpModal.tsx', 'custom-dialog'],
    ['resources/js/Components/SellerShopReview.tsx', 'custom-dialog'],
    ['resources/js/Components/Vouchers/BuyerVoucherPicker.tsx', 'headless-dialog'],
    ['resources/js/Layouts/DashboardLayout.tsx', 'custom-dialog'],
    ['resources/js/Pages/Admin/KycQueue.tsx', 'headless-dialog'],
    ['resources/js/Pages/Buyer/OrderDetail.tsx', 'custom-dialog'],
    ['resources/js/Pages/Buyer/ProductDetail.tsx', 'custom-dialog'],
    ['resources/js/Pages/Buyer/Search.tsx', 'custom-dialog'],
    ['resources/js/Pages/Checkout/Index.tsx', 'headless-dialog'],
    ['resources/js/Pages/Hub/CounterPickup.tsx', 'headless-dialog'],
    ['resources/js/Pages/Hub/Network.tsx', 'custom-dialog'],
    ['resources/js/Pages/Marketplace/ShopDetail.tsx', 'custom-dialog'],
    ['resources/js/Pages/Seller/Landing.tsx', 'decorative'],
    ['resources/js/Pages/Seller/Orders.tsx', 'custom-dialog'],
    ['resources/js/Pages/Seller/Products.tsx', 'custom-dialog'],
]);

function fixedInsetFiles(directory = join(root, 'resources/js')) {
    return readdirSync(directory, { withFileTypes: true }).flatMap(entry => {
        const absolutePath = join(directory, entry.name);
        if (entry.isDirectory()) return fixedInsetFiles(absolutePath);
        if (!/\.(?:ts|tsx)$/.test(entry.name)) return [];
        const source = readFileSync(absolutePath, 'utf8');
        return /fixed\s+inset-0/.test(source) ? [absolutePath.slice(root.length + 1)] : [];
    }).sort();
}

test('every fixed full-screen source has an explicit modal/decorative classification', () => {
    assert.deepEqual(fixedInsetFiles(), [...overlayHandling.keys()].sort());
});

for (const [path, handling] of overlayHandling) {
    test(`${path} uses its audited ${handling} scroll behavior`, () => {
        const source = readFileSync(join(root, path), 'utf8');
        if (handling === 'custom-dialog') {
            assert.match(source, /import\s+useBodyScrollLock\s+from\s+['"]@\/hooks\/useBodyScrollLock['"]/);
            assert.match(source, /useBodyScrollLock\(/);
            return;
        }
        assert.doesNotMatch(source, /import\s+useBodyScrollLock\s+from\s+['"]@\/hooks\/useBodyScrollLock['"]/);
        if (handling === 'headless-dialog') {
            assert.match(source, /@headlessui\/react/);
            assert.match(source, /\bDialog(?:Backdrop|Panel|Title)?\b/);
        }
    });
}

const bundle = await build({
    entryPoints: [join(root, 'resources/js/hooks/useBodyScrollLock.ts')],
    bundle: true, platform: 'node', format: 'esm', write: false,
    plugins: [{ name: 'controlled-effect', setup(builder) {
        builder.onResolve({ filter: /^react$/ }, () => ({ path: 'react', namespace: 'hook-test' }));
        builder.onLoad({ filter: /.*/, namespace: 'hook-test' }, () => ({ contents: 'export const useEffect = fn => globalThis.modalScrollHarness.effect(fn);' }));
    } }],
});
const moduleUrl = `data:text/javascript;base64,${Buffer.from(bundle.outputFiles[0].text).toString('base64')}`;
const useBodyScrollLock = (await import(moduleUrl)).default;

function withDocument(run) {
    const previousDocument = Object.getOwnPropertyDescriptor(globalThis, 'document');
    const cleanups = [];
    const document = { body: { style: { overflow: 'auto' } }, documentElement: { style: { overflow: 'scroll' } } };
    globalThis.document = document;
    globalThis.modalScrollHarness = { effect(fn) {
        const cleanup = fn();
        if (cleanup) {
            let active = true;
            cleanups.push(() => { if (active) { active = false; cleanup(); } });
        }
    } };
    try { return run({ document, cleanups }); }
    finally {
        for (const cleanup of cleanups.reverse()) cleanup();
        delete globalThis.modalScrollHarness;
        if (previousDocument) Object.defineProperty(globalThis, 'document', previousDocument);
        else delete globalThis.document;
    }
}

test('active custom overlays lock both page scroll roots and restore the original styles', () => {
    withDocument(({ document, cleanups }) => {
        useBodyScrollLock(true);
        assert.equal(document.body.style.overflow, 'hidden');
        assert.equal(document.documentElement.style.overflow, 'hidden');
        cleanups[0]();
        assert.equal(document.body.style.overflow, 'auto');
        assert.equal(document.documentElement.style.overflow, 'scroll');
    });
});

test('overlapping custom overlays keep the page locked until the last one closes', () => {
    withDocument(({ document, cleanups }) => {
        useBodyScrollLock(true);
        useBodyScrollLock(true);
        cleanups[0]();
        assert.equal(document.body.style.overflow, 'hidden');
        assert.equal(document.documentElement.style.overflow, 'hidden');
        cleanups[1]();
        assert.equal(document.body.style.overflow, 'auto');
        assert.equal(document.documentElement.style.overflow, 'scroll');
    });
});

test('inactive custom overlays do not change the page scroll styles', () => {
    withDocument(({ document, cleanups }) => {
        useBodyScrollLock(false);
        assert.equal(document.body.style.overflow, 'auto');
        assert.equal(document.documentElement.style.overflow, 'scroll');
        assert.equal(cleanups.length, 0);
    });
});
