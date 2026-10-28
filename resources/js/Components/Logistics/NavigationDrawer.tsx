import { Dialog, DialogBackdrop, DialogPanel, DialogTitle } from '@headlessui/react';
import { useEffect, useState, type ReactElement } from 'react';

export default function NavigationDrawer({ enabled, open, onClose, children }: {
    enabled: boolean;
    open: boolean;
    onClose: () => void;
    children: ReactElement;
}) {
    const [desktop, setDesktop] = useState(true);

    useEffect(() => {
        if (!enabled) return;
        const media = window.matchMedia('(min-width: 1024px)');
        const update = () => setDesktop(media.matches);
        update();
        media.addEventListener('change', update);
        return () => media.removeEventListener('change', update);
    }, [enabled]);

    if (!enabled || desktop) return children;

    return <Dialog open={open} onClose={onClose} unmount={false} className="logistics-navigation-drawer relative z-[60] font-sans">
        <DialogTitle className="sr-only">Workspace navigation</DialogTitle>
        <DialogBackdrop className="fixed inset-0 bg-slate-950/40" />
        <DialogPanel className="contents">{children}</DialogPanel>
    </Dialog>;
}
