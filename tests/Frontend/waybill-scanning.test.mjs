import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

const temp = mkdtempSync(join(import.meta.dirname, '.tmp-waybill-'));
after(() => rmSync(temp, { recursive: true, force: true }));
const bundle = await build({ stdin: { contents: "export * from '@/utils/waybillScan'; export { default as Codes } from '@/Components/WaybillCodes';", resolveDir: resolve(import.meta.dirname, '../..'), loader: 'tsx' },
    bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', write: false,
    plugins: [{ name: 'code-values', setup(builder) {
        builder.onResolve({ filter: /^react$/ }, () => ({ path: 'react', external: true }));
        builder.onResolve({ filter: /Components\/Barcode$|^\.\/Barcode$/ }, () => ({ path: 'barcode', namespace: 'code-values' }));
        builder.onLoad({ filter: /.*/, namespace: 'code-values' }, () => ({ contents: `import {createElement} from 'react'; export default props => createElement('svg', {'data-barcode-value':props.value,'data-quiet-margin':props.margin});`, loader: 'js' }));
        builder.onResolve({ filter: /^qrcode.react$/ }, () => ({ path: 'qr', namespace: 'qr-values' }));
        builder.onLoad({ filter: /.*/, namespace: 'qr-values' }, () => ({ contents: `import {createElement} from 'react'; export const QRCodeSVG = props => createElement('svg', {'data-qr-value':props.value,'data-quiet-margin':props.marginSize});`, loader: 'js' }));
    } }],
});
const file = join(temp, 'waybill.mjs'); writeFileSync(file, bundle.outputFiles[0].text);
const ui = await import(pathToFileURL(file).href);
const origin = 'https://hub.bagoo.test';

test('raw tracking and older same-platform tracking QR labels identify one canonical code', () => {
    for (const value of [' BGO-ABC_123 ', 'bgo-abc_123', 'https://bagoo.test/track/BGO-ABC_123', 'https://seller.bagoo.test/track/bgo-abc_123/', 'https://hub.bagoo.test/track/BGO-ABC_123']) {
        assert.equal(ui.waybillCode(value, origin), 'BGO-ABC_123');
    }
    assert.equal(ui.waybillCode('http://localhost:8000/track/BGO-LOCAL', 'http://hub.localhost:8000'), 'BGO-LOCAL');
});
test('foreign URLs, misleading paths, hidden text, extra payloads and length overflow cannot become scans', () => {
    for (const value of ['https://foreign.test/track/BGO-ABC', 'https://hub.bagoo.test.foreign.test/track/BGO-ABC', 'https://user@bagoo.test/track/BGO-ABC', 'https://bagoo.test/track/BGO-ABC?next=/hub', 'https://bagoo.test/track/BGO-ABC#extra', 'https://bagoo.test/track/BGO%2FABC', 'https://bagoo.test:8443/track/BGO-ABC', 'javascript:BGO-ABC', 'BGO-ONE BGO-TWO', '\tBGO-ABC', 'BGO-ABC\n', 'ＢＧＯ-ABC', 'A'.repeat(256)]) {
        assert.equal(ui.waybillCode(value, origin), undefined, value);
    }
    assert.equal(ui.waybillCode('A'.repeat(255), origin), 'A'.repeat(255));
});
test('matching barcode and QR are deduplicated but two different parcels require a closer scan', () => {
    assert.deepEqual(ui.selectWaybill([{ rawValue: 'BGO-ONE' }, { rawValue: 'https://bagoo.test/track/BGO-ONE' }], origin), { code: 'BGO-ONE' });
    const result = ui.selectWaybill([{ rawValue: 'BGO-ONE' }, { rawValue: 'BGO-TWO' }], origin);
    assert.equal(result.code, undefined); assert.match(result.error, /Several parcel codes/);
    assert.deepEqual(ui.selectWaybill([], origin), {});
});
test('print and compact labels separate code zones with quiet margins and identical real identifiers', () => {
    for (const compact of [false, true]) {
        const html = renderToStaticMarkup(createElement(ui.Codes, { trackingNumber: 'BGO-ONE', compact }));
        assert.match(html, /data-waybill-barcode/); assert.match(html, /data-waybill-qr/);
        assert.match(html, /space-y-6/); assert.match(html, /data-barcode-value="BGO-ONE" data-quiet-margin="12"/);
        assert.match(html, /data-qr-value="BGO-ONE" data-quiet-margin="4"/);
        assert.doesNotMatch(html, /\/track\/|BGO-TRK-undefined/);
    }
    assert.doesNotMatch(renderToStaticMarkup(createElement(ui.Codes, { trackingNumber: '' })), /<svg/);
});
test('first scanner request uses the current session cookie even when the original page token is stale', async () => {
    const fetch = globalThis.fetch; const document = globalThis.document;
    let cookie = 'XSRF-TOKEN=first%2Bsession'; const calls = [];
    globalThis.document = { get cookie() { return cookie; }, querySelector: () => ({ content: 'old-page-token' }) };
    globalThis.fetch = async (path, options) => { calls.push({ path, options }); return Response.json({ success: true, prompt: {}, delivery: { tracking_number: 'BGO-ONE', guidance: {} } }); };
    try {
        await ui.requestWaybill('/hub/scan', { mode: 'inspect', barcode: 'BGO-ONE', hub_id: 2 }, new AbortController().signal);
        cookie = 'XSRF-TOKEN=new%2Bsession';
        await ui.requestWaybill('/scan', { mode: 'confirm', barcode: 'BGO-ONE', hub_id: 2 }, new AbortController().signal);
        assert.equal(calls[0].options.headers['X-XSRF-TOKEN'], 'first+session');
        assert.equal(calls[1].options.headers['X-XSRF-TOKEN'], 'new+session');
        for (const call of calls) { assert.equal(call.options.headers['X-CSRF-TOKEN'], undefined); assert.equal(call.options.mode, 'same-origin'); assert.equal(call.options.credentials, 'same-origin'); }
        assert.equal(calls.length, 2);
    } finally { globalThis.fetch = fetch; globalThis.document = document; }
});
test('scan failures expose validation and expired-session feedback and never replay a handover', async () => {
    const fetch = globalThis.fetch; const document = globalThis.document;
    globalThis.document = { cookie: '', querySelector: () => ({ content: 'rendered' }) };
    let calls = 0;
    try {
        for (const [status, data, message] of [[422, { message: 'Invalid', errors: { barcode: ['Use the tracking barcode.'] } }, /Use the tracking barcode/], [409, { error: 'Scan the waybill again.' }, /Scan the waybill again/], [419, {}, /session expired/], [500, { message: 'Internal details' }, /temporarily unavailable/]]) {
            globalThis.fetch = async () => { calls++; return Response.json(data, { status }); };
            await assert.rejects(ui.requestWaybill('/hub/scan', { mode: 'confirm' }, new AbortController().signal), message);
        }
        assert.equal(calls, 4);
        globalThis.fetch = async () => new Response('<html>Login</html>', { status: 200 });
        await assert.rejects(ui.requestWaybill('/hub/scan', {}, new AbortController().signal), /could not read/);
        globalThis.fetch = async () => Response.json({ success: true });
        await assert.rejects(ui.requestWaybill('/hub/scan', {}, new AbortController().signal), /incomplete/);
        await assert.rejects(ui.requestWaybill('//foreign.test/scan', {}, new AbortController().signal), /current portal/);
    } finally { globalThis.fetch = fetch; globalThis.document = document; }
});

test('a separate buyer-return response opens only its scoped case and never confirms delivery custody', async () => {
    const fetch = globalThis.fetch; const document = globalThis.document;
    globalThis.document = { cookie: '', querySelector: () => ({ content: 'rendered' }) };
    const entry = { reference: 'BR-OWNED', barcode: 'BRT-ABCDEFGHIJKLMNOPQRST', href: '/buyer-returns/11', status: 'approved' };
    try {
        globalThis.fetch = async () => Response.json({ success: true, confirmed: false, return_case: entry });
        const result = await ui.requestWaybill('/hub/scan', { barcode: entry.barcode, mode: 'inspect' }, new AbortController().signal);
        assert.deepEqual(result.return_case, entry); assert.equal(result.confirmed, false); assert.equal(result.delivery, undefined);
        for (const patch of [{ href: 'https://foreign.test/return' }, { href: 'javascript:run()' }, { href: '//foreign.test' }, { barcode: 'BGO-ORIGINAL' }, { status: null }]) {
            globalThis.fetch = async () => Response.json({ success: true, confirmed: false, return_case: { ...entry, ...patch } });
            await assert.rejects(ui.requestWaybill('/hub/scan', {}, new AbortController().signal), /return details were incomplete/);
        }
        globalThis.fetch = async () => Response.json({ success: true, confirmed: true, return_case: entry });
        await assert.rejects(ui.requestWaybill('/hub/scan', {}, new AbortController().signal), /return details were incomplete/);
    } finally { globalThis.fetch = fetch; globalThis.document = document; }
});
