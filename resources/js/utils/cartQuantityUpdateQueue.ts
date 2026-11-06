export const CART_QUANTITY_DEBOUNCE_MS = 260;

export type CartQuantityChange<Item extends { id: number }> = {
    item: Item;
    quantity: number;
};

export type CartQuantityTimers = {
    setTimeout: (callback: () => void, delay: number) => number;
    clearTimeout: (handle: number) => void;
};

export function createCartQuantityUpdateQueue<Item extends { id: number }>(
    send: (change: CartQuantityChange<Item>) => Promise<void>,
    delay = CART_QUANTITY_DEBOUNCE_MS,
    timers: CartQuantityTimers = {
        setTimeout: (callback, milliseconds) => window.setTimeout(callback, milliseconds),
        clearTimeout: handle => window.clearTimeout(handle),
    },
) {
    const pending = new Map<number, CartQuantityChange<Item>>();
    let timer: number | null = null;
    let inFlightItemId: number | null = null;
    let disposed = false;

    const schedule = (milliseconds = delay) => {
        if (timer !== null) timers.clearTimeout(timer);
        timer = timers.setTimeout(() => {
            timer = null;
            flush();
        }, milliseconds);
    };

    const flush = () => {
        if (disposed || inFlightItemId !== null) return;
        const next = pending.entries().next().value as [number, CartQuantityChange<Item>] | undefined;
        if (!next) return;

        const [itemId, change] = next;
        pending.delete(itemId);
        inFlightItemId = itemId;
        let request: Promise<void>;
        try {
            request = Promise.resolve(send(change));
        } catch {
            request = Promise.resolve();
        }

        void request.catch(() => undefined).finally(() => {
            inFlightItemId = null;
            if (!disposed && pending.size > 0) schedule(0);
        });
    };

    return {
        enqueue(change: CartQuantityChange<Item>) {
            if (disposed) return;
            pending.set(change.item.id, change);
            schedule();
        },
        cancel(itemId: number): boolean {
            if (inFlightItemId === itemId) return false;
            const removed = pending.delete(itemId);
            if (pending.size === 0 && timer !== null) {
                timers.clearTimeout(timer);
                timer = null;
            }
            return removed;
        },
        hasQueued(itemId: number) {
            return pending.has(itemId);
        },
        isSending(itemId: number) {
            return inFlightItemId === itemId;
        },
        dispose() {
            disposed = true;
            pending.clear();
            if (timer !== null) timers.clearTimeout(timer);
            timer = null;
        },
    };
}
