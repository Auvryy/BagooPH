import type { VisitOptions } from '@inertiajs/core';
import { catalogueUrl, CatalogueFilters } from './catalogueNavigation';
import { cataloguePolicy } from './cataloguePrefetch';

export type SearchState = { status: 'idle' | 'preparing' | 'ready' | 'loading' | 'error'; message: string; invalid?: boolean };
interface SearchOptions {
    base: () => string;
    filters: () => CatalogueFilters;
    resultsPage: () => boolean;
    prepare: (url: string) => Promise<boolean>;
    cancelPreparation: (url: string) => void;
    visit: (url: string, options: VisitOptions) => void;
    notify: (state: SearchState) => void;
    automaticAllowed?: () => boolean;
    debounce?: number;
}

export class CatalogueSearch {
    private sequence = 0;
    private timer?: ReturnType<typeof setTimeout>;
    private cancelVisit?: () => void;
    private prepared?: string;
    private target?: string;
    private composing = false;
    private disposed = false;
    constructor(private options: SearchOptions) {}
    private query(value: string) {
        // Match PHP trim rather than changing the meaning of punctuation or Unicode.
        return value.replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '');
    }
    private reset() {
        this.sequence++;
        clearTimeout(this.timer);
        this.cancelVisit?.(); this.cancelVisit = undefined;
        if (this.prepared) this.options.cancelPreparation(this.prepared);
        this.prepared = undefined; this.target = undefined;
    }
    update(value: string, composing = this.composing) {
        this.reset(); this.composing = composing;
        const query = this.query(value);
        if (Array.from(value).length > 100) {
            this.options.notify({ status: 'error', message: 'Use 100 characters or fewer.', invalid: true }); return;
        }
        this.options.notify({ status: 'idle', message: '' });
        if (this.disposed || composing || (query.length < 2 && !(query === '' && this.options.resultsPage()))) return;
        const sequence = this.sequence;
        this.timer = setTimeout(() => {
            if (this.disposed || sequence !== this.sequence) return;
            if (this.options.automaticAllowed?.() === false) {
                this.options.notify({ status: 'idle', message: 'Press Search to load results.' }); return;
            }
            if (this.options.resultsPage()) this.navigate(query, true, sequence);
            else {
                const url = catalogueUrl(this.options.base(), this.options.filters(), { search: query || undefined });
                this.prepared = url;
                this.options.notify({ status: 'preparing', message: 'Preparing search results.' });
                void this.options.prepare(url).then(ready => {
                    if (sequence === this.sequence && !this.disposed) this.options.notify({ status: ready ? 'ready' : 'idle', message: ready ? 'Search results are ready.' : '' });
                }).catch(() => {
                    if (sequence === this.sequence && !this.disposed) this.options.notify({ status: 'idle', message: '' });
                });
            }
        }, this.options.debounce ?? cataloguePolicy.searchDelay);
    }
    submit(value: string) {
        if (this.composing || this.disposed) return;
        // Keep a matching preparation alive so Enter can reuse its in-flight result.
        this.sequence++; clearTimeout(this.timer); this.cancelVisit?.(); this.cancelVisit = undefined;
        const query = this.query(value);
        if (Array.from(value).length > 100) {
            this.options.notify({ status: 'error', message: 'Use 100 characters or fewer.', invalid: true }); return;
        }
        const url = catalogueUrl(this.options.base(), this.options.filters(), { search: query || undefined });
        if (this.prepared && this.prepared !== url) this.options.cancelPreparation(this.prepared);
        this.navigate(query, false, this.sequence);
    }
    private navigate(query: string, live: boolean, sequence: number) {
        const url = catalogueUrl(this.options.base(), this.options.filters(), { search: query || undefined });
        this.target = url;
        this.options.notify({ status: 'loading', message: 'Loading search results.' });
        this.options.visit(url, {
            preserveState: true, preserveScroll: live, replace: live,
            ...(live ? { only: ['products', 'relatedProducts', 'filters'] } : {}),
            onCancelToken: token => { if (sequence === this.sequence) this.cancelVisit = token.cancel; else token.cancel(); },
            onBeforeUpdate: () => sequence === this.sequence && !this.disposed,
            onSuccess: () => { if (sequence === this.sequence && !this.disposed) this.options.notify({ status: 'ready', message: 'Search results updated.' }); },
            onError: () => this.failed(sequence),
            onFinish: () => { if (sequence === this.sequence) { this.cancelVisit = undefined; this.target = undefined; } },
        });
    }
    private failed(sequence: number) {
        if (sequence === this.sequence && !this.disposed) this.options.notify({ status: 'error', message: 'Could not load results. Press Search to try again.' });
    }
    networkFailure() { if (this.target) this.failed(this.sequence); }
    leaving(url: string) {
        const base = new URL(this.options.base(), 'http://localhost');
        const local = (value: string) => { const u = new URL(value, base); u.searchParams.sort(); return u.pathname + u.search; };
        if (!this.target || local(url) !== local(this.target)) { this.reset(); this.options.notify({ status: 'idle', message: '' }); }
    }
    get loading() { return !!this.target; }
    dispose() { this.disposed = true; this.reset(); }
}
