import { useEffect } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { catalogueHref, paginationLabel, revealCatalogueResults } from '@/utils/catalogueNavigation';

export interface CataloguePage {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from?: number | null;
    to?: number | null;
    next_page_url?: string | null;
    links?: { url: string | null; label: string; active: boolean }[];
}

export default function CataloguePagination({ page }: { page: CataloguePage }) {
    const current = usePage();
    useEffect(() => {
        const url = new URL(window.location.href);
        if (url.searchParams.has('page') && url.searchParams.get('page') !== String(page.current_page)) {
            url.searchParams.set('page', String(page.current_page));
            router.replace({ url: url.pathname + url.search, preserveState: true, preserveScroll: true });
        }
    }, [current.url, page.current_page]);

    const origin = typeof window === 'undefined' ? 'http://localhost' : window.location.origin;
    const from = page.total ? (page.current_page - 1) * page.per_page + 1 : 0;
    const to = Math.min(page.current_page * page.per_page, page.total);
    return (
        <div className="mt-8 space-y-3 font-sans text-xs">
            <p role="status" aria-live="polite" className="text-center text-slate-500">
                Showing {from}–{to} of {page.total} products · Page {page.current_page} of {Math.max(1, page.last_page)}
            </p>
            {page.last_page > 1 && <nav aria-label="Product pages" className="flex flex-wrap items-center justify-center gap-1">
                {page.links?.map((link, index) => {
                    const href = link.url ? catalogueHref(link.url, origin) : undefined;
                    const className = 'rounded-lg border px-3.5 py-2 font-bold uppercase transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42]';
                    const label = paginationLabel(link.label);
                    if (link.active) return <span key={index} aria-current="page" className={`${className} border-[#E00D42] bg-[#E00D42] text-white`}>{label}</span>;
                    if (!href) return <button key={index} type="button" disabled className={`${className} cursor-not-allowed border-slate-200 bg-slate-100 text-slate-400`}>{label}</button>;
                    return <Link key={index} href={href} preserveScroll preserveState onSuccess={revealCatalogueResults} className={`${className} border-slate-200 bg-white text-slate-800 hover:border-slate-400`}>{label}</Link>;
                })}
            </nav>}
        </div>
    );
}
