import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';

const root = resolve(import.meta.dirname, '../..');
const temporary = mkdtempSync(join(tmpdir(), 'bagoo-cart-quantity-'));
after(() => rmSync(temporary, { recursive: true, force: true }));

const bundle = await build({
    entryPoints: [resolve(root, 'resources/js/utils/cartQuantityUpdateQueue.ts')],
    bundle: true,
    platform: 'node',
    format: 'esm',
    write: false,
});
const modulePath = join(temporary, 'cart-quantity-queue.mjs');
writeFileSync(modulePath, bundle.outputFiles[0].text);
const { createCartQuantityUpdateQueue } = await import(pathToFileURL(modulePath).href);

function fakeTimers() {
    let now = 0;
    let nextId = 1;
    const tasks = new Map();
    return {
        setTimeout(callback, delay) {
            const id = nextId++;
            tasks.set(id, { at: now + delay, callback });
            return id;
        },
        clearTimeout(id) { tasks.delete(id); },
        async advance(milliseconds) {
            now += milliseconds;
            for (let turns = 0; turns < 100; turns++) {
                const next = [...tasks.entries()].filter(([, task]) => task.at <= now).sort((a, b) => a[1].at - b[1].at)[0];
                if (next) {
                    tasks.delete(next[0]);
                    next[1].callback();
                }
                for (let microtask = 0; microtask < 4; microtask++) await Promise.resolve();
                if (!next && ![...tasks.values()].some(task => task.at <= now)) return;
            }
            throw new Error('fake timer loop exceeded 100 turns');
        },
    };
}

test('rapid quantity changes batch to the final value and queued requests serialize without losing later edits', async () => {
    const timers = fakeTimers();
    const sent = [];
    const replies = [];
    let activeRequests = 0;
    let maximumConcurrentRequests = 0;
    const queue = createCartQuantityUpdateQueue(change => {
        sent.push({ itemId: change.item.id, quantity: change.quantity });
        activeRequests++;
        maximumConcurrentRequests = Math.max(maximumConcurrentRequests, activeRequests);
        return new Promise(resolve => replies.push(() => {
            activeRequests--;
            resolve();
        }));
    }, 260, timers);
    const firstItem = { id: 10, name: 'First item' };
    const secondItem = { id: 20, name: 'Second item' };

    queue.enqueue({ item: firstItem, quantity: 2 });
    queue.enqueue({ item: firstItem, quantity: 3 });
    queue.enqueue({ item: firstItem, quantity: 4 });
    await timers.advance(259);
    assert.deepEqual(sent, []);
    await timers.advance(1);
    assert.deepEqual(sent, [{ itemId: 10, quantity: 4 }]);
    assert.equal(queue.isSending(firstItem.id), true);

    queue.enqueue({ item: firstItem, quantity: 5 });
    queue.enqueue({ item: secondItem, quantity: 2 });
    await timers.advance(260);
    assert.deepEqual(sent, [{ itemId: 10, quantity: 4 }], 'next quantity waits for the active request');

    replies.shift()();
    await timers.advance(0);
    assert.deepEqual(sent, [
        { itemId: 10, quantity: 4 },
        { itemId: 10, quantity: 5 },
    ], 'the latest edit to the in-flight item is sent next');

    replies.shift()();
    await timers.advance(0);
    assert.deepEqual(sent, [
        { itemId: 10, quantity: 4 },
        { itemId: 10, quantity: 5 },
        { itemId: 20, quantity: 2 },
    ], 'the other item update is retained and sent after the first item');

    replies.shift()();
    await timers.advance(0);
    assert.equal(maximumConcurrentRequests, 1);
    assert.equal(queue.isSending(firstItem.id), false);
    assert.equal(queue.isSending(secondItem.id), false);
    assert.equal(queue.hasQueued(firstItem.id), false);
    assert.equal(queue.hasQueued(secondItem.id), false);
    queue.dispose();
});

test('Cart sends queued quantity changes without resetting page state or scroll', () => {
    const source = readFileSync(resolve(root, 'resources/js/Pages/Cart/Index.tsx'), 'utf8');
    const queueSend = source.slice(source.indexOf('createCartQuantityUpdateQueue<CartItem>'), source.indexOf('const removalFailed'));
    assert.match(source, /createCartQuantityUpdateQueue<CartItem>/);
    assert.match(queueSend, /router\.patch\(route\('cart\.update', itemId\)/);
    assert.match(queueSend, /preserveScroll:\s*true/);
    assert.match(queueSend, /preserveState:\s*true/);
    assert.match(source, /revision:\s*JSON\.stringify\(items\.map\(item =>/);
    assert.doesNotMatch(source, /revision:\s*JSON\.stringify\(\[quantityUpdates/);
});
