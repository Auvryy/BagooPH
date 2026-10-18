import { useEffect } from 'react';
import { router, usePage } from '@inertiajs/react';
import PortalLink from './PortalLink';
import { CataloguePage } from './CataloguePagination';
import { catalogueHref, paginationLabel } from '@/utils/catalogueNavigation';
import { adminDirectoryProps } from '@/utils/portalNavigation';

export default function AdminDirectoryPagination({ page, kind }: { page: CataloguePage; kind: 'users' | 'products' }) {
    const current = usePage();
    useEffect(() => {
        const url = new URL(window.location.href);
        if (url.searchParams.has('page') && url.searchParams.get('page') !== String(page.current_page)) {
            url.searchParams.set('page', String(page.current_page));
            router.replace({ url: url.pathname + url.search, preserveState: true, preserveScroll: true });
        }
    }, [current.url, page.current_page]);
    const origin = typeof window === 'undefined' ? 'http://localhost' : window.location.origin;
    return <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-300 px-5 py-4 text-sm">
        <p role="status" aria-live="polite" className="text-slate-600">{page.total} {kind} · Page {page.current_page} of {Math.max(1, page.last_page)}</p>
        <nav aria-label={`${kind === 'users' ? 'User' : 'Product'} pages`} className="flex flex-wrap gap-1">
            {page.links?.map((link, index) => {
                const href = link.url ? catalogueHref(link.url, origin) : undefined;
                const label = paginationLabel(link.label);
                const control = 'rounded-lg border px-3 py-2 text-xs font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42]';
                if (link.active) return <span key={index} aria-current="page" className={`${control} border-[#E00D42] bg-[#E00D42] text-white`}>{label}</span>;
                if (!href) return <span key={index} aria-disabled="true" className={`${control} border-slate-300 text-slate-400`}>{label}</span>;
                return <PortalLink key={index} href={href} only={adminDirectoryProps(kind)} preserveState className={`${control} border-slate-300 bg-white text-slate-700 hover:border-[#E00D42] hover:text-[#E00D42]`}>{label}</PortalLink>;
            })}
        </nav>
    </div>;
}
