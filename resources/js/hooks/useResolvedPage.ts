import { useEffect } from 'react';
import { router, usePage } from '@inertiajs/react';

/** Keep the URL consistent after a fresh response clamps an emptied last page. */
export default function useResolvedPage(page: number, active = true) {
    const current = usePage();
    useEffect(() => {
        if (!active) return;
        const url = new URL(window.location.href);
        if (url.searchParams.has('page') && url.searchParams.get('page') !== String(page)) {
            url.searchParams.set('page', String(page));
            router.replace({ url: url.pathname + url.search, preserveState: true, preserveScroll: true });
        }
    }, [current.url, page, active]);
}
