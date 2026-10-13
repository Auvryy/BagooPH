import { useEffect } from 'react';
import { redirectAuthenticatedSession } from '@/utils/authSession';

type Options = {
    events?: Pick<Window, 'addEventListener' | 'removeEventListener'>;
    visibility?: Pick<Document, 'addEventListener' | 'removeEventListener' | 'visibilityState'>;
    request?: (signal: AbortSignal) => Promise<{ data: unknown; status: number }>;
    redirect?: (data: unknown, status: number) => unknown;
    timeoutMs?: number;
};

export function watchGuestSession(options: Options = {}): () => void {
    const events = options.events ?? window;
    const visibility = options.visibility ?? document;
    const redirect = options.redirect ?? redirectAuthenticatedSession;
    const request = options.request ?? (async signal => {
        const response = await fetch('/auth/session', { headers: { Accept: 'application/json' }, cache: 'no-store', credentials: 'same-origin', signal });
        return { data: await response.json(), status: response.status };
    });
    let active: AbortController | undefined;
    let activeTimeout: ReturnType<typeof setTimeout> | undefined;
    let disposed = false;
    const check = async () => {
        if (disposed || active || visibility.visibilityState === 'hidden') return;
        const current = new AbortController();
        active = current;
        const timeout = setTimeout(() => {
            if (active === current) {
                active = undefined;
                current.abort();
            }
        }, options.timeoutMs ?? 10000);
        activeTimeout = timeout;
        try {
            const reply = await request(current.signal);
            if (!disposed && active === current && !current.signal.aborted) redirect(reply.data, reply.status);
        } catch {
            // A failed session read cannot establish a login or discard form input.
        } finally {
            clearTimeout(timeout);
            if (activeTimeout === timeout) activeTimeout = undefined;
            if (active === current) active = undefined;
        }
    };
    events.addEventListener('focus', check);
    events.addEventListener('pageshow', check);
    visibility.addEventListener('visibilitychange', check);
    void check();
    return () => {
        disposed = true;
        active?.abort();
        clearTimeout(activeTimeout);
        events.removeEventListener('focus', check);
        events.removeEventListener('pageshow', check);
        visibility.removeEventListener('visibilitychange', check);
    };
}

export function useGuestSessionGuard(enabled: boolean) {
    useEffect(() => enabled ? watchGuestSession() : undefined, [enabled]);
}
