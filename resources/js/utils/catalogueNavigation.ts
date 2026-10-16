export interface CatalogueFilters {
    search?: string;
    category?: string;
    sort?: string;
    min_price?: string;
    max_price?: string;
    in_stock?: boolean | string;
    rating?: string;
    tab?: string;
}

export class SupersededCatalogueVisit extends Error {
    constructor() {
        super('A newer catalogue request replaced this visit.');
        this.name = 'SupersededCatalogueVisit';
    }
}

export function catalogueFilters(filters: CatalogueFilters, updates: CatalogueFilters = {}): Record<string, string> {
    const next = { ...filters, ...updates };
    const query: Record<string, string> = {};
    for (const key of ['search', 'category', 'sort', 'min_price', 'max_price', 'in_stock', 'rating'] as const) {
        const value = next[key];
        if (value === undefined || value === null || value === '' || value === false || value === 'all') continue;
        if (key === 'in_stock') {
            if (value === true || value === '1') query[key] = '1';
        } else {
            query[key] = String(value === 'new_arrivals' && key === 'sort' ? 'newest' : value);
        }
    }
    return query;
}

export function catalogueHref(href: string, origin: string): string | undefined {
    try {
        const current = new URL(origin);
        const target = new URL(href, current);
        if (!['http:', 'https:'].includes(target.protocol) || target.username || target.password || target.hostname !== current.hostname) return;
        // Pagination can originate behind a TLS proxy; retain the browser's origin.
        return target.pathname + target.search + target.hash;
    } catch { return; }
}

export function catalogueUrl(base: string, filters: CatalogueFilters, updates: CatalogueFilters = {}): string {
    const query = new URLSearchParams(catalogueFilters(filters, updates));
    query.sort();
    return base.split('?')[0] + (query.size ? `?${query}` : '');
}

export function revealCatalogueResults() {
    const target = document.getElementById('catalogue-results');
    target?.focus({ preventScroll: true });
    target?.scrollIntoView({ block: 'start', behavior: 'auto' });
}

export function paginationLabel(label: string): string {
    if (/previous/i.test(label)) return 'Previous';
    if (/next/i.test(label)) return 'Next';
    return label.replace(/<[^>]*>/g, '').replace(/&hellip;/g, '…');
}
