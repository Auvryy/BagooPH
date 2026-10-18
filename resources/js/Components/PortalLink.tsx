import { ComponentProps, useEffect, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { cataloguePolicy } from '@/utils/cataloguePrefetch';
import { preparePortal, visitPortal } from '@/utils/catalogueRuntime';

type Props = ComponentProps<'a'> & { href: string; preserveScroll?: boolean; preserveState?: boolean; only?: string[]; onSuccess?: () => void };

export default function PortalLink({ href, children, preserveScroll = false, preserveState = false, only, onSuccess, onClick, ...props }: Props) {
    const page = usePage();
    const timer = useRef<ReturnType<typeof setTimeout>>();
    const busy = useRef(false);
    const [pending, setPending] = useState(false);
    const cancel = () => clearTimeout(timer.current);
    const prepare = () => {
        cancel();
        timer.current = setTimeout(() => { void preparePortal(href, page); }, cataloguePolicy.intentDelay);
    };
    useEffect(() => cancel, [href, page]);
    return <a {...props} href={href} aria-busy={pending || undefined}
        onPointerEnter={event => { props.onPointerEnter?.(event); if (!event.defaultPrevented && event.pointerType !== 'touch') prepare(); }}
        onPointerLeave={event => { props.onPointerLeave?.(event); cancel(); }}
        onFocus={event => { props.onFocus?.(event); if (!event.defaultPrevented) prepare(); }}
        onBlur={event => { props.onBlur?.(event); cancel(); }}
        onTouchStart={event => { props.onTouchStart?.(event); if (!event.defaultPrevented) void preparePortal(href, page); }}
        onClick={event => {
            onClick?.(event);
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || (props.target && props.target !== '_self') || props.download !== undefined) return;
            try { if (new URL(href, window.location.origin).origin !== window.location.origin) return; } catch { return; }
            event.preventDefault(); cancel();
            if (busy.current) return;
            busy.current = true; setPending(true);
            const finish = () => { busy.current = false; setPending(false); };
            void visitPortal(href, { preserveScroll, preserveState, only, onSuccess, onFinish: finish, onCancel: finish });
        }}>{children}</a>;
}
