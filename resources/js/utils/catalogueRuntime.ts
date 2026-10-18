import { router } from '@inertiajs/react';
import { catalogueAccountKey, canonicalCatalogueUrl, CatalogueEnvironment, CataloguePrefetch, CatalogueSnapshot, CatalogueTransport, preparedCatalogueComponent, cataloguePolicy } from './cataloguePrefetch';
import { SupersededCatalogueVisit } from './catalogueNavigation';
import { portalPageComponent } from './portalNavigation';

export function catalogueEnvironment(): CatalogueEnvironment {
    const connection = (navigator as Navigator & { connection?: CatalogueEnvironment['connection'] }).connection;
    return { origin: window.location.origin, currentUrl: window.location.href, visible: document.visibilityState === 'visible', online: navigator.onLine !== false, connection };
}

const transport: CatalogueTransport = {
    flush: (url, headers) => router.flush(url, { headers }),
    prepareComponent: component => router.resolveComponent(component),
    prepare(url, headers, account) {
        let cancel: (() => void) | undefined;
        let cancelled = false;
        let valid = false;
        let componentReady: Promise<unknown> = Promise.resolve();
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
                    if (valid) componentReady = router.resolveComponent(page.component).catch(() => { valid = false; });
                } catch { valid = false; }
            },
        }, { cacheFor: cataloguePolicy.ttl, cacheTags: ['catalogue'] });
        // Wait until Inertia has inserted its public in-flight cache handle.
        queueMicrotask(() => {
            const pending = router.getPrefetching(url, { headers }) ?? router.getCached(url, { headers });
            if (!pending) { finish(false); return; }
            void pending.response.then(async () => {
                await componentReady;
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
let managedVisit: { href: string; failed: () => void } | undefined;
let currentPage: (CatalogueSnapshot & { component?: string }) | undefined;

export function preparePortal(href: string, page: CatalogueSnapshot & { component?: string }) {
    const component = portalPageComponent(href, window.location.origin, page);
    if (component && component !== page.component) return cataloguePrefetch.prepareCode(component);
    if (canonicalCatalogueUrl(href, window.location.origin)) return cataloguePrefetch.prepare(href);
    return Promise.resolve(false);
}

export function visitPortal(href: string, options: Parameters<typeof router.visit>[1] = {}) {
    const url = new URL(href, window.location.origin);
    if (url.origin !== window.location.origin) { router.visit(href, options); return; }
    if (canonicalCatalogueUrl(href, window.location.origin)) return visitCatalogue(href, options);
    href = url.pathname + url.search + url.hash;
    if (!currentPage || !portalPageComponent(href, window.location.origin, currentPage)) {
        router.visit(href, { ...options, fresh: true });
        return;
    }
    invalidateCatalogue();
    cataloguePrefetch.setBusy(true);
    const current = { href, failed: () => options.onError?.({ navigation: 'Could not load this page. Please try again.' }) };
    managedVisit = current;
    const release = () => { if (managedVisit === current) managedVisit = undefined; cataloguePrefetch.setBusy(foreground > 0); };
    router.visit(href, { ...options, fresh: true, headers: { ...options.headers, 'X-Bagoo-Navigation': '1' },
        onFinish: visit => { release(); options.onFinish?.(visit); },
        onCancel: () => { release(); options.onCancel?.(); },
    });
}

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
    const current = { href, failed: () => options.onError?.({ navigation: 'Could not load this page. Please try again.' }) };
    managedVisit = current;
    const release = () => { if (managedVisit === current) managedVisit = undefined; };
    router.visit(href, { ...options, headers: { ...options.headers, ...cataloguePrefetch.headers() }, fresh: !ready,
        onCancelToken: token => { cancelRequest = token.cancel; if (cancelled) token.cancel(); },
        // Cached visits replay callbacks without a new global request/finish event.
        onFinish: visit => { release(); cataloguePrefetch.setBusy(foreground > 0); options.onFinish?.(visit); },
        onCancel: () => { release(); cataloguePrefetch.setBusy(foreground > 0); options.onCancel?.(); },
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
    currentPage = initial;
    cataloguePrefetch.observe(initial);
    router.on('before', event => {
        const visit = event.detail.visit;
        if (visit.prefetch || visit.headers.Purpose === 'prefetch') return;
        navigation++;
        if (visit.headers['X-Bagoo-Catalogue'] !== '1' && visit.headers['X-Bagoo-Navigation'] !== '1') managedVisit = undefined;
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
    router.on('navigate', event => { currentPage = event.detail.page; cataloguePrefetch.observe(event.detail.page); });
    const failed = (event: { preventDefault: () => void }) => {
        if (!managedVisit) return;
        const current = managedVisit; managedVisit = undefined;
        cataloguePrefetch.clear();
        current.failed();
        window.dispatchEvent(new CustomEvent('bagoo:catalogue-error', { detail: { href: current.href } }));
        event.preventDefault();
    };
    router.on('exception', event => {
        if (event.detail.exception instanceof SupersededCatalogueVisit) { event.preventDefault(); return; }
        failed(event);
    });
    router.on('invalid', failed);
    // Cached visits run outside the HTTP request's exception handler.
    window.addEventListener('unhandledrejection', event => {
        if (event.reason instanceof SupersededCatalogueVisit) event.preventDefault();
    });
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
