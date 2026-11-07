import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { MapPin } from 'lucide-react';

export default function CheckoutAddressDisclosure({ summary, initiallyExpanded, hasErrors = false, children }: {
    summary: string; initiallyExpanded: boolean; hasErrors?: boolean; children: ReactNode;
}) {
    const [expanded, setExpanded] = useState(initiallyExpanded || hasErrors);
    const editorId = useId();
    const revealingInvalid = useRef(false);
    useEffect(() => { if (hasErrors) setExpanded(true); }, [hasErrors]);

    return <section aria-label="Delivery address" className="space-y-4" onInvalidCapture={event => {
        event.preventDefault();
        if (revealingInvalid.current) return;
        revealingInvalid.current = true;
        const target = event.target as HTMLElement;
        setExpanded(true);
        requestAnimationFrame(() => { target.focus(); target.scrollIntoView({ block: 'center', behavior: 'smooth' }); revealingInvalid.current = false; });
    }}>
        <button type="button" aria-expanded={expanded} aria-controls={editorId} onClick={() => setExpanded(value => !value)}
            className="flex min-h-14 w-full items-center gap-3 rounded-xl border border-slate-300 bg-slate-50 px-4 py-3 text-left focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#E00D42]">
            <MapPin className="h-4 w-4 shrink-0 text-[#E00D42]" aria-hidden="true" />
            <span className="min-w-0 flex-1 truncate text-sm text-slate-800" title={summary}>{summary || 'Add delivery address'}</span>
            <span className="shrink-0 text-xs font-bold text-[#E00D42]">{expanded ? 'Done' : 'Edit'}</span>
        </button>
        <div id={editorId} hidden={!expanded} className="space-y-4">{children}</div>
    </section>;
}
