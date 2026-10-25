import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';

const drivable = new Set(['residential', 'unclassified', 'living_street', 'service', 'tertiary', 'secondary', 'primary', 'tertiary_link', 'secondary_link', 'primary_link']);
const radians = value => value * Math.PI / 180;
export function roadDistance(a, b) {
    const dLat = radians(b[0] - a[0]), dLon = radians(b[1] - a[1]);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(radians(a[0])) * Math.cos(radians(b[0])) * Math.sin(dLon / 2) ** 2;
    return 12742000 * Math.asin(Math.sqrt(Math.min(1, h)));
}

// This bounded, offline graph builds a public illustration, not live navigation.
// Shared OSM node IDs establish junctions; crossing lines alone never do.
export function buildRoadPath(network, origin = network.origin, destination = network.destination) {
    const validPoint = p => Array.isArray(p) && p.length === 2 && p.every(Number.isFinite) && Math.abs(p[0]) <= 85 && Math.abs(p[1]) <= 180;
    if (!validPoint(network.nodes[origin]) || !validPoint(network.nodes[destination])) return null;
    const graph = new Map();
    const add = (from, edge) => graph.set(from, [...(graph.get(from) ?? []), edge]);
    for (const way of network.ways) {
        const tags = way.tags;
        const access = tags.motorcycle ?? tags.motor_vehicle ?? tags.vehicle ?? tags.access;
        if (!drivable.has(tags.highway) || tags.area === 'yes' || (access && !['yes', 'permissive', 'designated'].includes(access))) continue;
        // Never bridge a missing geometry node by joining its neighbours.
        if (way.nodes.length < 2 || !way.nodes.every(id => validPoint(network.nodes[id]))) continue;
        const oneway = tags['oneway:motorcycle'] ?? tags.oneway ?? (tags.junction === 'roundabout' ? 'yes' : 'no');
        if (!['yes', 'true', '1', '-1', 'no', 'false', '0'].includes(oneway)) continue;
        for (let i = 1; i < way.nodes.length; i++) {
            const from = way.nodes[i - 1], to = way.nodes[i];
            if (from === to) continue;
            const meters = roadDistance(network.nodes[from], network.nodes[to]);
            if (meters === 0) continue;
            if (oneway !== '-1') add(from, { to, way: way.id, meters });
            if (!['yes', 'true', '1'].includes(oneway)) add(to, { to: from, way: way.id, meters });
        }
    }
    const key = state => JSON.stringify([state.node, state.way, state.previous]);
    const start = { node: origin, way: null, previous: null, meters: 0 };
    const queue = [start], best = new Map([[key(start), 0]]), parents = new Map();
    let end;
    while (queue.length) {
        queue.sort((a, b) => b.meters - a.meters);
        const current = queue.pop(), currentKey = key(current);
        if (current.meters !== best.get(currentKey)) continue;
        if (current.node === destination) { end = current; break; }
        for (const edge of graph.get(current.node) ?? []) {
            const prohibited = (network.restrictions ?? []).some(rule => {
                if (rule.via !== current.node || rule.from !== current.way) return false;
                const matches = edge.way === rule.to && (!rule.type.endsWith('u_turn') || edge.to === current.previous);
                return rule.type.startsWith('only_') ? !matches : rule.type.startsWith('no_') && matches;
            });
            if (prohibited) continue;
            const next = { node: edge.to, way: edge.way, previous: current.node, meters: current.meters + edge.meters };
            const nextKey = key(next);
            if (next.meters >= (best.get(nextKey) ?? Infinity)) continue;
            best.set(nextKey, next.meters);
            parents.set(nextKey, current);
            queue.push(next);
        }
    }
    // A disconnected road network must not turn into a straight-line route.
    if (!end) return null;
    const nodeIds = [], wayIds = [];
    for (let state = end; state; state = parents.get(key(state))) {
        nodeIds.unshift(state.node);
        if (state.way !== null) wayIds.unshift(state.way);
    }
    return { coordinates: nodeIds.map(id => network.nodes[id]), distanceMeters: Math.round(end.meters), nodeIds, wayIds };
}

export function buildSample(network) {
    const route = buildRoadPath(network);
    if (!route || route.nodeIds.length < 2) throw new Error('The sample needs a connected road path. No direct-line fallback is permitted.');
    return {
        label: 'Sample delivery route',
        notice: 'Illustration only. This is not a live rider or an assigned parcel.',
        riderLabel: 'Sample rider', deliveryLabel: 'Sample delivery point',
        ...route,
        source: network.source,
    };
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const network = JSON.parse(readFileSync(new URL('./data/rider-sample-roads.json', import.meta.url), 'utf8'));
    const sample = JSON.stringify(buildSample(network), null, 2) + '\n';
    const output = new URL('../resources/js/data/riderDeliverySample.json', import.meta.url);
    if (process.argv.includes('--check')) {
        if (readFileSync(output, 'utf8') !== sample) throw new Error('The sample is out of date. Run node scripts/build-rider-delivery-sample.mjs.');
    } else writeFileSync(output, sample);
    console.log(`${process.argv.includes('--check') ? 'Verified' : 'Built'} road sample: ${fileURLToPath(output)}`);
}
