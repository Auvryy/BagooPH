import { router } from '@inertiajs/react';
import { catalogueAccountKey, canonicalCatalogueUrl, CatalogueEnvironment, CataloguePrefetch, CatalogueSnapshot, CatalogueTransport, preparedCatalogueComponent, cataloguePolicy } from './cataloguePrefetch';

export function catalogueEnvironment(): CatalogueEnvironment {
    const connection = (navigator as Navigator & { connection?: CatalogueEnvironment['connection'] }).connection;
    return { origin: window.location.origin, currentUrl: window.location.href, visible: document.visibilityState === 'visible', online: navigator.onLine !== false, connection };
}

const transport: CatalogueTransport = {
    flush: (url, headers) => router.flush(url, { headers }),
    prepare(url, headers, account) {
        let cancel: (() => void) | undefined;
        let cancelled = false;
        let valid = false;
        let finish: (valid: boolean) => void;
        const done = new Promise<boolean>(resolve => { finish = resolve; });
        router.prefetch(url, {
            headers: { ...headers, Purpose: 'prefetch' },
            onCancelToken: token => { cancel = token.cancel; if (cancelled) cancel(); },
            onPrefetched(response) {
                try {
                    const page = typeof response.data === 'string' ? JSON.parse(response.data) : response.data;
                    valid = !cancelled && response.status === 200
                        && page.component === preparedCatalogueComponent(new URL(url, window.location.origin).pathname)
                        && catalogueAccountKey(page) === account
                        && !Object.keys(page.props.errors ?? {}).length
                        && !Object.values(page.props.flash ?? {}).some(Boolean);
                    if (valid) void router.resolveComponent(page.component).catch(() => {});
                } catch { valid = false; }
            },
        }, { cacheFor: cataloguePolicy.ttl, cacheTags: ['catalogue'] });
        // Wait until Inertia has inserted its public in-flight cache handle.
        queueMicrotask(() => {
            const pending = router.getPrefetching(url, { headers }) ?? router.getCached(url, { headers });
            if (!pending) { finish(false); return; }
            void pending.response.then(() => {
                if (!valid || cancelled) router.flush(url, { headers });
                finish(valid && !cancelled);
            }, () => finish(false));
        });
        return { done, cancel: () => { cancelled = true; cancel?.(); finish(false); } };
    },
};

export const cataloguePrefetch = new CataloguePrefetch(transport, catalogueEnvironment);
let navigation = 0;
let broadcast: BroadcastChannel | undefined;
let installed = false;
let foreground = 0;

export async function visitCatalogue(href: string, options: Parameters<typeof router.visit>[1] = {}) {
    const destination = canonicalCatalogueUrl(href, window.location.origin);
    if (!destination) { router.visit(href, options); return; }
    href = destination;
    const ticket = ++navigation;
    let cancelled = false;
    let cancelRequest: (() => void) | undefined;
    cataloguePrefetch.setBusy(true);
    options.onCancelToken?.({ cancel: () => {
        cancelled = true;
        cancelRequest?.();
        if (ticket === navigation) {
            navigation++;
            cataloguePrefetch.cancel(href);
            cataloguePrefetch.setBusy(foreground > 0);
        }
    } });
    if (options.only?.length) cataloguePrefetch.prioritize();
    const ready = options.only?.length ? false : await cataloguePrefetch.ready(href);
    if (cancelled || ticket !== navigation) return;
    router.visit(href, { ...options, headers: { ...options.headers, ...cataloguePrefetch.headers() }, fresh: !ready,
        onCancelToken: token => { cancelRequest = token.cancel; if (cancelled) token.cancel(); },
        // Cached visits replay callbacks without a new global request/finish event.
        onFinish: visit => { cataloguePrefetch.setBusy(foreground > 0); options.onFinish?.(visit); },
        onCancel: () => { cataloguePrefetch.setBusy(foreground > 0); options.onCancel?.(); },
    });
}

export function invalidateCatalogue(notify = true) {
    navigation++;
    cataloguePrefetch.clear();
    if (notify) broadcast?.postMessage('invalidate');
}

export function installCatalogueNavigation(initial: CatalogueSnapshot) {
    if (installed) return;
    installed = true;
    cataloguePrefetch.observe(initial);
    router.on('before', event => {
        const visit = event.detail.visit;
        if (visit.prefetch || visit.headers.Purpose === 'prefetch') return;
        navigation++;
        if (visit.method !== 'get') invalidateCatalogue();
        else if (!canonicalCatalogueUrl(visit.url.href, window.location.origin) || visit.headers['X-Bagoo-Catalogue'] !== '1') cataloguePrefetch.clear();
        else cataloguePrefetch.prioritize(visit.url.href);
    });
    router.on('start', event => {
        if (!event.detail.visit.prefetch && event.detail.visit.headers.Purpose !== 'prefetch') { foreground++; cataloguePrefetch.setBusy(true); }
    });
    router.on('finish', event => {
        if (!event.detail.visit.prefetch && event.detail.visit.headers.Purpose !== 'prefetch') { foreground = Math.max(0, foreground - 1); cataloguePrefetch.setBusy(foreground > 0); }
    });
    router.on('navigate', event => cataloguePrefetch.observe(event.detail.page));
    const clear = () => invalidateCatalogue(false);
    window.addEventListener('pagehide', clear);
    window.addEventListener('pageshow', clear);
    window.addEventListener('focus', clear);
    window.addEventListener('offline', clear);
    document.addEventListener('visibilitychange', clear);
    if (typeof BroadcastChannel !== 'undefined') {
        broadcast = new BroadcastChannel('bagoo-catalogue');
        broadcast.addEventListener('message', clear);
    }
}
