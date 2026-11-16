import PortalLink from '@/Components/PortalLink';

export type AdminTab = { label: string; href: string; components: string[] };

/** Admin areas that used to be many sidebar links: one sidebar entry, then tabs across the top of each page. */
export function adminAreaTabs(component: string, options: { returnLiabilities: boolean; financialReturn: boolean }): { label: string; tabs: (AdminTab & { current: boolean })[] } | null {
    const finance: AdminTab[] = [
        { label: 'Overview', href: '/financial-oversight', components: ['Finance/Overview', 'Finance/Evidence'] },
        { label: 'COD cash', href: '/admin/cod', components: ['Finance/Cod', 'Finance/CodDetail'] },
        { label: 'Seller payouts', href: '/seller-settlements', components: ['Finance/Settlements', 'Finance/SettlementDetail'] },
        { label: 'Earnings', href: '/delivery-earnings', components: ['Finance/DeliveryEarnings'] },
        { label: 'Wallet credits', href: '/wallet/admin', components: ['Admin/WalletCredits'] },
        { label: 'Wallet sales', href: '/wallet/proceeds', components: ['Finance/WalletProceeds'] },
        ...(options.returnLiabilities ? [{ label: 'Return liabilities', href: '/return-liabilities', components: [] }] : []),
    ];
    const logistics: AdminTab[] = [
        { label: 'Oversight', href: '/admin/logistics', components: ['Admin/Logistics'] },
        { label: 'Companies & hubs', href: '/hub/company-setup', components: ['Hub/CompanySetup', 'Hub/CompanyDirectory'] },
    ];
    const mark = (tabs: AdminTab[]) => tabs.map(tab => ({ ...tab, current: tab.components.some(name => component === name || component.startsWith(`${name}/`))
        || (tab.label === 'Return liabilities' && options.financialReturn) }));
    if (finance.some(tab => tab.components.some(name => component === name)) || options.financialReturn) return { label: 'Finance', tabs: mark(finance) };
    if (component.startsWith('Admin/Logistics') || component === 'Hub/CompanySetup' || component === 'Hub/CompanyDirectory') return { label: 'Logistics', tabs: mark(logistics) };
    return null;
}

export default function AdminSectionTabs({ label, tabs }: { label: string; tabs: (AdminTab & { current: boolean })[] }) {
    return <nav aria-label={`${label} pages`} className="-mx-4 overflow-x-auto border-b border-line-200 px-4 sm:mx-0 sm:px-0">
        <ul className="flex min-w-max gap-1">
            {tabs.map(tab => <li key={tab.href}>
                <PortalLink href={tab.href} aria-current={tab.current ? 'page' : undefined}
                    className={`-mb-px inline-flex h-11 items-center border-b-2 px-3 text-sm font-semibold ${tab.current ? 'border-brand-500 text-ink-900' : 'border-transparent text-ink-600 hover:border-line-300 hover:text-ink-900'}`}>
                    {tab.label}
                </PortalLink>
            </li>)}
        </ul>
    </nav>;
}
