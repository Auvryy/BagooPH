import { useEffect, useId, useState } from 'react';
import { Banknote, ChevronDown, ShieldCheck, Store, Truck, Users, type LucideIcon } from 'lucide-react';
import PortalLink from '@/Components/PortalLink';
import NavigationAttentionDot from '@/Components/NavigationAttentionDot';

export type AdminNavigationItem = { name: string; href: string; icon: LucideIcon; current: boolean; attention?: boolean; attentionLabel?: string };

const sections = [
    { key: 'accounts', label: 'Accounts & reviews', icon: Users, names: ['KYC Queue', 'Shop reviews', 'Users', 'Review requests'] },
    { key: 'marketplace', label: 'Marketplace', icon: Store, names: ['Products', 'Voucher campaigns', 'Advertisements'] },
    { key: 'deliveries', label: 'Deliveries & returns', icon: Truck, names: ['Logistics', 'Delivery exceptions', 'Buyer returns', 'Handover issues'] },
    { key: 'finance', label: 'Finance', icon: Banknote, names: ['COD cash', 'Seller proceeds', 'Wallet credits', 'Virtual proceeds', 'Financial oversight'] },
    { key: 'governance', label: 'Governance', icon: ShieldCheck, names: ['Governance history', 'Resource activity'] },
];

function NavigationLink({ item }: { item: AdminNavigationItem }) {
    return <PortalLink href={item.href} aria-current={item.current ? 'page' : undefined}
        className={`group flex items-center justify-between gap-3 rounded-lg px-3 py-2.5 text-xs transition focus-visible:ring-2 focus-visible:ring-[#E00D42] ${item.current ? 'bg-[#E00D42] font-bold text-white' : 'font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900'}`}>
        <span className="flex min-w-0 items-center gap-2.5"><item.icon aria-hidden="true" className="h-4 w-4 shrink-0" /><span>{item.name}</span></span>
        {item.attention && <NavigationAttentionDot label={item.attentionLabel} />}
    </PortalLink>;
}

function NavigationSection({ section, items }: { section: typeof sections[number]; items: AdminNavigationItem[] }) {
    const active = items.find(item => item.current)?.href;
    const [expanded, setExpanded] = useState(Boolean(active));
    const id = useId();
    useEffect(() => { if (active) setExpanded(true); }, [active]);
    return <div>
        <button type="button" aria-expanded={expanded} aria-controls={id} onClick={() => setExpanded(value => !value)}
            className={`flex min-h-10 w-full items-center justify-between gap-2 rounded-lg px-3 py-2 text-left text-xs font-semibold focus-visible:ring-2 focus-visible:ring-[#E00D42] ${active ? 'bg-rose-50 text-[#C20836]' : 'text-slate-700 hover:bg-slate-100'}`}>
            <span className="flex min-w-0 items-center gap-2.5"><section.icon aria-hidden="true" className="h-4 w-4 shrink-0" /><span>{section.label}</span></span><span className="flex items-center gap-2">{!expanded && items.some(item => item.attention) && <NavigationAttentionDot />}
                <ChevronDown aria-hidden="true" className={`h-3.5 w-3.5 transition-transform motion-reduce:transition-none ${expanded ? '' : '-rotate-90'}`} /></span>
        </button>
        <div id={id} hidden={!expanded} role="group" aria-label={section.label} className="ml-4 mt-1 space-y-0.5 border-l border-slate-300 pl-2">
            {items.map(item => <NavigationLink key={item.name} item={item} />)}
        </div>
    </div>;
}

export default function AdminSidebarNavigation({ items }: { items: AdminNavigationItem[] }) {
    return <div className="space-y-2" aria-label="Admin sections">
        {items.filter(item => item.name === 'Dashboard').map(item => <NavigationLink key={item.name} item={item} />)}
        {sections.map(section => <NavigationSection key={section.key} section={section} items={items.filter(item => section.names.includes(item.name))} />)}
    </div>;
}
