import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { buildRoadPath, buildSample, roadDistance } from '../../scripts/build-rider-delivery-sample.mjs';

const way = (id, nodes, tags = {}) => ({ id, nodes, tags: { highway: 'residential', ...tags } });
const fixture = () => ({
    origin: 'a', destination: 'c',
    nodes: { a: [14, 121], b: [14, 121.001], c: [14, 121.002], d: [14.002, 121.001] },
    ways: [way('short', ['a', 'b', 'c']), way('long', ['a', 'd', 'c'])], restrictions: [],
});

test('the road path minimizes actual distance and retains every bend', () => {
    const route = buildRoadPath(fixture());
    assert.deepEqual(route.nodeIds, ['a', 'b', 'c']);
    assert.deepEqual(route.wayIds, ['short', 'short']);
    assert.equal(route.distanceMeters, Math.round(roadDistance([14, 121], [14, 121.001]) * 2));
});
test('one-way and reverse one-way roads cannot be traversed backwards', () => {
    const network = fixture();
    network.ways = [way('one', ['a', 'b', 'c'], { oneway: 'yes' })];
    assert.ok(buildRoadPath(network));
    assert.equal(buildRoadPath(network, 'c', 'a'), null);
    network.ways[0].tags.oneway = '-1';
    assert.equal(buildRoadPath(network), null);
    assert.ok(buildRoadPath(network, 'c', 'a'));
});
test('restricted roads, footpaths and missing geometry cannot become shortcuts', () => {
    for (const tags of [{ access: 'private' }, { motor_vehicle: 'no' }, { highway: 'footway' }, { highway: 'construction' }, { area: 'yes' }]) {
        const network = fixture(); network.ways[0].tags = { ...network.ways[0].tags, ...tags };
        assert.deepEqual(buildRoadPath(network).nodeIds, ['a', 'd', 'c']);
    }
    const network = fixture(); network.ways = [way('broken', ['a', 'missing', 'c'])];
    assert.equal(buildRoadPath(network), null);
});
test('crossing geometry does not create an intersection without a shared road node', () => {
    const network = fixture();
    network.nodes.e = [13.999, 121.001]; network.nodes.f = [14.001, 121.001];
    network.ways = [way('east-west', ['a', 'c']), way('north-south', ['e', 'f'])];
    assert.equal(buildRoadPath(network, 'a', 'f'), null);
});
test('node-via turn restrictions select a permitted detour', () => {
    const network = fixture();
    network.ways = [way('in', ['a', 'b']), way('out', ['b', 'c']), way('detour', ['b', 'd', 'c'])];
    network.restrictions = [{ from: 'in', via: 'b', to: 'out', type: 'no_right_turn' }];
    assert.deepEqual(buildRoadPath(network).nodeIds, ['a', 'b', 'd', 'c']);
    network.restrictions = [{ from: 'in', via: 'b', to: 'detour', type: 'only_left_turn' }];
    assert.deepEqual(buildRoadPath(network).nodeIds, ['a', 'b', 'd', 'c']);
});
test('disconnected or invalid endpoints never produce an invented direct route', () => {
    const network = fixture(); network.ways = [];
    assert.equal(buildRoadPath(network), null);
    assert.throws(() => buildSample(network), /connected road path/);
    assert.equal(buildRoadPath(fixture(), 'missing', 'c'), null);
});
test('every shipped sample segment matches consecutive OpenStreetMap road nodes', () => {
    const network = JSON.parse(readFileSync(new URL('../../scripts/data/rider-sample-roads.json', import.meta.url)));
    const sample = JSON.parse(readFileSync(new URL('../../resources/js/data/riderDeliverySample.json', import.meta.url)));
    assert.deepEqual(buildSample(network), sample);
    assert.equal(sample.nodeIds.length, sample.wayIds.length + 1);
    for (let i = 0; i < sample.wayIds.length; i++) {
        const road = network.ways.find(way => way.id === sample.wayIds[i]);
        const a = road.nodes.indexOf(sample.nodeIds[i]), b = road.nodes.indexOf(sample.nodeIds[i + 1]);
        assert.equal(Math.abs(a - b), 1);
        assert.deepEqual(sample.coordinates[i], network.nodes[sample.nodeIds[i]]);
        assert.deepEqual(sample.coordinates[i + 1], network.nodes[sample.nodeIds[i + 1]]);
    }
    assert.ok(sample.distanceMeters > 500 && sample.distanceMeters < 1500);
    assert.equal(sample.source.license, 'ODbL-1.0');
});
