import { useEffect, useState } from 'react';
import { ChevronDown, type LucideIcon } from 'lucide-react';
import PortalLink from '@/Components/PortalLink';

type Item = { name: string; href: string; icon: LucideIcon; current: boolean };
const groups = [
    { title: 'Daily work', names: ['Overview', 'Scan Station', 'Parcels & Waybills', 'Counter Pickup'], defaultOpen: true },
    { title: 'Hub transfers', names: ['Mother Bags', 'Manifests', 'Delivery recovery', 'Parcel recovery', 'Buyer returns', 'Handover issues'], defaultOpen: false },
    { title: 'Cash & earnings', names: ['COD cash', 'Delivery earnings', 'Financial oversight'], defaultOpen: false },
    { title: 'Company & people', names: ['Facility Network', 'Fleet Management', 'Company setup'], defaultOpen: false },
    { title: 'Review & history', names: ['Delivery exceptions', 'Resource activity', 'Governance history'], defaultOpen: false },
    { title: 'My account', names: ['Account & security', 'Workspace guide'], defaultOpen: false },
];
export default function SidebarNavigation({ items }: { items: Item[] }) {
    const [expanded, setExpanded] = useState<Record<string, boolean>>({ 'Daily work': true });
    const active = items.find(item => item.current)?.name;
    useEffect(() => { const group = groups.find(group => group.names.includes(active ?? ''));if (group) setExpanded(value => ({ ...value, [group.title]: true })); }, [active]);
    return <div className="space-y-2">{groups.map(group => {
        const links = group.names.flatMap(name => items.filter(item => item.name === name));
        if (!links.length) return null;
        const open = expanded[group.title] ?? group.defaultOpen;
        const id = 'hub-nav-'+group.title.toLowerCase().replaceAll(' ', '-').replaceAll('&', 'and');
        return <section key={group.title}><button type="button" aria-expanded={open} aria-controls={id} onClick={() => setExpanded(value => ({ ...value, [group.title]: !open }))} className="flex w-full items-center justify-between rounded-lg px-3 py-2.5 text-left text-[11px] font-semibold text-slate-500 transition hover:bg-slate-50 hover:text-slate-800"><span className="flex items-center gap-2">{group.title}</span><ChevronDown className={`h-3.5 w-3.5 transition-transform ${open ? '' : '-rotate-90'}`} /></button><div id={id} hidden={!open} className="space-y-1 pb-2">{links.map(item => <PortalLink key={item.name} href={item.href} aria-current={item.current ? 'page' : undefined} className={`group flex min-h-10 items-center gap-2.5 rounded-lg px-3 py-2.5 text-xs font-medium transition ${item.current ? 'bg-rose-50 text-[#C20836] ring-1 ring-inset ring-rose-100' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950'}`}><item.icon className={`h-4 w-4 shrink-0 ${item.current ? 'text-[#E00D42]' : 'text-slate-400 group-hover:text-slate-600'}`} /><span className="flex-1">{item.name === 'Counter Pickup' ? 'Counter pickup' : item.name === 'Fleet Management' ? 'Fleet & drivers' : item.name === 'Facility Network' ? 'Hub network' : item.name}</span></PortalLink>)}</div></section>;
    })}</div>;
}
