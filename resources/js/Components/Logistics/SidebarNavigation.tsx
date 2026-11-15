import { useEffect, useState } from 'react';
import { ChevronDown, type LucideIcon } from 'lucide-react';
import PortalLink from '@/Components/PortalLink';

type Item = { name: string; href: string; icon: LucideIcon; current: boolean; badge?: string };
const groups = [
    { title: 'Daily work', names: ['Overview', 'Scan Station', 'Parcels & Waybills', 'Counter Pickup'], defaultOpen: true },
    { title: 'Hub transfers', names: ['Mother Bags', 'Manifests', 'Delivery recovery', 'Parcel recovery', 'Buyer returns', 'Handover issues'], defaultOpen: false },
    { title: 'Cash & earnings', names: ['COD cash', 'Delivery earnings', 'Return liabilities', 'Financial oversight'], defaultOpen: false },
    { title: 'Company & people', names: ['Facility Network', 'Fleet Management', 'Company setup'], defaultOpen: false },
    { title: 'Review & history', names: ['Delivery exceptions', 'Resource activity', 'Governance history'], defaultOpen: false },
    { title: 'My account', names: ['Account & security'], defaultOpen: false },
];
export default function SidebarNavigation({ items }: { items: Item[] }) {
    const [expanded, setExpanded] = useState<Record<string, boolean>>({ 'Daily work': true });
    const active = items.find(item => item.current)?.name;
    useEffect(() => { const group = groups.find(group => group.names.includes(active ?? ''));if (group) setExpanded(value => ({ ...value, [group.title]: true })); }, [active]);
    return <div className="space-y-1">{groups.map(group => {
        const links = group.names.flatMap(name => items.filter(item => item.name === name));
        if (!links.length) return null;
        const open = expanded[group.title] ?? group.defaultOpen;
        const id = 'hub-nav-'+group.title.toLowerCase().replaceAll(' ', '-').replaceAll('&', 'and');
        return (
            <section key={group.title}>
                <button
                    type="button"
                    aria-expanded={open}
                    aria-controls={id}
                    onClick={() => setExpanded(value => ({ ...value, [group.title]: !open }))}
                    className="flex w-full items-center justify-between rounded-control px-3 py-2 text-left text-xs font-medium text-ink-500 transition-colors hover:bg-surface-100 hover:text-ink-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500"
                >
                    <span className="flex items-center gap-2">{group.title}</span>
                    <ChevronDown aria-hidden="true" className={`h-3.5 w-3.5 transition-transform ${open ? '' : '-rotate-90'}`} />
                </button>
                <div id={id} hidden={!open} className="space-y-0.5 pb-2 pt-0.5">
                    {links.map(item => (
                        <PortalLink
                            key={item.name}
                            href={item.href}
                            aria-current={item.current ? 'page' : undefined}
                            className={`group relative flex min-h-10 items-center gap-2.5 rounded-control px-3 py-2 text-sm transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500 ${
                                item.current
                                    ? 'bg-brand-50 text-brand-600 font-semibold before:absolute before:inset-y-2 before:left-0 before:w-[3px] before:rounded-full before:bg-brand-500'
                                    : 'text-ink-700 hover:bg-surface-100 hover:text-ink-900 font-medium'
                            }`}
                        >
                            <item.icon aria-hidden="true" className={`h-4 w-4 shrink-0 ${item.current ? 'text-brand-500' : 'text-ink-500 group-hover:text-ink-700'}`} />
                            <span className="flex-1">
                                {item.name === 'Counter Pickup'
                                    ? 'Counter pickup'
                                    : item.name === 'Fleet Management'
                                    ? 'Fleet & drivers'
                                    : item.name === 'Facility Network'
                                    ? 'Hub network'
                                    : item.name}
                            </span>
                            {item.badge && (
                                <span className="rounded-chip bg-surface-100 px-1.5 py-0.5 text-xs font-medium text-ink-600">
                                    {item.badge}
                                </span>
                            )}
                        </PortalLink>
                    ))}
                </div>
            </section>
        );
    })}</div>;
}
