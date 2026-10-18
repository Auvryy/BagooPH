import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, relative, resolve, sep } from 'node:path';
import { performance } from 'node:perf_hooks';
import { pathToFileURL } from 'node:url';
import { gzipSync } from 'node:zlib';
import { buildSync } from 'esbuild';

// A repeatable transport experiment, not browser automation or a production speed test.
const args = process.argv.slice(2);
if (args.includes('--help')) {
    console.log('node scripts/measure-navigation.mjs --endpoint-before <repo JSON> --endpoint-after <repo JSON> --output <repo JSON> [--samples 10]');
    process.exit(0);
}
const options = Object.fromEntries(Array.from({ length: args.length / 2 }, (_, i) => [args[i * 2], args[i * 2 + 1]]));
const root = resolve(import.meta.dirname, '..');
function localPath(value) {
    assert.ok(value, 'Provide both endpoint reports and an output path.');
    const path = resolve(root, value);
    assert.ok(path.startsWith(root + sep), 'Measurements must remain inside this repository.');
    return path;
}
const output = localPath(options['--output']);
const before = JSON.parse(readFileSync(localPath(options['--endpoint-before']), 'utf8'));
const after = JSON.parse(readFileSync(localPath(options['--endpoint-after']), 'utf8'));
assert.deepEqual(before.fixture, after.fixture, 'Use the same isolated fixture scale, seed and clock.');
const repetitions = Number(options['--samples'] ?? 10);
assert.ok(Number.isInteger(repetitions) && repetitions >= 10 && repetitions <= 30);
const directory = resolve(root, '.codex/navigation-measurement');
mkdirSync(directory, { recursive: true });
const compiled = buildSync({
    stdin: { contents: "export * from '@/utils/cataloguePrefetch'; export * from '@/utils/catalogueSearch'; export * from '@/utils/portalNavigation';", resolveDir: root },
    bundle: true, packages: 'external', platform: 'node', format: 'esm', write: false,
});
const modulePath = resolve(directory, `policy-${process.pid}.mjs`);
writeFileSync(modulePath, compiled.outputFiles[0].text);
const ui = await import(pathToFileURL(modulePath).href);
const manifest = JSON.parse(readFileSync(resolve(root, 'public/build/manifest.json'), 'utf8'));
function graph(key, seen = new Set()) {
    if (seen.has(key)) return [];
    seen.add(key);
    const entry = manifest[key];
    assert.ok(entry, `Build the application first; missing ${key}.`);
    return [entry.file, ...(entry.css ?? []), ...(entry.imports ?? []).flatMap(child => graph(child, seen))];
}
const sizes = new Map();
function assetSize(component, from) {
    const key = `${from}:${component}`;
    if (sizes.has(key)) return sizes.get(key);
    const initial = new Set([...graph('resources/js/app.tsx'), ...graph(`resources/js/Pages/${from}.tsx`)]);
    const files = [...new Set(graph(`resources/js/Pages/${component}.tsx`))].filter(file => !initial.has(file));
    const result = { from, files, raw: files.reduce((n, file) => n + readFileSync(resolve(root, 'public/build', file)).length, 0),
        gzip: files.reduce((n, file) => n + gzipSync(readFileSync(resolve(root, 'public/build', file))).length, 0) };
    sizes.set(key, result); return result;
}
function blanketBudget(from) {
    const seen = new Set();
    const discover = key => {
        if (seen.has(key)) return [];
        seen.add(key);
        const entry = manifest[key];
        return [entry.file, ...(entry.css ?? []), ...[...(entry.imports ?? []), ...(entry.dynamicImports ?? [])].flatMap(discover)];
    };
    const loaded = new Set([...graph('resources/js/app.tsx'), ...graph(`resources/js/Pages/${from}.tsx`)]);
    const files = [...new Set(discover('resources/js/app.tsx'))].filter(file => !loaded.has(file));
    return { files: files.length, raw_bytes: files.reduce((n, file) => n + readFileSync(resolve(root, 'public/build', file)).length, 0),
        calculated_gzip_bytes: files.reduce((n, file) => n + gzipSync(readFileSync(resolve(root, 'public/build', file))).length, 0) };
}
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const timing = { data_ms: 40, code_ms: 70 };
function stats(values) {
    const sorted = [...values].sort((a, b) => a - b);
    const quantile = fraction => {
        const at = (sorted.length - 1) * fraction, low = Math.floor(at);
        return sorted[low] + (sorted[Math.ceil(at)] - sorted[low]) * (at - low);
    };
    const mean = values.reduce((a, b) => a + b, 0) / values.length;
    const round = value => Math.round(value * 1000) / 1000;
    return { n: values.length, median: round(quantile(0.5)), p90: round(quantile(0.9)), min: round(sorted[0]), max: round(sorted.at(-1)),
        mean: round(mean), standard_deviation: round(Math.sqrt(values.reduce((sum, value) => sum + (value - mean) ** 2, 0) / values.length)) };
}
const targets = [
    { role: 'buyer', case: 'catalogue', from: 'Buyer/Home', component: 'Buyer/Search', data: true },
    { role: 'buyer', case: 'buyer', from: 'Buyer/Home', component: 'Buyer/Profile' },
    { role: 'seller', case: 'seller', from: 'Seller/Dashboard', component: 'Seller/Products' },
    { role: 'courier', case: 'rider', from: 'Courier/Profile', component: 'Courier/Deliveries' },
    { role: 'logistics', case: 'logistics', from: 'Hub/Dashboard', component: 'Hub/Deliveries' },
    { role: 'admin', case: 'admin_users', from: 'Admin/Dashboard', component: 'Admin/Users' },
].map(target => ({ ...target, href: after.cases[target.case].route }));
function experiment(target, enabled, failPreparation = false) {
    const bytes = after.cases[target.case].samples[0].response_bytes;
    const asset = assetSize(target.component, target.from);
    const events = [], modules = new Map(), cached = new Set();
    let elapsed = 0, speculative = 0, maximum = 0, failurePending = failPreparation;
    const snapshot = { component: target.from, version: 'measured-build', props: {
        cataloguePrefetchEnabled: enabled, auth: { user: { id: 7, role: target.role, status: 'active', kyc_status: 'approved' } }, errors: {}, flash: {},
    } };
    const env = { origin: 'https://bagoo.test', currentUrl: 'https://bagoo.test/current', online: true, visible: true, connection: { effectiveType: '4g' } };
    const request = async (kind, speculativeRequest, delay, payload) => {
        const row = { kind, speculative: speculativeRequest, ...payload, cancelled: false };
        events.push(row);
        if (speculativeRequest) { speculative++; maximum = Math.max(maximum, speculative); }
        try {
            await sleep(delay);
            if (speculativeRequest && failurePending) {
                failurePending = false; row.failed = true; row.raw_bytes = 0;
                if (kind === 'static') row.gzip_bytes = 0;
                throw new Error('Controlled preparation failure');
            }
        } finally { if (speculativeRequest) speculative--; }
        return row;
    };
    const loadCode = speculativeRequest => {
        if (!modules.has(target.component)) modules.set(target.component, request('static', speculativeRequest, timing.code_ms,
            { raw_bytes: asset.raw, gzip_bytes: asset.gzip }).catch(error => { modules.delete(target.component); throw error; }));
        return modules.get(target.component);
    };
    const policy = new ui.CataloguePrefetch({
        prepare(url) {
            let cancelled = false;
            const done = request('data', true, timing.data_ms, { raw_bytes: bytes }).then(async row => {
                if (cancelled) { row.cancelled = true; return false; }
                // Match the runtime's catalogue transport: code readiness is part of readiness.
                await loadCode(true);
                if (cancelled) return false;
                cached.add(url); return true;
            });
            return { done, cancel() { cancelled = true; cached.delete(url); } };
        },
        flush(url) { cached.delete(url); }, prepareComponent() { return loadCode(true); },
    }, () => env, () => elapsed);
    policy.observe(snapshot);
    if (!target.data) assert.equal(ui.portalPageComponent(target.href, env.origin, snapshot), target.component);
    const prepare = () => target.data ? policy.prepare(target.href) : policy.prepareCode(target.component);
    const click = async () => {
        const start = performance.now();
        const hit = target.data ? await policy.ready(target.href) : false;
        const atClick = events.length;
        if (target.data && hit) assert.ok(cached.has(ui.canonicalCatalogueUrl(target.href, env.origin)));
        else await request('data', false, timing.data_ms, { raw_bytes: bytes });
        await loadCode(false);
        return { page_ready_ms: performance.now() - start, reused_data: hit, foreground_started: events.length > atClick || hit };
    };
    return { policy, events, prepare, click, expire() { elapsed += ui.cataloguePolicy.ttl + 1; },
        result: () => ({ maximum_speculative_in_flight: maximum, data_requests: events.filter(x => x.kind === 'data').length,
            errors: events.filter(x => x.failed).length, cancellations: events.filter(x => x.cancelled).length,
            foreground_data_requests: events.filter(x => x.kind === 'data' && !x.speculative).length,
            speculative_data_requests: events.filter(x => x.kind === 'data' && x.speculative).length,
            static_requests: events.filter(x => x.kind === 'static').length,
            data_bytes: events.filter(x => x.kind === 'data').reduce((n, x) => n + x.raw_bytes, 0),
            static_raw_bytes: events.filter(x => x.kind === 'static').reduce((n, x) => n + x.raw_bytes, 0),
            static_gzip_bytes: events.filter(x => x.kind === 'static').reduce((n, x) => n + x.gzip_bytes, 0) }) };
}
const rows = [];
try {
    for (const target of targets) {
        for (const scenario of ['cold', 'completed', 'in_flight', 'expired']) {
            const samples = [];
            for (let iteration = 0; iteration < repetitions; iteration++) {
                const pair = {};
                // Alternate order to reduce a consistent scheduling-order bias.
                for (const enabled of iteration % 2 ? [true, false] : [false, true]) {
                    const f = experiment(target, enabled);
                    let preparing;
                    if (scenario !== 'cold') {
                        preparing = f.prepare();
                        if (scenario === 'in_flight') await sleep(20);
                        else await preparing;
                        if (scenario === 'expired') f.expire();
                    }
                    const navigation = await f.click();
                    await preparing;
                    const result = { ...navigation, ...f.result() };
                    assert.ok(result.maximum_speculative_in_flight <= 1);
                    assert.equal(result.data_requests, scenario === 'expired' && enabled && target.data ? 2 : 1);
                    if (!target.data) assert.equal(result.speculative_data_requests, 0);
                    if (enabled && target.data && ['completed', 'in_flight'].includes(scenario)) assert.equal(result.foreground_data_requests, 0);
                    pair[enabled ? 'enabled' : 'disabled'] = result;
                }
                samples.push(pair);
            }
            rows.push({ role: target.role, case: target.case, route: target.href, from: target.from, component: target.component, scenario,
                disabled: stats(samples.map(x => x.disabled.page_ready_ms)), enabled: stats(samples.map(x => x.enabled.page_ready_ms)), samples });
        }
    }
    const abandoned = [];
    for (const target of targets) {
        const f = experiment(target, true); await f.prepare();
        abandoned.push({ case: target.case, ...f.result() });
    }
    const adverse = [];
    for (const target of [targets[0], targets.at(-1)]) {
        for (const scenario of ['failed_preparation', 'cancelled_preparation']) {
            const samples = [];
            for (let iteration = 0; iteration < repetitions; iteration++) {
                const f = experiment(target, true, scenario === 'failed_preparation');
                const work = f.prepare();
                if (scenario === 'cancelled_preparation') { await sleep(20); f.policy.clear(); }
                else assert.equal(await work, false);
                const navigation = await f.click(); await work;
                const result = { ...navigation, ...f.result() };
                assert.equal(result.reused_data, false);
                assert.equal(result.foreground_data_requests, 1);
                assert.ok(result.maximum_speculative_in_flight <= 1);
                if (scenario === 'failed_preparation') assert.equal(result.errors, 1);
                samples.push(result);
            }
            adverse.push({ case: target.case, scenario, page_ready_ms: stats(samples.map(x => x.page_ready_ms)), samples });
        }
    }
    const searches = [];
    for (let iteration = 0; iteration < repetitions; iteration++) {
        let ready;
        const done = new Promise(resolve => { ready = resolve; });
        let visits = 0, sentAt;
        const input = new ui.CatalogueSearch({ base: () => '/buyer/search', filters: () => ({ category: 'pet-supplies' }), resultsPage: () => true,
            prepare: async () => false, cancelPreparation() {}, notify() {},
            visit(url, options) {
                visits++; sentAt = performance.now();
                assert.equal(new URL(url, 'https://bagoo.test').searchParams.get('search'), 'Benchmark');
                void sleep(timing.data_ms).then(() => { options.onBeforeUpdate(); options.onSuccess(); options.onFinish(); ready(); });
            } });
        const start = performance.now(); input.update('Bench'); input.update('Benchmark');
        const returned = performance.now(); await done; const finished = performance.now(); input.dispose();
        assert.equal(visits, 1);
        searches.push({ input_return_ms: returned - start, settled_to_request_ms: sentAt - start, settled_to_ready_ms: finished - start, foreground_requests: visits });
    }
    const endpoint = {};
    for (const [name, candidate] of Object.entries(after.cases)) {
        const baseline = before.cases[name];
        assert.equal(candidate.route, baseline.route); assert.equal(candidate.partial, baseline.partial);
        assert.equal(baseline.samples.length, 10); assert.equal(candidate.samples.length, 10);
        endpoint[name] = { route: candidate.route, partial: candidate.partial,
            before: Object.fromEntries(['endpoint_ms', 'queries', 'database_ms', 'response_bytes'].map(key => [key, stats(baseline.samples.map(x => x[key]))])),
            after: Object.fromEntries(['endpoint_ms', 'queries', 'database_ms', 'response_bytes'].map(key => [key, stats(candidate.samples.map(x => x[key]))])) };
    }
    const report = { schema: 1, client: { runtime: process.version, platform: process.platform, architecture: process.arch,
        kind: 'Node controlled transport using the actual shared policy and search controller; no DOM, real network, image decoding or rendering',
        delays: timing, samples_per_scenario: repetitions, scenarios: rows, abandoned, adverse,
        search: { input_return_ms: stats(searches.map(x => x.input_return_ms)), settled_to_request_ms: stats(searches.map(x => x.settled_to_request_ms)),
            settled_to_ready_ms: stats(searches.map(x => x.settled_to_ready_ms)), samples: searches } },
        endpoint: { kind: before.environment, fixture: before.fixture, scenarios: endpoint },
        build: { manifest_sha256: createHash('sha256').update(readFileSync(resolve(root, 'public/build/manifest.json'))).digest('hex'),
            removed_blanket_prefetch: { kind: 'Manifest budget of the removed load-triggered Vite waterfall; not measured network traffic',
                concurrency: 3, from: Object.fromEntries(targets.map(target => [target.from, blanketBudget(target.from)])) },
            assets: Object.fromEntries(targets.map(target => [target.component, assetSize(target.component, target.from)])) },
        limitations: ['Controlled transport compares the same candidate build with preparation disabled/enabled.',
            'Endpoint samples compare the pre-change and candidate code on equivalent isolated fixtures; no deployment data is used.',
            'Payload bytes are uncompressed JSON and static source bytes; gzip size is calculated, not a measured network transfer.',
            'Static byte budgets exclude the already-loaded app and source page; per-page galleries are never fetched by this experiment.',
            'The blanket-prefetch comparison is an eligible manifest budget. Browsers can ignore hints or already have files cached.',
            'Cancelled data bytes conservatively count the full response budget; cancellation does not prove server work or transfer stopped.',
            'Cache use and abandoned work describe these scenarios, not a production use rate.',
            'Browser click-to-render, real network/device behavior, Azure timing and PostgreSQL concurrency remain unverified.'] };
    mkdirSync(dirname(output), { recursive: true }); writeFileSync(output, JSON.stringify(report, null, 2) + '\n');
    console.log(JSON.stringify({ output: relative(root, output), cases: rows.length, samples_per_case: repetitions, role_coverage: targets.map(x => x.case), passed: true }));
} finally { rmSync(modulePath, { force: true }); }
