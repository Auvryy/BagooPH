import { ComponentProps, useEffect, useRef, useState } from 'react';
import { cataloguePrefetch, visitCatalogue } from '@/utils/catalogueRuntime';
import { cataloguePolicy } from '@/utils/cataloguePrefetch';

type Props = ComponentProps<'a'> & { href: string; onSuccess?: () => void; preserveState?: boolean; preserveScroll?: boolean };
export default function CatalogueLink({ href, children, onSuccess, preserveScroll = false, preserveState = false, onClick, ...props }: Props) {
    const timer = useRef<ReturnType<typeof setTimeout>>();
    const [pending, setPending] = useState(false);
    const cancel = () => clearTimeout(timer.current);
    const prepare = () => {
        cancel();
        timer.current = setTimeout(() => { void cataloguePrefetch.prepare(href); }, cataloguePolicy.intentDelay);
    };
    useEffect(() => cancel, [href]);
    return <a {...props} href={href} aria-busy={pending || undefined}
        onPointerEnter={event => { props.onPointerEnter?.(event); if (event.pointerType !== 'touch') prepare(); }}
        onPointerLeave={event => { props.onPointerLeave?.(event); cancel(); }}
        onFocus={event => { props.onFocus?.(event); prepare(); }}
        onBlur={event => { props.onBlur?.(event); cancel(); }}
        onTouchStart={event => { props.onTouchStart?.(event); void cataloguePrefetch.prepare(href); }}
        onClick={event => {
            onClick?.(event);
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || (props.target && props.target !== '_self') || props.download !== undefined) return;
            event.preventDefault(); cancel(); setPending(true);
            void visitCatalogue(href, { preserveScroll, preserveState, onSuccess, onFinish: () => setPending(false), onCancel: () => setPending(false) });
        }}>{children}</a>;
}
