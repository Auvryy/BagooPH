import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
const tmp = mkdtempSync(join(import.meta.dirname, '.tmp-route-preview-'));
after(() => rmSync(tmp, { recursive: true, force: true }));
const output = await build({entryPoints: ['resources/js/Components/CheckoutRoutePreview.tsx'], bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', write: false});
const file = join(tmp, 'preview.mjs'); writeFileSync(file, output.outputFiles[0].text);
const {default: Preview} = await import(pathToFileURL(file));
const hub = (id, name) => ({id, name, tier: 'local_bayan_hub', code: `H-${id}`});
const shops = [{shop_id: 1, shop_name: 'Test shop', shipping_quote: {route: [hub(1, 'Origin'), hub(2, 'Mother'), hub(3, 'Destination')], same_hub_bypass: false}}];
const render = props => renderToStaticMarkup(createElement(Preview, {shops, ready: true, pending: false, ...props}));
test('regional route is ordered, named and disclosed as a plan rather than tracking', () => {
    const html = render();
    assert.match(html, /<details/); assert.match(html, /<summary/);
    assert.ok(html.indexOf('Origin') < html.indexOf('Mother</li>'));
    assert.ok(html.indexOf('Mother</li>') < html.indexOf('Destination'));
    assert.match(html, /aria-label="Planned route for Test shop"/);
    assert.match(html, /Tracking updates after handover scans/);
    assert.match(html, /Your address/);
});
test('a changed or failed quote cannot show an old route and pending has accessible feedback', () => {
    assert.equal(render({ready: false}), '');
    const html = render({pending: true});
    assert.match(html, /role="status"/); assert.doesNotMatch(html, /Origin|Destination/);
});
test('local collection and several shop routes retain separate endpoints', () => {
    const html = render({selfPickup: true, shops: [shops[0], {shop_id: 2, shop_name: 'Local shop', shipping_quote: {route: [hub(4, 'Local')], same_hub_bypass: true}}]});
    assert.match(html, /2 shops/); assert.match(html, /Local hub route/);
    assert.match(html, /Hub collection/); assert.doesNotMatch(html, /Your address/);
    assert.equal((html.match(/<ol /g) || []).length, 2);
});
