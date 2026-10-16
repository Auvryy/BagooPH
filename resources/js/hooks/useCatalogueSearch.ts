import { useEffect, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { CatalogueSearch, SearchState } from '@/utils/catalogueSearch';
import { CatalogueFilters, SupersededCatalogueVisit } from '@/utils/catalogueNavigation';
import { cataloguePrefetch, visitCatalogue } from '@/utils/catalogueRuntime';
import { PageProps } from '@/types';

export default function useCatalogueSearch() {
    const page = usePage<PageProps<{ filters?: CatalogueFilters }>>();
    const latest = useRef(page); latest.current = page;
    const [value, setValue] = useState(page.props.filters?.search ?? '');
    const [state, setState] = useState<SearchState>({ status: 'idle', message: '' });
    const controller = useRef<CatalogueSearch>();
    useEffect(() => {
        const search = new CatalogueSearch({
            base: () => route('buyer.search'),
            filters: () => latest.current.props.filters ?? {},
            resultsPage: () => latest.current.component === 'Buyer/Search',
            prepare: url => { cataloguePrefetch.prioritize(url); return cataloguePrefetch.prepare(url); },
            cancelPreparation: url => cataloguePrefetch.cancel(url),
            visit: (url, options) => { void visitCatalogue(url, options); },
            notify: setState,
            automaticAllowed: () => document.visibilityState === 'visible' && navigator.onLine !== false,
        });
        controller.current = search;
        const stop = router.on('before', event => {
            if (!event.detail.visit.prefetch && event.detail.visit.headers.Purpose !== 'prefetch') search.leaving(event.detail.visit.url.href);
        });
        const stopException = router.on('exception', event => {
            if (event.detail.exception instanceof SupersededCatalogueVisit) return;
            if (search.loading) { search.networkFailure(); event.preventDefault(); }
        });
        const suspend = () => { search.leaving('about:blank'); };
        window.addEventListener('pagehide', suspend);
        window.addEventListener('offline', suspend);
        document.addEventListener('visibilitychange', suspend);
        return () => { stop(); stopException(); search.dispose(); window.removeEventListener('pagehide', suspend); window.removeEventListener('offline', suspend); document.removeEventListener('visibilitychange', suspend); };
    }, []);
    useEffect(() => { setValue(page.props.filters?.search ?? ''); }, [page.url, page.props.filters?.search]);
    const update = (next: string, composing?: boolean) => { setValue(next); controller.current?.update(next, composing); };
    return { value, state, update, submit: () => controller.current?.submit(value), clear: () => { setValue(''); controller.current?.submit(''); }, composing: () => controller.current?.update(value, true), composed: (next: string) => update(next, false) };
}
