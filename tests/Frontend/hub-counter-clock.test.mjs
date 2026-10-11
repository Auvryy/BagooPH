import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';

const temporary = mkdtempSync(join(import.meta.dirname, '.tmp-counter-clock-'));
after(() => rmSync(temporary, { recursive: true, force: true }));
const bundle = await build({
    stdin: { contents: "export { default as Counter } from '@/Pages/Hub/CounterPickup';", resolveDir: resolve(import.meta.dirname, '../..'), loader: 'tsx' },
    bundle: true, platform: 'node', format: 'esm', packages: 'external', jsx: 'automatic', loader: { '.css': 'empty' }, write: false,
    plugins: [{ name: 'controlled-clock-hooks', setup(builder) {
        builder.onResolve({ filter: /^react$/ }, args => args.namespace === 'counter-clock'
            ? { path: 'react', external: true } : { path: 'counter-clock', namespace: 'counter-clock' });
        builder.onLoad({ filter: /.*/, namespace: 'counter-clock' }, () => ({ contents: `
            import React from 'react'; export * from 'react'; export default React;
            export const useState = value => [value === Date.now ? globalThis.counterClock.mountedAt : (typeof value === 'function' ? value() : value), () => {}];
            export const useRef = value => ({ current: value });
            export const useEffect = effect => { globalThis.counterClock.effect = effect; };
        ` }));
    } }],
});
const path = join(temporary, 'counter.mjs');
writeFileSync(path, bundle.outputFiles[0].text);
const { Counter } = await import(pathToFileURL(path).href);

function scheduledDelay({ mountedAt, now, expiresAt, lockedUntil = null }) {
    const originalNow = Date.now;
    const originalWindow = globalThis.window;
    const delays = [];
    Date.now = () => now;
    // Model the clock state retained while Inertia refreshes this mounted page.
    globalThis.counterClock = { mountedAt, effect: null };
    globalThis.window = { setTimeout: (_, delay) => { delays.push(delay); return 1; }, clearTimeout: () => {} };
    try {
        Counter({ activeHub: null, counterParcels: { data: [{ id: 1, tracking_number: 'BGO-CLOCK', order_number: 'BGO-CLOCK', buyer_name: 'Mara Santos',
            buyer_id: 2, buyer_phone: '', status: 'ready_for_hub_pickup', destination_bin: 'COUNTER', total_amount: 100,
            payment_method: 'cod', item_count: 1, items: [], arrived_at: null,
            claim: { reference: 'PUC-CLOCK', status: 'ready', expires_at: new Date(expiresAt).toISOString(),
                locked_until: lockedUntil === null ? null : new Date(lockedUntil).toISOString() },
            guidance: { stage: 'Staged', order_status: 'Sorted', custodian: 'Destination Bayan Hub', custody_recorded_at: null,
                next_step: 'Verify buyer', responsible: 'Handler', blocker: null, action: 'counter', can_act: true,
                href: null, eligible_rider_ids: [], ready_for_collection: true } }], current_page: 1, last_page: 1, total: 1 },
            recentlyCollected: [], search: '', requestToken: 'CLOCK', canConfigureHours: false, basePath: '/hub', canScan: false,
            counterCounts: { shown: 1, ready: 1, blocked: 0 } });
        const cleanup = globalThis.counterClock.effect();
        cleanup?.();
        return delays;
    } finally {
        Date.now = originalNow;
        if (originalWindow === undefined) delete globalThis.window;
        else globalThis.window = originalWindow;
        delete globalThis.counterClock;
    }
}

const mountedAt = Date.parse('2026-10-11T00:00:00Z');
const now = mountedAt + 2 * 60 * 60 * 1000;
test('a refreshed counter schedules imminent expiry from the current clock', () => {
    assert.deepEqual(scheduledDelay({ mountedAt, now, expiresAt: now + 60_000 }), [60_001]);
});
test('a refreshed counter skips elapsed lock boundaries before scheduling expiry', () => {
    assert.deepEqual(scheduledDelay({ mountedAt, now, expiresAt: now + 120_000, lockedUntil: now - 60_000 }), [120_001]);
});
test('a verification lock ending before expiry schedules the current unlock boundary', () => {
    assert.deepEqual(scheduledDelay({ mountedAt, now, expiresAt: now + 120_000, lockedUntil: now + 30_000 }), [30_001]);
});
test('already elapsed claim and lock boundaries do not start a delayed timer', () => {
    assert.deepEqual(scheduledDelay({ mountedAt, now, expiresAt: now - 60_000, lockedUntil: now - 120_000 }), []);
});
