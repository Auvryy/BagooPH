import { RefObject, useEffect } from 'react';
import { usePage } from '@inertiajs/react';
import { cataloguePrefetch } from '@/utils/catalogueRuntime';
import { CatalogueIdle } from '@/utils/cataloguePrefetch';

export default function useCatalogueIdle(ref: RefObject<HTMLElement>, next?: string | null) {
    const page = usePage();
    useEffect(() => {
        if (!next || !ref.current || typeof IntersectionObserver === 'undefined') return;
        const idle = new CatalogueIdle(() => cataloguePrefetch.allowed(true), () => { void cataloguePrefetch.prepare(next, true); });
        const observer = new IntersectionObserver(entries => idle.display(entries.some(entry => entry.isIntersecting)));
        observer.observe(ref.current);
        window.addEventListener('keydown', idle.activity);
        window.addEventListener('pointerdown', idle.activity);
        return () => {
            idle.dispose(); observer.disconnect();
            window.removeEventListener('keydown', idle.activity);
            window.removeEventListener('pointerdown', idle.activity);
        };
    }, [page.url, next, ref]);
}
