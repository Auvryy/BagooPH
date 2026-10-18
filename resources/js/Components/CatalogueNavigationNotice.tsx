import { useEffect, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { X } from 'lucide-react';
import { canonicalCatalogueUrl } from '@/utils/cataloguePrefetch';
import { visitPortal } from '@/utils/catalogueRuntime';
import { portalPageComponent } from '@/utils/portalNavigation';

export default function CatalogueNavigationNotice() {
    const page = usePage();
    const rider = (page.props.auth as { user?: { role?: string } })?.user?.role === 'courier';
    const [href, setHref] = useState<string>();
    useEffect(() => {
        const show = (event: Event) => {
            const target = (event as CustomEvent<{ href: string }>).detail?.href;
            if (typeof target === 'string' && (canonicalCatalogueUrl(target, window.location.origin) || portalPageComponent(target, window.location.origin, page))) setHref(target);
        };
        const stop = router.on('before', event => {
            if (!event.detail.visit.prefetch && event.detail.visit.headers.Purpose !== 'prefetch') setHref(undefined);
        });
        window.addEventListener('bagoo:catalogue-error', show);
        return () => { stop(); window.removeEventListener('bagoo:catalogue-error', show); };
    }, [page]);
    if (!href) return null;
    return <div role="alert" className={`fixed right-4 top-24 z-50 flex max-w-[min(24rem,calc(100vw-2rem))] items-center gap-3 border border-red-300 bg-white p-4 text-sm text-slate-800 shadow-lg ${rider ? 'rounded-[8px]' : 'rounded-lg'}`}>
        <div><p>Could not load this page. Please try again.</p><button type="button" className={`mt-2 font-semibold text-[#E00D42] underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#E00D42] ${rider ? 'min-h-12 rounded-[8px]' : 'rounded-lg'}`} onClick={() => { setHref(undefined); void visitPortal(href); }}>Try again</button></div>
        <button type="button" aria-label="Dismiss message" onClick={() => setHref(undefined)} className={`shrink-0 self-start text-slate-500 hover:bg-slate-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#E00D42] ${rider ? 'flex h-12 w-12 items-center justify-center rounded-[8px]' : 'rounded-lg p-1'}`}><X className="h-4 w-4" /></button>
    </div>;
}
