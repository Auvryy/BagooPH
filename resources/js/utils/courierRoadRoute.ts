export type RoadPoint = [number, number]; // latitude, longitude
export type RoadMode = 'motorcycle' | 'scooter' | 'drive';

export function validRoadPoint(point: RoadPoint): boolean {
    return point.length === 2 && Number.isFinite(point[0]) && Number.isFinite(point[1])
        && Math.abs(point[0]) <= 85.05112878 && Math.abs(point[1]) <= 180;
}

export function roadPointDistance(a: RoadPoint, b: RoadPoint): number {
    const radians = Math.PI / 180;
    const dLat = (b[0] - a[0]) * radians, dLon = (b[1] - a[1]) * radians;
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(a[0] * radians) * Math.cos(b[0] * radians) * Math.sin(dLon / 2) ** 2;
    return 12742000 * Math.asin(Math.min(1, Math.sqrt(h)));
}

function includesFerry(value: unknown, budget = { count: 0 }, depth = 0): boolean {
    if (++budget.count > 50000 || depth > 32) throw new Error('Route details are too large.');
    if (Array.isArray(value)) return value.some(item => includesFerry(item, budget, depth + 1));
    if (value && typeof value === 'object') return Object.entries(value).some(([key, item]) =>
        (key === 'ferry' && item === true) || (['kind', 'road_class'].includes(key) && item === 'ferry') || includesFerry(item, budget, depth + 1));
    return false;
}

export function parseRoadRoute(value: unknown): RoadPoint[][] {
    if (!value || typeof value !== 'object') throw new Error('Route geometry is unavailable.');
    const collection = value as { type?: unknown; features?: unknown };
    if (collection.type !== 'FeatureCollection' || !Array.isArray(collection.features) || collection.features.length < 1) throw new Error('Route geometry is unavailable.');
    const feature = collection.features[0];
    if (!feature || typeof feature !== 'object' || feature.type !== 'Feature' || !feature.properties || typeof feature.properties !== 'object'
        || !Array.isArray(feature.properties.legs) || feature.properties.legs.length < 1
        || feature.properties.legs.some((leg: { steps?: unknown }) => !leg || !Array.isArray(leg.steps) || leg.steps.length < 1)
        || includesFerry(feature.properties)) throw new Error('A supported road-only route was not returned.');
    const geometry = feature.geometry;
    if (!geometry || typeof geometry !== 'object') throw new Error('Route geometry is unavailable.');
    const lines = geometry.type === 'LineString' ? [geometry.coordinates] : geometry.type === 'MultiLineString' ? geometry.coordinates : null;
    if (!Array.isArray(lines) || lines.length < 1 || lines.length > 100) throw new Error('Route geometry is unavailable.');
    let count = 0;
    return lines.map(line => {
        if (!Array.isArray(line) || line.length < 2) throw new Error('Route geometry is unavailable.');
        return line.map(point => {
            if (++count > 10000 || !Array.isArray(point) || point.length < 2 || typeof point[0] !== 'number' || typeof point[1] !== 'number') throw new Error('Route geometry is unavailable.');
            const result: RoadPoint = [point[1], point[0]];
            if (!validRoadPoint(result)) throw new Error('Route geometry is unavailable.');
            return result;
        });
    });
}

async function readLimited(response: Response): Promise<string> {
    if (!response.body) return '';
    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let bytes = 0, text = '';
    try {
        while (true) {
            const part = await reader.read();
            if (part.done) return text + decoder.decode();
            bytes += part.value.byteLength;
            if (bytes > 2_000_000) { await reader.cancel(); throw new Error('Route response is too large.'); }
            text += decoder.decode(part.value, { stream: true });
        }
    } finally { reader.releaseLock(); }
}

export async function fetchRoadRoute(origin: RoadPoint, destination: RoadPoint, mode: RoadMode, key: string, signal: AbortSignal, request: typeof fetch = fetch): Promise<RoadPoint[][]> {
    if (!validRoadPoint(origin) || !validRoadPoint(destination) || !key.trim() || !['motorcycle', 'scooter', 'drive'].includes(mode)) throw new Error('Road routing is unavailable.');
    const query = new URLSearchParams({ waypoints: `${origin.join(',')}|${destination.join(',')}`, mode, format: 'geojson', avoid: 'ferries', details: 'route_details', apiKey: key.trim() });
    try {
        const response = await request(`https://api.geoapify.com/v1/routing?${query}`, { signal, credentials: 'omit', cache: 'no-store', referrerPolicy: 'strict-origin-when-cross-origin' });
        if (!response.ok || response.redirected) throw new Error('Route request failed.');
        const length = Number(response.headers.get('content-length') || 0);
        if (length > 2_000_000) throw new Error('Route response is too large.');
        const text = await readLimited(response);
        const lines = parseRoadRoute(JSON.parse(text));
        if (roadPointDistance(origin, lines[0][0]) > 1500 || roadPointDistance(destination, lines[lines.length - 1].at(-1)!) > 1500) throw new Error('Route endpoints do not match.');
        return lines;
    } catch (_) {
        if (signal.aborted) throw new DOMException('Route request cancelled.', 'AbortError');
        throw new Error('Road routing is unavailable.');
    }
}
