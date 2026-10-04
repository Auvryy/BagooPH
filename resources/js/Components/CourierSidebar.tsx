import { Dialog, DialogBackdrop, DialogPanel, DialogTitle } from '@headlessui/react';
import { Link } from '@inertiajs/react';
import { ArrowUpRight, History, LayoutDashboard, MapPin, Menu, MessageSquare, UserRound, X } from 'lucide-react';
import { useEffect, useRef } from 'react';
import BagooLogo from '@/Components/BagooLogo';
import { courierButton, courierClasses } from '@/Components/CourierUI';
import { courierPath } from '@/utils/courier';

export const courierNavItems = [
    { name: 'Dashboard', path: '/deliveries', page: 'Courier/Deliveries', icon: LayoutDashboard },
    { name: 'Trips', path: '/earnings', page: 'Courier/Earnings', icon: History },
    { name: 'Messages', path: '/messages', page: 'Courier/Messages', icon: MessageSquare },
    { name: 'Profile', path: '/profile', page: 'Courier/Profile', icon: UserRound },
];

export function CourierSidebarToggle({ open, mobile, onClick }: { open: boolean; mobile: boolean; onClick: () => void }) {
    return <button type="button" onClick={onClick} aria-label={mobile ? open ? 'Close sidebar' : 'Open sidebar' : open ? 'Collapse sidebar' : 'Expand sidebar'} aria-expanded={open} aria-controls={mobile ? 'rider-mobile-sidebar' : 'rider-sidebar'} aria-haspopup={mobile ? 'dialog' : undefined} className={courierClasses(courierButton, 'h-12 w-12 shrink-0 border-transparent p-0 hover:bg-slate-100')}><Menu className="h-5 w-5" aria-hidden="true" /></button>;
}

interface SidebarContentProps {
    component: string;
    hub: string;
    company: string;
    name: string;
    initials: string;
    subtle: boolean;
    onClose: () => void;
    mobile?: boolean;
}

function SidebarContent({ component, hub, company, name, initials, subtle, onClose, mobile = false }: SidebarContentProps) {
    return <>
        <div className="flex min-h-[76px] shrink-0 items-center justify-between gap-2 px-3">
            <Link href={courierPath('/deliveries')} onClick={mobile ? onClose : undefined} className="flex min-h-12 min-w-0 items-center gap-2 focus-visible:outline-[#E00D42]">
                <BagooLogo className="h-8 w-8 shrink-0" rounded="rounded-[8px]" />
                <span><span className="block text-lg font-bold tracking-tight">Bagoo<span className="text-[#E00D42]">PH</span></span><span className="block text-xs font-medium text-slate-500">Rider workspace</span></span>
            </Link>
            {mobile && <button type="button" onClick={onClose} aria-label="Close sidebar" className={courierClasses(courierButton, 'h-12 w-12 shrink-0 border-transparent p-0 hover:bg-slate-100')}><X className="h-5 w-5" aria-hidden="true" /></button>}
        </div>
        <nav aria-label="Rider sidebar navigation" className="shrink-0 space-y-1.5 px-3 py-4">
            <p className="mb-3 px-3 text-xs font-medium tracking-wide text-slate-500">Your workspace</p>
            {courierNavItems.map(({ name: label, path, page, icon: Icon }) => (
                <Link key={path} href={courierPath(path)} onClick={mobile ? onClose : undefined} aria-current={component === page ? 'page' : undefined} className={courierClasses('flex min-h-12 items-center gap-3 rounded-[8px] border px-3 py-3 text-sm font-medium transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#E00D42] motion-reduce:transition-none', component === page ? subtle ? 'border-transparent bg-[#FFF5F7] text-[#C20836]' : 'border-rose-200 bg-[#FFF2F4] text-[#C20836]' : 'border-transparent text-slate-600 hover:bg-slate-50')}><Icon className="h-[18px] w-[18px]" aria-hidden="true" />{label}</Link>
            ))}
        </nav>
        <div className="mt-auto shrink-0 px-3 pb-4 pt-8">
            <div className="courier-dispatch-card rounded-[8px] border border-rose-200 bg-[#FFF2F4] p-4">
                <MapPin className="h-5 w-5 text-[#E00D42]" aria-hidden="true" />
                <p className="mt-3 text-sm font-semibold">Your dispatch point</p>
                <p className="mt-2 break-words text-sm leading-relaxed text-slate-600">{hub}</p>
                <Link href={courierPath('/profile')} onClick={mobile ? onClose : undefined} className="mt-3 inline-flex min-h-12 items-center gap-1.5 text-sm font-semibold text-[#C20836] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#E00D42]">View profile<ArrowUpRight className="h-4 w-4" aria-hidden="true" /></Link>
            </div>
            <p className="px-2 py-4 text-xs leading-relaxed text-slate-600">A clear handoff, every step of the way. Use the portal while safely stopped.</p>
            <Link href={courierPath('/profile')} onClick={mobile ? onClose : undefined} className="flex min-h-14 items-center gap-3 rounded-[8px] border border-transparent px-2 py-2 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#E00D42]">
                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#E00D42] text-sm font-semibold text-white" aria-hidden="true">{initials}</span>
                <span className="min-w-0"><span className="block truncate text-sm font-semibold">{name}</span><span className="block truncate text-xs text-slate-600">{company}</span></span>
            </Link>
        </div>
    </>;
}

interface Props extends Omit<SidebarContentProps, 'onClose' | 'mobile'> {
    collapsed: boolean;
    mobileOpen: boolean;
    onCloseMobile: () => void;
}

export default function CourierSidebar({ collapsed, mobileOpen, onCloseMobile, ...content }: Props) {
    const sidebar = useRef<HTMLElement>(null);
    useEffect(() => {
        if (sidebar.current) sidebar.current.inert = collapsed;
    }, [collapsed]);

    return <>
        <aside ref={sidebar} id="rider-sidebar" aria-label="Rider sidebar" aria-hidden={collapsed} data-collapsed={collapsed} className={courierClasses('courier-sidebar fixed inset-y-0 left-0 z-40 hidden flex-col overflow-y-auto border-r border-slate-300 bg-white md:flex', content.subtle && 'border-transparent shadow-[2px_0_16px_rgba(15,23,42,0.035)]')}>
            <SidebarContent {...content} onClose={onCloseMobile} />
        </aside>
        <Dialog open={mobileOpen} onClose={onCloseMobile} className="relative z-[60] font-sans text-slate-900">
            <DialogBackdrop transition className="courier-sidebar-backdrop fixed inset-0 bg-slate-950/25" />
            <DialogPanel transition id="rider-mobile-sidebar" className={courierClasses('courier-sidebar-drawer fixed inset-y-0 left-0 flex w-80 max-w-[calc(100vw-3rem)] flex-col overflow-y-auto bg-white shadow-xl', content.subtle && 'courier-dashboard')}>
                <DialogTitle className="sr-only">Rider navigation</DialogTitle>
                <SidebarContent {...content} mobile onClose={onCloseMobile} />
            </DialogPanel>
        </Dialog>
    </>;
}
