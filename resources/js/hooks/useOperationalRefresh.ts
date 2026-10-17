import { useEffect, useRef } from 'react';
import { usePoll } from '@inertiajs/react';

export function canRefreshOperations(busy: boolean, visible: boolean, online: boolean) {
    return !busy && visible && online;
}

/** Refresh visible work queues without overlapping requests or interrupting a submitted action. */
export default function useOperationalRefresh(busy: boolean, only: string[]) {
    const blocked = useRef(busy);
    blocked.current = busy;
    const cancellation = useRef<{ cancel: () => void } | null>(null);
    usePoll(15_000, () => ({
        only,
        onBefore: () => canRefreshOperations(blocked.current, document.visibilityState === 'visible', navigator.onLine !== false),
        onCancelToken: token => { cancellation.current = token; },
        onFinish: () => { cancellation.current = null; },
    }), { mode: 'rest' });
    useEffect(() => { if (busy) cancellation.current?.cancel(); }, [busy]);
    useEffect(() => () => { cancellation.current?.cancel(); }, []);
}
