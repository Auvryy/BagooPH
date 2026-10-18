import { useEffect, useRef } from 'react';
import { router, usePage, usePoll } from '@inertiajs/react';
import { catalogueAccountKey } from '@/utils/cataloguePrefetch';
import { SupersededCatalogueVisit } from '@/utils/catalogueNavigation';

export function canRefreshOperations(busy: boolean, visible: boolean, online: boolean) {
    return !busy && visible && online;
}

/** Refresh visible work queues without overlapping requests or interrupting a submitted action. */
export default function useOperationalRefresh(busy: boolean, only: string[]) {
    const context = catalogueAccountKey(usePage());
    const blocked = useRef(busy);
    blocked.current = busy;
    const cancellation = useRef<{ cancel: () => void } | null>(null);
    const generation = useRef(0);
    const foreground = useRef(0);
    const cancel = () => { generation.current++; cancellation.current?.cancel(); cancellation.current = null; };
    usePoll(15_000, () => {
        const ticket = generation.current;
        let token: { cancel: () => void };
        return {
            only: [...new Set(['auth', ...only])],
            headers: { 'X-Bagoo-Queue-Refresh': '1' },
            onBefore: () => canRefreshOperations(blocked.current || foreground.current > 0, document.visibilityState === 'visible', navigator.onLine !== false),
            onCancelToken: value => { token = value; cancellation.current = value; },
            onBeforeUpdate: () => { if (ticket !== generation.current) throw new SupersededCatalogueVisit(); },
            onFinish: () => { if (cancellation.current === token) cancellation.current = null; },
        };
    }, { mode: 'rest' });
    useEffect(() => {
        const intentional = (visit: { prefetch?: boolean; headers: Record<string, string> }) => !visit.prefetch && visit.headers.Purpose !== 'prefetch' && visit.headers['X-Bagoo-Queue-Refresh'] !== '1';
        const before = router.on('before', event => { if (intentional(event.detail.visit)) cancel(); });
        const start = router.on('start', event => { if (intentional(event.detail.visit)) foreground.current++; });
        const finish = router.on('finish', event => { if (intentional(event.detail.visit)) foreground.current = Math.max(0, foreground.current - 1); });
        const unavailable = () => { if (document.visibilityState !== 'visible' || navigator.onLine === false) cancel(); };
        document.addEventListener('visibilitychange', unavailable);
        window.addEventListener('offline', unavailable);
        return () => { before(); start(); finish(); document.removeEventListener('visibilitychange', unavailable); window.removeEventListener('offline', unavailable); cancel(); };
    }, []);
    useEffect(() => { if (busy) cancel(); }, [busy]);
    useEffect(() => () => cancel(), [context]);
}
