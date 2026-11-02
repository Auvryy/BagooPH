import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';

const root = resolve(import.meta.dirname, '../..');
const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-road-route-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const bundle = await build({ entryPoints: [join(root, 'resources/js/utils/courierRoadRoute.ts')], bundle: true, platform: 'node', format: 'esm', write: false });
const file = join(temporary, 'route.mjs');
writeFileSync(file, bundle.outputFiles[0].text);
const { parseRoadRoute, fetchRoadRoute } = await import(pathToFileURL(file).href);

const route = { type: 'FeatureCollection', features: [{ type: 'Feature', properties: { legs: [{ steps: [{ from_index: 0, to_index: 1 }] }] }, geometry: {
    type: 'MultiLineString', coordinates: [[[120.98, 14.59], [121.02, 14.55]]],
} }] };

test('road geometry uses provider longitude-latitude order and rejects unsafe results', () => {
    assert.deepEqual(parseRoadRoute(route), [[[14.59, 120.98], [14.55, 121.02]]]);
    assert.throws(() => parseRoadRoute({ ...route, features: [{ ...route.features[0], properties: { ...route.features[0].properties, ferry: true } }] }));
    assert.throws(() => parseRoadRoute({ ...route, features: [{ ...route.features[0], properties: {} }] }));
    assert.throws(() => parseRoadRoute({ ...route, features: [{ ...route.features[0], geometry: { type: 'LineString', coordinates: [[181, 14], [121, 14]] } }] }));
    assert.throws(() => parseRoadRoute({ ...route, features: [{ ...route.features[0], geometry: { type: 'LineString', coordinates: Array.from({ length: 10001 }, () => [121, 14]) } }] }));
    assert.throws(() => parseRoadRoute({ type: 'FeatureCollection', features: [] }));
});

test('routing request uses current endpoints, chosen mode and no ferry shortcut', async () => {
    let seen;
    const request = async (url, options) => { seen = { url, options }; return new Response(JSON.stringify(route)); };
    const signal = new AbortController().signal;
    assert.deepEqual(await fetchRoadRoute([14.59, 120.98], [14.55, 121.02], 'motorcycle', 'test-key', signal, request), [[[14.59, 120.98], [14.55, 121.02]]]);
    const url = new URL(seen.url);
    assert.equal(url.origin, 'https://api.geoapify.com');
    assert.equal(url.searchParams.get('waypoints'), '14.59,120.98|14.55,121.02');
    assert.equal(url.searchParams.get('mode'), 'motorcycle');
    assert.equal(url.searchParams.get('avoid'), 'ferries');
    assert.equal(seen.options.credentials, 'omit');
    assert.equal(seen.options.signal, signal);
    await assert.rejects(fetchRoadRoute([13, 120], [14.55, 121.02], 'drive', 'test-key', signal, request), /unavailable/);
});

test('invalid input and provider failures never disclose the API key', async () => {
    let requests = 0;
    const request = async () => { requests++; throw new Error('provider URL contains private-test-key'); };
    const signal = new AbortController().signal;
    await assert.rejects(fetchRoadRoute([91, 121], [14, 121], 'drive', 'private-test-key', signal, request), /unavailable/);
    assert.equal(requests, 0);
    await assert.rejects(fetchRoadRoute([14, 121], [15, 121], 'drive', 'private-test-key', signal, request), error => !error.message.includes('private-test-key'));
    assert.equal(requests, 1);
    await assert.rejects(fetchRoadRoute([14, 121], [15, 121], 'drive', 'private-test-key', signal,
        async () => new Response('', { headers: { 'content-length': '2000001' } })), /unavailable/);
});
