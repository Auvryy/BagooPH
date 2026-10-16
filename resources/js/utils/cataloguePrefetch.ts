import { catalogueHref } from './catalogueNavigation';

export const cataloguePolicy = { ttl: 10_000, maxEntries: 20, intentDelay: 150, idleDelay: 1_000, searchDelay: 300 };

export class CatalogueIdle {
    private visible = false;
    private used = false;
    private timer?: ReturnType<typeof setTimeout>;
    constructor(private allowed: () => boolean, private prepare: () => void) {}
    display(visible: boolean) { this.visible = visible; this.activity(); }
    activity = () => {
        clearTimeout(this.timer);
        if (this.used || !this.visible) return;
        this.timer = setTimeout(() => {
            if (!this.allowed()) return;
            this.used = true; this.prepare();
        }, cataloguePolicy.idleDelay);
    };
    dispose() { clearTimeout(this.timer); this.visible = false; }
}
export interface CatalogueEnvironment {
    origin: string;
    currentUrl: string;
    visible: boolean;
    online: boolean;
    connection?: { saveData?: boolean; effectiveType?: string };
}
export interface CatalogueSnapshot {
    version?: string | null;
    props: Record<string, any>;
}
export function catalogueAccountKey(page: CatalogueSnapshot): string {
    const user = page.props.auth?.user;
    return JSON.stringify([page.version, user?.id ?? null, user?.role, user?.status, user?.kyc_status,
        user?.activeHub?.id, user?.logisticsCompany?.id]);
}
export function preparedCatalogueComponent(path: string): string | undefined {
    if (/^\/(?:buyer\/)?product\/[^/]+\/?$/.test(path)) return 'Buyer/ProductDetail';
    if (['/', '/buyer', '/buyer/'].includes(path)) return 'Buyer/Home';
    if (['/products', '/catalog', '/buyer/search', '/buyer/catalog'].includes(path)) return 'Buyer/Search';
}
export function canonicalCatalogueUrl(href: string, origin: string): string | undefined {
    const local = catalogueHref(href, origin);
    if (!local) return;
    const url = new URL(local, origin);
    if (!preparedCatalogueComponent(url.pathname)) return;
    url.hash = '';
    url.searchParams.sort();
    return url.pathname + url.search;
}
export interface Preparation {
    done: Promise<boolean>;
    cancel: () => void;
}
export interface CatalogueTransport {
    prepare: (url: string, headers: Record<string, string>, account: string) => Preparation;
    flush: (url: string, headers: Record<string, string>) => void;
}
interface Entry {
    url: string;
    headers: Record<string, string>;
    expires: number;
}

/** A bounded policy over Inertia's page cache; never a second response store. */
export class CataloguePrefetch {
    private context = '';
    private generation = 0;
    private permitted = false;
    private enabled = false;
    private busy = false;
    private entries = new Map<string, Entry>();
    private failures = new Map<string, number>();
    private active?: Entry & Preparation;
    constructor(private transport: CatalogueTransport, private environment: () => CatalogueEnvironment, private now = Date.now) {}

    observe(page: CatalogueSnapshot) {
        const context = catalogueAccountKey(page);
        if (context !== this.context) { this.clear(); this.context = context; }
        this.enabled = page.props.cataloguePrefetchEnabled === true;
        this.permitted = (!page.props.auth?.user || page.props.auth.user.role === 'buyer')
            && !Object.keys(page.props.errors ?? {}).length && !Object.values(page.props.flash ?? {}).some(Boolean);
        if (!this.enabled || !this.permitted) this.clear();
    }
    headers(): Record<string, string> {
        return { 'X-Bagoo-Catalogue': '1', 'X-Bagoo-Catalogue-Context': `${this.generation}:${this.context}` };
    }
    allowed(idle = false) {
        const env = this.environment();
        const slow = env.connection?.saveData || ['slow-2g', '2g'].includes(env.connection?.effectiveType ?? '');
        return this.enabled && this.permitted && !this.busy && env.visible && env.online && !slow && (!idle || !!env.connection?.effectiveType);
    }
    setBusy(busy: boolean) { this.busy = busy; }
    private prune() {
        for (const [url, entry] of this.entries) if (entry.expires <= this.now()) {
            this.transport.flush(url, entry.headers); this.entries.delete(url);
        }
        for (const [url, expires] of this.failures) if (expires <= this.now()) this.failures.delete(url);
    }
    private failed(url: string) {
        this.failures.set(url, this.now() + cataloguePolicy.ttl);
        while (this.failures.size > cataloguePolicy.maxEntries) this.failures.delete(this.failures.keys().next().value!);
    }
    prepare(href: string, idle = false): Promise<boolean> {
        this.prune();
        const env = this.environment();
        const url = canonicalCatalogueUrl(href, env.origin);
        if (!url || !this.allowed(idle) || url === canonicalCatalogueUrl(env.currentUrl, env.origin)) return Promise.resolve(false);
        if (this.entries.has(url)) return Promise.resolve(true);
        if (this.active?.url === url) return this.active.done;
        if (this.active || this.failures.has(url)) return Promise.resolve(false);
        const generation = this.generation;
        const headers = this.headers();
        const request = this.transport.prepare(url, headers, this.context);
        const active = { url, headers, expires: 0, cancel: request.cancel, done: Promise.resolve(false) };
        this.active = active;
        active.done = request.done.then(valid => {
            if (this.active === active) this.active = undefined;
            if (!valid || generation !== this.generation) {
                this.transport.flush(url, headers);
                if (generation === this.generation) this.failed(url);
                return false;
            }
            this.prune();
            while (this.entries.size >= cataloguePolicy.maxEntries) {
                const oldest = this.entries.values().next().value!;
                this.transport.flush(oldest.url, oldest.headers); this.entries.delete(oldest.url);
            }
            this.entries.set(url, { url, headers, expires: this.now() + cataloguePolicy.ttl });
            return true;
        }, () => {
            if (this.active === active) this.active = undefined;
            this.transport.flush(url, headers);
            if (generation === this.generation) this.failed(url);
            return false;
        });
        return active.done;
    }
    async ready(href: string): Promise<boolean> {
        this.prune();
        const url = canonicalCatalogueUrl(href, this.environment().origin);
        if (!url) return false;
        if (this.entries.has(url)) return true;
        if (this.active?.url === url) return this.active.done;
        this.cancelActive();
        return false;
    }
    cancel(href: string) {
        if (this.active?.url === canonicalCatalogueUrl(href, this.environment().origin)) this.cancelActive();
    }
    prioritize(href?: string) {
        if (!href || this.active?.url !== canonicalCatalogueUrl(href, this.environment().origin)) this.cancelActive();
    }
    private cancelActive() {
        const active = this.active;
        this.active = undefined;
        if (active) { active.cancel(); this.transport.flush(active.url, active.headers); }
    }
    clear() {
        this.generation++;
        this.cancelActive();
        for (const entry of this.entries.values()) this.transport.flush(entry.url, entry.headers);
        this.entries.clear(); this.failures.clear();
    }
}
