import { useEffect, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { X } from 'lucide-react';
import { canonicalCatalogueUrl } from '@/utils/cataloguePrefetch';
import { visitPortal } from '@/utils/catalogueRuntime';
import { portalPageComponent } from '@/utils/portalNavigation';

export default function CatalogueNavigationNotice() {
    const page = usePage();
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
    return <div role="alert" className="fixed right-4 top-24 z-50 flex max-w-sm items-center gap-3 rounded-lg border border-red-300 bg-white p-4 text-sm text-slate-800 shadow-lg">
        <div><p>Could not load this page. Please try again.</p><button type="button" className="mt-2 font-semibold text-[#E00D42] underline" onClick={() => { setHref(undefined); void visitPortal(href); }}>Try again</button></div>
        <button type="button" aria-label="Dismiss message" onClick={() => setHref(undefined)} className="self-start rounded-lg p-1 text-slate-500 hover:bg-slate-100"><X className="h-4 w-4" /></button>
    </div>;
}
